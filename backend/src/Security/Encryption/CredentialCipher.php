<?php

declare(strict_types=1);

namespace App\Security\Encryption;

/**
 * Authenticated symmetric encryption for stored third-party credentials
 * (FTP/SFTP and RCON passwords) that must be recoverable in plaintext.
 */
final readonly class CredentialCipher
{
    private const CURRENT_VERSION = 'v1';

    private string $key;

    public function __construct(#[\SensitiveParameter] string $hexKey)
    {
        $key = \sodium_hex2bin($hexKey);

        if (\strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'Encryption key must be %d bytes (%d hex characters), got %d.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2,
                \strlen($key),
            ));
        }

        $this->key = $key;
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = \random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = \sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::CURRENT_VERSION.':'.\base64_encode($nonce.$cipher);
    }

    /**
     * @throws DecryptionFailed when the payload was truncated, tampered with,
     *                          or encrypted under a different key
     */
    public function decrypt(string $payload): string
    {
        $parts = \explode(':', $payload, 2);

        if (\count($parts) !== 2) {
            throw new DecryptionFailed('Payload is missing its version prefix.');
        }

        [$version, $encoded] = $parts;

        if ($version !== self::CURRENT_VERSION) {
            throw new DecryptionFailed(\sprintf('Unsupported payload version "%s".', $version));
        }

        $raw = \base64_decode($encoded, true);

        if ($raw === false || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new DecryptionFailed('Payload is not valid base64 or is too short.');
        }

        $nonce = \substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = \substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = \sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if ($plaintext === false) {
            throw new DecryptionFailed('Authentication failed; payload was tampered with or the key is wrong.');
        }

        return $plaintext;
    }
}
