<?php

declare(strict_types=1);

namespace App\Server\Logs;

use App\Entity\FtpConfig;
use App\Server\Storage\StorageException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

final class SftpChunkReader implements ChunkReader
{
    private ?SFTP $sftp = null;

    public function __construct(
        private readonly FtpConfig $config,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function size(string $path): int
    {
        $size = $this->connection()->filesize($path);

        if ($size === false) {
            throw new StorageException('errors.fileNotFound', sprintf('No file at "%s".', $path));
        }

        return $size;
    }

    public function read(string $path, int $offset, int $length): string
    {
        $chunk = $this->connection()->get($path, false, $offset, $length);

        if (!\is_string($chunk)) {
            throw new StorageException('errors.readFailed', sprintf('Could not read "%s".', $path));
        }

        return $chunk;
    }

    public function close(): void
    {
        $this->sftp?->disconnect();
        $this->sftp = null;
    }

    private function connection(): SFTP
    {
        if ($this->sftp instanceof SFTP) {
            return $this->sftp;
        }

        $sftp = new SFTP($this->config->getHost(), $this->config->getPort(), $this->timeoutSeconds);
        $privateKey = $this->config->getPrivateKey();

        $credential = $privateKey === null
            ? $this->config->getPassword()
            : PublicKeyLoader::load($privateKey, $this->config->getPassword() ?? false);

        if ($credential === null) {
            throw new StorageException('errors.credentialsMissing', 'No password or private key is stored.');
        }

        if (!$sftp->login($this->config->getUsername(), $credential)) {
            throw new StorageException('errors.authenticationFailed', 'The server refused the credentials.');
        }

        return $this->sftp = $sftp;
    }
}
