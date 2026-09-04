<?php

declare(strict_types=1);

namespace App\Server\Bridge;

/**
 * What the bridge answered.
 *
 * A handler confirms its work by reading the value back where that is
 * safe, so "ok" means the server checked rather than that the call did
 * not throw.
 */
final readonly class BridgeResult
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public bool $ok,
        public string $message,
        public ?array $data = null,
        public ?int $sequence = null,
    ) {
    }

    /** @throws BridgeCommandFailed when the answer cannot be read */
    public static function parse(string $raw): self
    {
        try {
            $payload = json_decode(trim($raw), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BridgeCommandFailed('The bridge answered with something unreadable.', 'bridge.badAnswer');
        }

        if (!\is_array($payload)) {
            throw new BridgeCommandFailed('The bridge answered with something unreadable.', 'bridge.badAnswer');
        }

        return new self(
            ok: ($payload['ok'] ?? false) === true,
            message: \is_string($payload['message'] ?? null) ? $payload['message'] : '',
            data: \is_array($payload['data'] ?? null) ? $payload['data'] : null,
            sequence: \is_int($payload['seq'] ?? null) ? $payload['seq'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
