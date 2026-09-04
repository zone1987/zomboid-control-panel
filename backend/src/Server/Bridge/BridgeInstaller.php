<?php

declare(strict_types=1);

namespace App\Server\Bridge;

use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Uploads the Lua bridge into the game server's media/lua/server directory.
 */
final readonly class BridgeInstaller
{
    public const FILENAME = 'ZomboidControlBridge.lua';

    public function __construct(
        private FileBrowserInterface $files,
        private string $bridgeSourcePath,
    ) {
    }

    /** The version this panel ships. */
    public function version(): string
    {
        return self::versionOf($this->source());
    }

    /**
     * What is on the server, and whether it matches what is shipped here.
     *
     * @return array{
     *     installed: bool,
     *     installedVersion: string|null,
     *     availableVersion: string,
     *     upToDate: bool,
     *     path: string|null,
     *     error: string|null
     * }
     */
    public function status(GameServer $server): array
    {
        $available = $this->version();
        $config = $server->getFtpConfig();
        $path = $config?->getLuaServerPath();

        if ($config === null || $path === null || trim($path) === '') {
            return $this->unknown($available, 'servers.bridgeNeedsPath');
        }

        $target = rtrim($this->relativeTo($config->getBasePath(), $path), '/').'/'.self::FILENAME;

        try {
            if (!$this->files->fileExists($config, $target)) {
                return [
                    'installed' => false,
                    'installedVersion' => null,
                    'availableVersion' => $available,
                    'upToDate' => false,
                    'path' => $target,
                    'error' => null,
                ];
            }

            // readTail reads from the end, and the version is declared at
            // the top, so the whole file has to come across -- it is a few
            // kilobytes, which is cheaper than getting this wrong.
            $installed = self::versionOf($this->files->readTail($config, $target, 262144));
        } catch (StorageException $exception) {
            return $this->unknown($available, $exception->messageKey());
        }

        return [
            'installed' => true,
            'installedVersion' => $installed,
            'availableVersion' => $available,
            'upToDate' => $installed === $available,
            'path' => $target,
            'error' => null,
        ];
    }

    public static function versionOf(string $source): string
    {
        return preg_match('/BRIDGE_VERSION\s*=\s*"([^"]+)"/', $source, $matches) === 1
            ? $matches[1]
            : 'unknown';
    }

    /** @return array<string, mixed> */
    private function unknown(string $available, string $error): array
    {
        return [
            'installed' => false,
            'installedVersion' => null,
            'availableVersion' => $available,
            'upToDate' => false,
            'path' => null,
            'error' => $error,
        ];
    }

    /**
     * @throws BridgePathMissing when the server has no Lua path configured
     * @throws StorageException  when the upload itself fails
     */
    public function install(GameServer $server): string
    {
        $config = $server->getFtpConfig();
        $path = $config?->getLuaServerPath();

        if ($config === null || $path === null || trim($path) === '') {
            throw new BridgePathMissing();
        }

        // Dropped straight into the server's own media/lua/server, which
        // loads on start without a mod.info — that is only needed by mods
        // installed under mods/.
        $target = rtrim($this->relativeTo($config->getBasePath(), $path), '/').'/'.self::FILENAME;

        $this->files->upload($config, $target, $this->source());

        return $target;
    }

    /**
     * The browser works relative to the base path, so an absolute Lua path
     * has to be reduced to the part below it.
     */
    private function relativeTo(string $basePath, string $luaPath): string
    {
        $base = '/'.trim(str_replace('\\', '/', $basePath), '/');
        $lua = '/'.trim(str_replace('\\', '/', $luaPath), '/');

        if ($base !== '/' && str_starts_with($lua, $base.'/')) {
            return substr($lua, \strlen($base) + 1);
        }

        return ltrim($lua, '/');
    }

    private function source(): string
    {
        $contents = @file_get_contents($this->bridgeSourcePath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Bridge source is missing at "%s".', $this->bridgeSourcePath));
        }

        return $contents;
    }
}
