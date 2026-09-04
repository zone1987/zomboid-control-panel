<?php

declare(strict_types=1);

namespace App\Server\Bridge;

use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * The slow-moving state the bridge writes: in-game time, weather and
 * claimed safehouses. Kept apart from the player roster, which changes
 * whenever somebody joins.
 */
final readonly class ServerInfoReader
{
    public function __construct(private FileBrowserInterface $files)
    {
    }

    /**
     * @return array{
     *     generatedAt: int|null,
     *     gameTime: array<string, int>|null,
     *     weather: array<string, mixed>|null,
     *     maxPlayers: int|null
     * }|null
     */
    public function serverInfo(GameServer $server): ?array
    {
        $payload = $this->read($server, BridgeFiles::SERVER);

        if ($payload === null) {
            return null;
        }

        return [
            'generatedAt' => isset($payload['generatedAt']) ? (int) $payload['generatedAt'] : null,
            'gameTime' => \is_array($payload['gameTime'] ?? null) ? $payload['gameTime'] : null,
            'weather' => \is_array($payload['weather'] ?? null) ? $payload['weather'] : null,
            'maxPlayers' => isset($payload['maxPlayers']) ? (int) $payload['maxPlayers'] : null,
        ];
    }

    /**
     * @return list<array{title: string, owner: string, x: int, y: int, w: int, h: int, members: list<string>}>|null
     */
    public function safehouses(GameServer $server): ?array
    {
        $payload = $this->read($server, BridgeFiles::SAFEHOUSES);

        if ($payload === null || !\is_array($payload['safehouses'] ?? null)) {
            return null;
        }

        $houses = [];

        foreach ($payload['safehouses'] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $houses[] = [
                'title' => (string) ($entry['title'] ?? ''),
                'owner' => (string) ($entry['owner'] ?? ''),
                'x' => (int) ($entry['x'] ?? 0),
                'y' => (int) ($entry['y'] ?? 0),
                'w' => (int) ($entry['w'] ?? 0),
                'h' => (int) ($entry['h'] ?? 0),
                'members' => array_values(array_filter(
                    (array) ($entry['members'] ?? []),
                    \is_string(...),
                )),
            ];
        }

        return $houses;
    }

    /**
     * Null rather than an exception: these files only exist from bridge
     * 0.4.0, and a server still on an older one is not an error.
     *
     * @return array<string, mixed>|null
     */
    private function read(GameServer $server, string $path): ?array
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return null;
        }

        try {
            $payload = json_decode(trim($this->files->readTail($config, $path)), true);
        } catch (StorageException) {
            return null;
        }

        return \is_array($payload) ? $payload : null;
    }
}
