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

    public function version(): string
    {
        return preg_match('/BRIDGE_VERSION\s*=\s*"([^"]+)"/', $this->source(), $matches) === 1
            ? $matches[1]
            : 'unknown';
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
