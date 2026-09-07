<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSetting;
use App\Server\Discord\InteractionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * The endpoint Discord posts every interaction to.
 *
 * **This test is not optional.** Discord refuses to register an endpoint
 * whose signature check does not work, re-checks it afterwards, and
 * disables one that starts accepting bad signatures — so a regression
 * here does not degrade the bot, it switches it off.
 */
final class DiscordInteractionTest extends FunctionalTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $secretKey;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clearDatabase();

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);

        $key = new AppSetting(AppSetting::DISCORD_PUBLIC_KEY);
        $key->setValue(bin2hex(sodium_crypto_sign_publickey($pair)));

        $this->em->persist($key);
        $this->em->flush();
    }

    /**
     * Discord sends this when the URL is saved, and will not accept the
     * URL unless it comes back as a Pong.
     */
    public function testAnswersDiscordsPingWithAPong(): void
    {
        $this->post(['type' => InteractionType::PING]);

        self::assertResponseIsSuccessful();
        self::assertSame(InteractionType::PONG, $this->body()['type']);
    }

    /** No session, no login — and it still must not be a redirect. */
    public function testTheEndpointIsReachableWithoutSigningIn(): void
    {
        $this->post(['type' => InteractionType::PING]);

        self::assertResponseIsSuccessful();
    }

    public function testRejectsAnUnsignedRequest(): void
    {
        $this->client->request(
            'POST',
            '/api/discord/interactions',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['type' => InteractionType::PING], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** The case the signature exists for. */
    public function testRejectsABodyChangedAfterSigning(): void
    {
        $timestamp = (string) time();
        $signed = json_encode(['type' => InteractionType::PING], JSON_THROW_ON_ERROR);

        $this->client->request(
            'POST',
            '/api/discord/interactions',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE_ED25519' => $this->sign($timestamp, $signed),
                'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
            ],
            content: json_encode(['type' => InteractionType::APPLICATION_COMMAND], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRejectsASignatureFromAnotherApplication(): void
    {
        $other = sodium_crypto_sign_keypair();
        $timestamp = (string) time();
        $body = json_encode(['type' => InteractionType::PING], JSON_THROW_ON_ERROR);

        $this->client->request(
            'POST',
            '/api/discord/interactions',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE_ED25519' => bin2hex(sodium_crypto_sign_detached(
                    $timestamp.$body,
                    sodium_crypto_sign_secretkey($other),
                )),
                'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
            ],
            content: $body,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * With no key stored there is nothing to verify against, so nothing
     * is accepted — an endpoint that trusts everything when
     * unconfigured is worse than one that trusts nothing.
     */
    public function testRejectsEverythingWhenNoPublicKeyIsStored(): void
    {
        $stored = $this->em->find(AppSetting::class, AppSetting::DISCORD_PUBLIC_KEY);

        self::assertNotNull($stored);

        $this->em->remove($stored);
        $this->em->flush();

        $this->post(['type' => InteractionType::PING]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** A correctly signed body that is not JSON must not be a 500. */
    public function testAMalformedBodyIsABadRequestRatherThanACrash(): void
    {
        $timestamp = (string) time();
        $body = 'not json at all';

        $this->client->request(
            'POST',
            '/api/discord/interactions',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE_ED25519' => $this->sign($timestamp, $body),
                'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
            ],
            content: $body,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    /** A genuine request captured and replayed hours later. */
    public function testRejectsAReplayedRequest(): void
    {
        $timestamp = (string) (time() - 7200);
        $body = json_encode(['type' => InteractionType::PING], JSON_THROW_ON_ERROR);

        $this->client->request(
            'POST',
            '/api/discord/interactions',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE_ED25519' => $this->sign($timestamp, $body),
                'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
            ],
            content: $body,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** A signed command is accepted; what it does comes next. */
    public function testASignedCommandIsAccepted(): void
    {
        $this->post([
            'type' => InteractionType::APPLICATION_COMMAND,
            'data' => ['name' => 'server', 'options' => [['name' => 'status', 'type' => 1]]],
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('type', $this->body());
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $this->client->request(
            'POST',
            '/api/discord/interactions',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE_ED25519' => $this->sign($timestamp, $body),
                'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
            ],
            content: $body,
        );
    }

    private function sign(string $timestamp, string $body): string
    {
        return bin2hex(sodium_crypto_sign_detached($timestamp.$body, $this->secretKey));
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }
}
