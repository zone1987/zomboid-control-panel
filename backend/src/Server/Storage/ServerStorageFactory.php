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
 */
final readonly class ServerStorageFactory
{
    private const TIMEOUT_SECONDS = 10;

    public function create(FtpConfig $config): Filesystem
    {
        return new Filesystem(
            $config->getProtocol() === FtpConfig::PROTOCOL_SFTP
                ? $this->sftpAdapter($config)
                : $this->ftpAdapter($config),
        );
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
