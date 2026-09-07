<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * Verifies that a request really came from Discord.
 *
 * Discord signs every interaction with Ed25519 over
 * `timestamp + raw body`, and the application's public key verifies it.
 * There is no shared secret and no session: **the signature is the
 * authentication**, which is why the endpoint carrying it is the panel's
 * only route without a login.
 *
 * Two things Discord enforces, both of which make this non-optional:
 * it refuses to register an endpoint whose signature check does not
 * work, and it re-checks periodically afterwards — an endpoint that
 * starts accepting bad signatures is disabled.
 *
 * `ext-sodium` is a hard requirement of this project already, so
 * Ed25519 costs no new dependency.
 */
final readonly class InteractionSignature
{
    /**
     * Discord's timestamps are seconds; a wider window than this would
     * let a captured request be replayed long after the fact.
     */
    private const MAX_AGE_SECONDS = 300;

    public function __construct(private string $publicKey)
    {
    }

    /**
     * @param string $signature the `X-Signature-Ed25519` header, hex
     * @param string $timestamp the `X-Signature-Timestamp` header
     * @param string $body      the raw request body, byte for byte
     */
    public function verify(string $signature, string $timestamp, string $body, ?int $now = null): bool
    {
        if ($this->publicKey === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        // A replay of a genuine, correctly signed request is still not
        // something to act on twice.
        if (!$this->isRecent($timestamp, $now ?? time())) {
            return false;
        }

        $key = self::fromHex($this->publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $raw = self::fromHex($signature, SODIUM_CRYPTO_SIGN_BYTES);

        if ($key === null || $raw === null) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($raw, $timestamp.$body, $key);
        } catch (\SodiumException) {
            // Malformed input reaches here as an exception rather than
            // as false, and a request we cannot check is not verified.
            return false;
        }
    }

    private function isRecent(string $timestamp, int $now): bool
    {
        if (preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        return abs($now - (int) $timestamp) <= self::MAX_AGE_SECONDS;
    }

    /** Hex of exactly the expected length, or nothing. */
    private static function fromHex(string $value, int $bytes): ?string
    {
        if (strlen($value) !== $bytes * 2 || preg_match('/^[0-9a-f]+$/i', $value) !== 1) {
            return null;
        }

        $decoded = @hex2bin($value);

        return $decoded === false ? null : $decoded;
    }
}
