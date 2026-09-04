<?php

declare(strict_types=1);

namespace App\Server\Players;

use App\Entity\GameServer;
use App\Entity\PlayerSnapshot;
use App\Repository\PlayerSnapshotRepository;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the file the Lua bridge writes and stores what it says.
 *
 * The bridge is the only way to see game state: Lua on the server has no
 * network access, so it writes a file and this reads it back over SFTP.
 */
final readonly class BridgeStatusReader
{
    /** Relative to the transfer base path, matching what the bridge writes. */
    public const STATUS_PATH = 'Lua/ZomboidControl/status.json';

    /** Past this the file is no longer evidence of who is playing. */
    public const STALE_AFTER_SECONDS = 120;

    public function __construct(
        private FileBrowserInterface $files,
        private PlayerSnapshotRepository $snapshots,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
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

        try {
            $raw = $this->files->readTail($config, self::STATUS_PATH);
        } catch (StorageException $exception) {
            throw new BridgeUnavailable('bridge.fileMissing', $exception);
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

        return [
            'playerCount' => $stale ? 0 : \count($seen),
            'generatedAt' => $generatedAt,
            'bridgeVersion' => (string) ($payload['bridgeVersion'] ?? 'unknown'),
            'stale' => $stale,
        ];
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
