<?php

declare(strict_types=1);

namespace App\Server\Logs;

use App\Entity\FtpConfig;
use App\Server\Storage\StorageException;

/**
 * Plain FTP, addressed directly for the sake of REST -- the command that
 * resumes a transfer at a byte offset. Flysystem's adapter does not
 * expose it, and several game hosts offer nothing but FTP.
 */
final class FtpChunkReader implements ChunkReader
{
    /** @var \FTP\Connection|null */
    private $connection = null;

    public function __construct(
        private readonly FtpConfig $config,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function size(string $path): int
    {
        $size = ftp_size($this->connect(), $path);

        if ($size < 0) {
            throw new StorageException('errors.fileNotFound', sprintf('No file at "%s".', $path));
        }

        return $size;
    }

    public function read(string $path, int $offset, int $length): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new StorageException('errors.readFailed', 'Could not open a buffer.');
        }

        try {
            // REST: start the transfer at $offset rather than at zero.
            if (!@ftp_fget($this->connect(), $stream, $path, FTP_BINARY, $offset)) {
                throw new StorageException('errors.readFailed', sprintf('Could not read "%s".', $path));
            }

            // ftp_fget seeks the target stream to the resume offset before
            // writing, so the buffer holds $offset null bytes followed by
            // the data. Reading starts where the file data does.
            fseek($stream, $offset);
            $contents = stream_get_contents($stream, $length);

            return $contents === false ? '' : $contents;
        } finally {
            fclose($stream);
        }
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }

    /** @return \FTP\Connection */
    private function connect()
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $connection = @ftp_connect($this->config->getHost(), $this->config->getPort(), $this->timeoutSeconds);

        if ($connection === false) {
            throw new StorageException(
                'errors.connectionFailed',
                sprintf('Could not reach %s:%d.', $this->config->getHost(), $this->config->getPort()),
            );
        }

        if (!@ftp_login($connection, $this->config->getUsername(), $this->config->getPassword() ?? '')) {
            @ftp_close($connection);

            throw new StorageException('errors.authenticationFailed', 'The server refused the credentials.');
        }

        // Passive mode works behind NAT, which most game hosts are.
        @ftp_pasv($connection, true);

        return $this->connection = $connection;
    }
}
