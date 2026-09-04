<?php

declare(strict_types=1);

namespace App\Server\Rcon;

/**
 * Source RCON over TCP, spoken directly.
 *
 * Written rather than taken from a library because Zomboid splits long
 * replies -- "help" arrives as 4086 bytes followed by the rest -- and
 * sends the continuation unprompted. Libraries that ask for it with
 * SERVERDATA_REQUESTVALUE wait for an answer that never comes, and
 * libraries that only look for a split above 4000 bytes miss it entirely.
 */
final class RconTransport
{
    public const TYPE_RESPONSE = 0;
    public const TYPE_EXEC = 2;
    public const TYPE_AUTH = 3;

    /** Anything at or above this may be the first part of a longer reply. */
    private const SPLIT_THRESHOLD = 3500;

    /** How long to wait for a continuation that may never come. */
    private const CONTINUATION_TIMEOUT_MICROSECONDS = 400_000;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $deadlineSeconds = 12,
    ) {
    }

    /**
     * @throws RconUnreachable
     * @throws RconAuthenticationFailed
     */
    public function connect(string $host, int $port, string $password): void
    {
        $socket = @fsockopen($host, $port, $errno, $error, $this->timeoutSeconds);

        if ($socket === false) {
            throw new RconUnreachable(sprintf('Could not connect to %s:%d: %s', $host, $port, $error));
        }

        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeoutSeconds);

        $this->write(1, self::TYPE_AUTH, $password);

        $reply = $this->readPacket();

        // Some servers answer with an empty value packet before the
        // authentication result itself.
        if ($reply !== null && $reply['type'] === self::TYPE_RESPONSE && $reply['body'] === '') {
            $reply = $this->readPacket();
        }

        if ($reply === null) {
            throw new RconUnreachable('The server closed the connection during authentication.');
        }

        // The protocol signals a rejected password by answering with -1.
        if ($reply['id'] === -1) {
            throw new RconAuthenticationFailed('The RCON password was refused.');
        }
    }

    /** @throws RconUnreachable */
    public function send(string $command): string
    {
        $this->write(2, self::TYPE_EXEC, $command);

        $deadline = microtime(true) + $this->deadlineSeconds;
        $body = '';

        while (microtime(true) < $deadline) {
            $packet = $this->readPacket();

            if ($packet === null) {
                break;
            }

            $body .= $packet['body'];

            if (\strlen($packet['body']) < self::SPLIT_THRESHOLD) {
                break;
            }

            // A full-sized packet may be the last one anyway, so the wait
            // for a continuation is deliberately short.
            if (!$this->hasMoreWaiting()) {
                break;
            }
        }

        if ($body === '' && microtime(true) >= $deadline) {
            throw new RconUnreachable('The server took too long to answer.');
        }

        return rtrim($body, "\0\r\n ");
    }

    public function disconnect(): void
    {
        if (\is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    private function hasMoreWaiting(): bool
    {
        if (!\is_resource($this->socket)) {
            return false;
        }

        $read = [$this->socket];
        $write = null;
        $except = null;

        return stream_select($read, $write, $except, 0, self::CONTINUATION_TIMEOUT_MICROSECONDS) === 1;
    }

    private function write(int $id, int $type, string $body): void
    {
        if (!\is_resource($this->socket)) {
            throw new RconUnreachable('Not connected.');
        }

        $payload = pack('VV', $id, $type).$body."\x00\x00";

        if (fwrite($this->socket, pack('V', \strlen($payload)).$payload) === false) {
            throw new RconUnreachable('Could not write to the socket.');
        }
    }

    /** @return array{id: int, type: int, body: string}|null */
    private function readPacket(): ?array
    {
        if (!\is_resource($this->socket)) {
            return null;
        }

        $head = $this->readExactly(4);

        if ($head === null) {
            return null;
        }

        $size = unpack('V', $head)[1];

        // A packet carries at least an id and a type plus two terminators.
        if ($size < 10 || $size > 8192) {
            throw new RconUnreachable(sprintf('The server sent a packet of %d bytes, which is not RCON.', $size));
        }

        $data = $this->readExactly($size);

        if ($data === null) {
            return null;
        }

        return [
            // Signed: a refused password is reported as id -1, which an
            // unsigned read turns into 4294967295.
            'id' => unpack('l', substr($data, 0, 4))[1],
            'type' => unpack('l', substr($data, 4, 4))[1],
            'body' => substr($data, 8, -2),
        ];
    }

    private function readExactly(int $length): ?string
    {
        $data = '';

        while (\strlen($data) < $length) {
            $chunk = fread($this->socket, $length - \strlen($data));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $data .= $chunk;
        }

        return $data;
    }
}
