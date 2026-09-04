<?php

declare(strict_types=1);

namespace App\Server\Storage;

use App\Entity\FtpConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

/**
 * Builds a filesystem for one server's transfer credentials.
 *
 * Connections are kept for the lifetime of the request: listing a
 * directory, checking a file and reading it are three operations that
 * would otherwise open three FTP sessions, and the handshake costs more
 * than every transfer put together.
 */
final class ServerStorageFactory
{
    private const TIMEOUT_SECONDS = 10;

    /** @var array<string, Filesystem> */
    private array $filesystems = [];

    public function create(FtpConfig $config): Filesystem
    {
        $key = $this->keyFor($config);

        return $this->filesystems[$key] ??= new Filesystem(
            $config->getProtocol() === FtpConfig::PROTOCOL_SFTP
                ? $this->sftpAdapter($config)
                : $this->ftpAdapter($config),
        );
    }

    /**
     * Everything that decides which machine and which directory a
     * filesystem talks to. Credentials are not part of it: two configs
     * that differ only by password would still be the same target, and
     * keeping secrets out of an array key is worth the edge case.
     */
    private function keyFor(FtpConfig $config): string
    {
        return implode('|', [
            $config->getProtocol(),
            $config->getHost(),
            (string) $config->getPort(),
            $config->getUsername(),
            $config->getBasePath(),
        ]);
    }

    private function sftpAdapter(FtpConfig $config): SftpAdapter
    {
        $privateKey = $config->getPrivateKey();

        return new SftpAdapter(
            new SftpConnectionProvider(
                host: $config->getHost(),
                username: $config->getUsername(),
                password: $privateKey === null ? $config->getPassword() : null,
                privateKey: $privateKey,
                passphrase: $privateKey === null ? null : $config->getPassword(),
                port: $config->getPort(),
                timeout: self::TIMEOUT_SECONDS,
            ),
            $config->getBasePath(),
        );
    }

    private function ftpAdapter(FtpConfig $config): FtpAdapter
    {
        return new FtpAdapter(
            FtpConnectionOptions::fromArray([
                'host' => $config->getHost(),
                'root' => $config->getBasePath(),
                'username' => $config->getUsername(),
                'password' => $config->getPassword() ?? '',
                'port' => $config->getPort(),
                'ssl' => false,
                'timeout' => self::TIMEOUT_SECONDS,
                // Passive mode works behind NAT, which most game hosts are.
                'passive' => true,
            ]),
        );
    }
}
