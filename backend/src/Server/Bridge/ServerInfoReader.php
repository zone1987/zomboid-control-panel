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
     * Vehicles the server currently has loaded.
     *
     * Only loaded ones: a vehicle in an unloaded chunk is not in the
     * cell, so an empty list means nobody is near one rather than that
     * the world has none.
     *
     * Paint, wear and facing come from bridge 0.12.0 and are null on
     * an older one, which is why every one of them is optional.
     *
     * @return list<array{
     *     id: int, script: string, x: int, y: int, z: int,
     *     fuel: float|null, engineRunning: bool, angle: float|null,
     *     hue: float|null, saturation: float|null, value: float|null,
     *     rust: float|null, skin: int|null
     * }>|null
     */
    public function vehicles(GameServer $server): ?array
    {
        $payload = $this->read($server, BridgeFiles::VEHICLES);

        if ($payload === null || !\is_array($payload['vehicles'] ?? null)) {
            return null;
        }

        $vehicles = [];

        foreach ($payload['vehicles'] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $vehicles[] = [
                'id' => (int) ($entry['id'] ?? 0),
                'script' => (string) ($entry['script'] ?? ''),
                'x' => (int) ($entry['x'] ?? 0),
                'y' => (int) ($entry['y'] ?? 0),
                'z' => (int) ($entry['z'] ?? 0),
                'fuel' => self::decimal($entry['fuel'] ?? null),
                'engineRunning' => ($entry['engineRunning'] ?? false) === true,
                'angle' => self::decimal($entry['angle'] ?? null),
                'hue' => self::decimal($entry['hue'] ?? null),
                'saturation' => self::decimal($entry['saturation'] ?? null),
                'value' => self::decimal($entry['value'] ?? null),
                'rust' => self::decimal($entry['rust'] ?? null),
                'skin' => isset($entry['skin']) && is_numeric($entry['skin'])
                    ? (int) $entry['skin']
                    : null,
            ];
        }

        return $vehicles;
    }

    /** Null rather than zero, so a missing value is not a real reading. */
    private static function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<array{name: string, owner: string, tag: string, members: list<string>}>|null
     */
    public function factions(GameServer $server): ?array
    {
        $payload = $this->read($server, BridgeFiles::FACTIONS);

        if ($payload === null || !\is_array($payload['factions'] ?? null)) {
            return null;
        }

        $factions = [];

        foreach ($payload['factions'] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $factions[] = [
                'name' => (string) ($entry['name'] ?? ''),
                'owner' => (string) ($entry['owner'] ?? ''),
                'tag' => (string) ($entry['tag'] ?? ''),
                'members' => array_values(array_filter(
                    (array) ($entry['members'] ?? []),
                    \is_string(...),
                )),
            ];
        }

        return $factions;
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
