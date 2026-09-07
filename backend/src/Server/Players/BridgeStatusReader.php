<?php

declare(strict_types=1);

namespace App\Server\Players;

use App\Entity\GameServer;
use App\Entity\PlayerSnapshot;
use App\Server\Bridge\BridgeFiles;
use App\Repository\PlayerSnapshotRepository;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the file the Lua bridge writes and stores what it says.
 *
 * The bridge is the only way to see game state: Lua on the server has no
 * network access, so it writes a file and this reads it back over SFTP.
 */
final readonly class BridgeStatusReader
{

    /** Past this the file is no longer evidence of who is playing. */
    public const STALE_AFTER_SECONDS = 120;

    /**
     * The shortest gap between two transfers. Several browser tabs, or
     * several people watching the same server, would otherwise each open
     * their own FTP connection every few seconds.
     */
    private const MIN_SECONDS_BETWEEN_READS = 2;

    public function __construct(
        private FileBrowserInterface $files,
        private PlayerSnapshotRepository $snapshots,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @throws BridgeUnavailable when the file cannot be read or parsed
     *
     * @return array{playerCount: int, generatedAt: \DateTimeImmutable, bridgeVersion: string, stale: bool}
     */
    public function refresh(GameServer $server): array
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            throw new BridgeUnavailable('bridge.noTransferCredentials');
        }

        $recent = $this->cache->getItem('bridge.read.'.$server->getId()->toRfc4122());

        // Answering from the last read costs a database query instead of a
        // file transfer, and the data is at most two seconds old either way.
        if ($recent->isHit() && \is_array($recent->get())) {
            $cached = $recent->get();

            return [
                'playerCount' => $cached['playerCount'],
                'generatedAt' => new \DateTimeImmutable('@'.$cached['generatedAt']),
                'bridgeVersion' => $cached['bridgeVersion'],
                'stale' => time() - $cached['generatedAt'] > self::STALE_AFTER_SECONDS,
            ];
        }

        // A bridge from before 0.4.0 wrote a single status.json; reading
        // that as a fallback keeps an un-updated server working.
        $raw = null;

        foreach ([BridgeFiles::PLAYERS, BridgeFiles::LEGACY] as $candidate) {
            try {
                $raw = $this->files->readTail($config, $candidate);

                break;
            } catch (StorageException $exception) {
                $lastFailure = $exception;
            }
        }

        if ($raw === null) {
            throw new BridgeUnavailable('bridge.fileMissing', $lastFailure ?? null);
        }

        $payload = json_decode(trim($raw), true);

        if (!\is_array($payload) || !isset($payload['players']) || !\is_array($payload['players'])) {
            throw new BridgeUnavailable('bridge.fileUnreadable');
        }

        $generatedAt = (new \DateTimeImmutable())->setTimestamp((int) ($payload['generatedAt'] ?? time()));

        // The bridge writes every few seconds; anything older means it
        // stopped, or the server is down.
        $stale = time() - $generatedAt->getTimestamp() > self::STALE_AFTER_SECONDS;

        $seen = [];

        foreach ($payload['players'] as $entry) {
            if (!\is_array($entry) || !isset($entry['username']) || !\is_string($entry['username'])) {
                continue;
            }

            $this->store($server, $entry, $generatedAt, online: !$stale);
            $seen[] = $entry['username'];
        }

        // A stale file proves nothing about who is playing now, so nobody
        // is left marked online on the strength of it. Showing someone as
        // online when the server is down is worse than showing nobody.
        $this->snapshots->markEveryoneElseOffline($server, $stale ? [] : $seen);
        $this->entityManager->flush();

        $result = [
            'playerCount' => $stale ? 0 : \count($seen),
            'generatedAt' => $generatedAt,
            'bridgeVersion' => (string) ($payload['bridgeVersion'] ?? 'unknown'),
            'stale' => $stale,
        ];

        $recent
            ->set([
                'playerCount' => $result['playerCount'],
                'generatedAt' => $generatedAt->getTimestamp(),
                'bridgeVersion' => $result['bridgeVersion'],
            ])
            ->expiresAfter(self::MIN_SECONDS_BETWEEN_READS);
        $this->cache->save($recent);

        return $result;
    }

    /** @param array<string, mixed> $entry */
    private function store(GameServer $server, array $entry, \DateTimeImmutable $seenAt, bool $online): void
    {
        $username = (string) $entry['username'];
        $snapshot = $this->snapshots->findOneForServer($server, $username);

        if (!$snapshot instanceof PlayerSnapshot) {
            $snapshot = new PlayerSnapshot($server, $username);
            $this->entityManager->persist($snapshot);
        }

        $snapshot->update(
            $this->stringOrNull($entry['steamId'] ?? null),
            (float) ($entry['x'] ?? 0),
            (float) ($entry['y'] ?? 0),
            (float) ($entry['z'] ?? 0),
            (float) ($entry['health'] ?? 1),
            (bool) ($entry['infected'] ?? false),
            (float) ($entry['infectionLevel'] ?? 0),
            (float) ($entry['hoursSurvived'] ?? 0),
            $this->stringOrNull($entry['accessLevel'] ?? null),
            $this->skills($entry['skills'] ?? null),
            $this->traits($entry['traits'] ?? null),
            $seenAt,
            $online,
        );

        $snapshot->recordKills(
            $this->intOrNull($entry['zombieKills'] ?? null),
            $this->intOrNull($entry['survivorKills'] ?? null),
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<string, int> */
    private function skills(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $skills = [];

        foreach ($value as $name => $level) {
            if (\is_string($name) && is_numeric($level)) {
                $skills[$name] = (int) $level;
            }
        }

        return $skills;
    }

    /** @return list<string> */
    private function traits(mixed $value): array
    {
        return \is_array($value)
            ? array_values(array_filter($value, static fn ($t): bool => \is_string($t)))
            : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
