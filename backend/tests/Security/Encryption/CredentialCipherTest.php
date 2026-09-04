<?php

declare(strict_types=1);

namespace App\Tests\Security\Encryption;

use App\Security\Encryption\CredentialCipher;
use App\Security\Encryption\DecryptionFailed;
use PHPUnit\Framework\TestCase;

final class CredentialCipherTest extends TestCase
{
    private const KEY = '74aefa659d5571cdcf611b05ef899733562a5070c62d2f80ee0668a2fa3a6736';
    private const OTHER_KEY = '1111111111111111111111111111111111111111111111111111111111111111';

    public function testRoundTripsAPassword(): void
    {
        $cipher = new CredentialCipher(self::KEY);

        self::assertSame('hunter2', $cipher->decrypt($cipher->encrypt('hunter2')));
    }

    public function testRoundTripsAnEmptyString(): void
    {
        $cipher = new CredentialCipher(self::KEY);

        self::assertSame('', $cipher->decrypt($cipher->encrypt('')));
    }

    public function testRoundTripsMultibyteCharacters(): void
    {
        $cipher = new CredentialCipher(self::KEY);
        $secret = 'Paßwort-mit-Ümläüten-🔐';

        self::assertSame($secret, $cipher->decrypt($cipher->encrypt($secret)));
    }

    public function testEncryptingTheSameValueTwiceYieldsDifferentCiphertexts(): void
    {
        $cipher = new CredentialCipher(self::KEY);

        self::assertNotSame($cipher->encrypt('same'), $cipher->encrypt('same'));
    }

    public function testCiphertextCarriesAVersionPrefix(): void
    {
        $cipher = new CredentialCipher(self::KEY);

        self::assertStringStartsWith('v1:', $cipher->encrypt('secret'));
    }

    public function testRejectsPayloadEncryptedUnderADifferentKey(): void
    {
        $payload = (new CredentialCipher(self::KEY))->encrypt('secret');

        $this->expectException(DecryptionFailed::class);
        (new CredentialCipher(self::OTHER_KEY))->decrypt($payload);
    }

    public function testRejectsTamperedCiphertext(): void
    {
        $cipher = new CredentialCipher(self::KEY);
        $payload = $cipher->encrypt('secret');

        $raw = base64_decode(substr($payload, 3), true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\x00" ? "\x01" : "\x00";

        $this->expectException(DecryptionFailed::class);
        $cipher->decrypt('v1:'.base64_encode($raw));
    }

    public function testRejectsPayloadWithoutVersionPrefix(): void
    {
        $this->expectException(DecryptionFailed::class);
        (new CredentialCipher(self::KEY))->decrypt('bm9wZQ==');
    }

    public function testRejectsUnknownVersionPrefix(): void
    {
        $this->expectException(DecryptionFailed::class);
        (new CredentialCipher(self::KEY))->decrypt('v99:bm9wZQ==');
    }

    public function testRejectsTruncatedPayload(): void
    {
        $this->expectException(DecryptionFailed::class);
        (new CredentialCipher(self::KEY))->decrypt('v1:'.base64_encode('short'));
    }

    public function testRejectsKeyOfWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CredentialCipher('abcd');
    }
}
