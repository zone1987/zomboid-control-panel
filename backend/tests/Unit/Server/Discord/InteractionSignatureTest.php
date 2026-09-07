<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Discord;

use App\Server\Discord\InteractionSignature;
use PHPUnit\Framework\TestCase;

/**
 * The signature is the authentication, so this test is not optional.
 *
 * Discord refuses to register an endpoint whose check does not work,
 * and disables one that later starts accepting bad signatures. Signed
 * with real Ed25519 keys rather than fixtures, so the test proves the
 * verification rather than a string comparison.
 */
final class InteractionSignatureTest extends TestCase
{
    private string $publicKey;
    private string $secretKey;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();

        $this->publicKey = bin2hex(sodium_crypto_sign_publickey($pair));
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
    }

    public function testAcceptsARequestDiscordSigned(): void
    {
        $body = '{"type":1}';
        $timestamp = (string) time();

        self::assertTrue(
            $this->verifier()->verify($this->sign($timestamp, $body), $timestamp, $body),
        );
    }

    /** The one case that matters: a body somebody changed in transit. */
    public function testRejectsATamperedBody(): void
    {
        $timestamp = (string) time();
        $signature = $this->sign($timestamp, '{"type":1}');

        self::assertFalse(
            $this->verifier()->verify($signature, $timestamp, '{"type":2}'),
        );
    }

    public function testRejectsATamperedTimestamp(): void
    {
        $body = '{"type":1}';
        $now = time();
        $signature = $this->sign((string) $now, $body);

        self::assertFalse($this->verifier()->verify($signature, (string) ($now + 1), $body));
    }

    /** A genuine request captured and sent again hours later. */
    public function testRejectsAReplayOfAnOldRequest(): void
    {
        $body = '{"type":1}';
        $timestamp = (string) (time() - 3600);

        self::assertFalse(
            $this->verifier()->verify($this->sign($timestamp, $body), $timestamp, $body),
        );
    }

    public function testRejectsASignatureFromAnotherKey(): void
    {
        $other = sodium_crypto_sign_keypair();
        $body = '{"type":1}';
        $timestamp = (string) time();

        $signature = bin2hex(
            sodium_crypto_sign_detached($timestamp.$body, sodium_crypto_sign_secretkey($other)),
        );

        self::assertFalse($this->verifier()->verify($signature, $timestamp, $body));
    }

    /**
     * Malformed input must answer false rather than throw: an exception
     * escaping here would be a 500, and Discord reads that as an
     * endpoint to disable.
     */
    public function testRejectsMalformedInputWithoutThrowing(): void
    {
        $timestamp = (string) time();

        foreach (['', 'not-hex', 'ab', str_repeat('zz', 64)] as $signature) {
            self::assertFalse(
                $this->verifier()->verify($signature, $timestamp, '{}'),
                sprintf('"%s" must be refused', $signature),
            );
        }

        foreach (['', 'yesterday', '-1'] as $stamp) {
            self::assertFalse($this->verifier()->verify($this->sign('1', '{}'), $stamp, '{}'));
        }
    }

    /** No key configured means nothing can be verified, so nothing is. */
    public function testRejectsEverythingWhenNoKeyIsConfigured(): void
    {
        $timestamp = (string) time();
        $body = '{"type":1}';

        self::assertFalse(
            (new InteractionSignature(''))->verify($this->sign($timestamp, $body), $timestamp, $body),
        );
    }

    /** The body is verified byte for byte, whitespace included. */
    public function testTheBodyIsCheckedByteForByte(): void
    {
        $timestamp = (string) time();
        $signature = $this->sign($timestamp, '{"type":1}');

        self::assertFalse($this->verifier()->verify($signature, $timestamp, '{"type": 1}'));
    }

    private function verifier(): InteractionSignature
    {
        return new InteractionSignature($this->publicKey);
    }

    private function sign(string $timestamp, string $body): string
    {
        return bin2hex(sodium_crypto_sign_detached($timestamp.$body, $this->secretKey));
    }
}
