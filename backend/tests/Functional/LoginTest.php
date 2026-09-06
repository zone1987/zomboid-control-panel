<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        $user = new User('admin@example.com', 'First Admin');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );

        $this->em->persist($user);
        $this->em->flush();
    }

    public function testSignsInWithCorrectCredentials(): void
    {
        $this->request('POST', '/api/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD]);

        self::assertResponseIsSuccessful();

        $payload = $this->payload();

        self::assertSame('authenticated', $payload['status']);
        self::assertTrue($payload['twoFactorComplete']);
        self::assertSame('admin@example.com', $payload['user']['email']);
        self::assertArrayNotHasKey('password', $payload['user']);
    }

    public function testRejectsAWrongPassword(): void
    {
        $this->request('POST', '/api/login', ['email' => 'admin@example.com', 'password' => 'wrong-password']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('auth.invalidCredentials', $this->payload()['error']);
    }

    public function testGivesTheSameAnswerForAnUnknownAccount(): void
    {
        $this->request('POST', '/api/login', ['email' => 'nobody@example.com', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        // Identical to a wrong password, so the endpoint cannot be used to
        // discover which addresses are registered.
        self::assertSame('auth.invalidCredentials', $this->payload()['error']);
    }

    public function testSessionIsAnonymousBeforeSigningIn(): void
    {
        $this->request('GET', '/api/session');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['authenticated']);
    }

    public function testSessionReportsTheUserAfterSigningIn(): void
    {
        $this->request('POST', '/api/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD]);
        $this->request('GET', '/api/session');

        self::assertResponseIsSuccessful();

        $payload = $this->payload();

        self::assertTrue($payload['authenticated']);
        self::assertSame('admin@example.com', $payload['user']['email']);
    }

    public function testProtectedEndpointRefusesAnonymousCallers(): void
    {
        $this->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('auth.required', $this->payload()['error']);
    }

    public function testLoginRecordsTheTimestamp(): void
    {
        $this->request('POST', '/api/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD]);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);

        self::assertNotNull($user->getLastLoginAt());
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
