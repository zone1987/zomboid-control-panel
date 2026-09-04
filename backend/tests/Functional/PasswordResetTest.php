<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\PasswordReset\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetTest extends WebTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\PasswordResetToken')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->createUser('user@example.com');
    }

    public function testAnswersTheSameForAnUnknownAddress(): void
    {
        $this->request('POST', '/api/password-reset', ['email' => 'nobody@example.com']);

        self::assertResponseIsSuccessful();
        // Identical to a known address: otherwise this endpoint would reveal
        // which addresses are registered.
        self::assertSame('sent', $this->payload()['status']);
    }

    public function testAnswersTheSameForAKnownAddress(): void
    {
        $this->request('POST', '/api/password-reset', ['email' => 'user@example.com']);

        self::assertResponseIsSuccessful();
        self::assertSame('sent', $this->payload()['status']);
    }

    public function testCreatesATokenOnlyForAKnownAddress(): void
    {
        $this->request('POST', '/api/password-reset', ['email' => 'nobody@example.com']);
        self::assertSame(0, $this->countTokens());

        $this->request('POST', '/api/password-reset', ['email' => 'user@example.com']);
        self::assertSame(1, $this->countTokens());
    }

    public function testStoresOnlyTheTokenHash(): void
    {
        $this->request('POST', '/api/password-reset', ['email' => 'user@example.com']);

        $stored = $this->em->getConnection()->fetchOne('SELECT token_hash FROM password_reset_token LIMIT 1');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored);
    }

    public function testRequestingTwiceInvalidatesTheOlderLink(): void
    {
        $this->request('POST', '/api/password-reset', ['email' => 'user@example.com']);
        $this->request('POST', '/api/password-reset', ['email' => 'user@example.com']);

        self::assertSame(1, $this->countTokens());
    }

    public function testResetsThePassword(): void
    {
        $token = $this->requestAndCaptureToken();

        $this->request('POST', "/api/password-reset/$token", ['password' => 'a-brand-new-password']);

        self::assertResponseIsSuccessful();

        // The new password must actually work.
        $this->request('POST', '/api/login', ['email' => 'user@example.com', 'password' => 'a-brand-new-password']);

        self::assertResponseIsSuccessful();
        self::assertSame('authenticated', $this->payload()['status']);
    }

    public function testATokenCannotBeUsedTwice(): void
    {
        $token = $this->requestAndCaptureToken();

        $this->request('POST', "/api/password-reset/$token", ['password' => 'a-brand-new-password']);
        $this->request('POST', "/api/password-reset/$token", ['password' => 'yet-another-password']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRejectsAShortPassword(): void
    {
        $token = $this->requestAndCaptureToken();

        $this->request('POST', "/api/password-reset/$token", ['password' => 'short']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->request('GET', '/api/password-reset/'.str_repeat('0', 64));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** Swaps the stored hash for one this test knows; see InvitationTest. */
    private function requestAndCaptureToken(): string
    {
        self::getContainer()->get(PasswordResetService::class)->request('user@example.com');

        $known = str_repeat('cd', 32);
        $this->em->getConnection()->executeStatement(
            'UPDATE password_reset_token SET token_hash = ?',
            [hash('sha256', $known)],
        );

        return $known;
    }

    private function countTokens(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM password_reset_token');
    }

    private function createUser(string $email): void
    {
        $user = new User($email, 'Test User');
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );

        $this->em->persist($user);
        $this->em->flush();
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
