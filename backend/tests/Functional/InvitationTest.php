<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invitation;
use App\Entity\User;
use App\Invitation\InvitationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InvitationTest extends WebTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\Invitation')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testOnlyAdministratorsMayInvite(): void
    {
        $this->createUser('server-admin@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('server-admin@example.com');

        $this->request('POST', '/api/invitations', ['email' => 'new@example.com']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCreatesAnInvitation(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/invitations', [
            'email' => 'New@Example.com',
            'roles' => [User::ROLE_SERVER_ADMIN],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        // Addresses are normalised, or the same person could be invited twice.
        self::assertSame('new@example.com', $this->payload()['email']);
    }

    public function testStoresOnlyTheTokenHash(): void
    {
        $this->signInAsAdmin();
        $this->request('POST', '/api/invitations', ['email' => 'new@example.com']);

        $stored = $this->em->getConnection()->fetchOne('SELECT token_hash FROM invitation LIMIT 1');

        // 64 hex characters is a SHA-256 digest, not a usable token.
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored);
    }

    public function testRefusesToInviteAnExistingAccount(): void
    {
        $this->signInAsAdmin();
        $this->createUser('taken@example.com', [User::ROLE_USER]);

        $this->request('POST', '/api/invitations', ['email' => 'taken@example.com']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('invitations.accountExists', $this->payload()['error']);
    }

    public function testRejectsAnInvalidAddress(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/invitations', ['email' => 'not-an-address']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRejectsAnUnknownRole(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/invitations', ['email' => 'new@example.com', 'roles' => ['ROLE_ROOT']]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testInvitingTwiceReplacesThePendingInvitation(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/invitations', ['email' => 'new@example.com']);
        $this->request('POST', '/api/invitations', ['email' => 'new@example.com']);

        $this->em->clear();

        // Only the newest link may work.
        self::assertCount(1, $this->em->getRepository(Invitation::class)->findBy(['email' => 'new@example.com']));
    }

    public function testAcceptingCreatesTheAccount(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.com', [User::ROLE_SERVER_ADMIN]);

        $this->request('POST', "/api/invitations/accept/$token", [
            'displayName' => 'New Person',
            'password' => 'another-long-enough-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);

        self::assertNotNull($user);
        self::assertContains(User::ROLE_SERVER_ADMIN, $user->getRoles());
    }

    public function testAnAcceptedInvitationCannotBeReused(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.com', [User::ROLE_USER]);

        $body = ['displayName' => 'New Person', 'password' => 'another-long-enough-password'];

        $this->request('POST', "/api/invitations/accept/$token", $body);
        $this->request('POST', "/api/invitations/accept/$token", $body);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->request('GET', '/api/invitations/accept/'.str_repeat('0', 64));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertFalse($this->payload()['valid']);
    }

    public function testAcceptingRejectsAShortPassword(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.com', [User::ROLE_USER]);

        $this->request('POST', "/api/invitations/accept/$token", [
            'displayName' => 'New Person',
            'password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Only the hash is stored, so the plaintext token cannot be read back.
     * The invitation is created through the real service, then its hash is
     * swapped for the hash of a token this test knows.
     *
     * @param list<string> $roles
     */
    private function inviteAndCaptureToken(string $email, array $roles): string
    {
        $admin = $this->createUser('admin@example.com', [User::ROLE_ADMIN]);
        $service = self::getContainer()->get(InvitationService::class);

        $service->invite($email, $roles, $admin);

        $hash = $this->em->getConnection()->fetchOne(
            'SELECT token_hash FROM invitation WHERE email = ?',
            [$email],
        );

        $known = str_repeat('ab', 32);
        $this->em->getConnection()->executeStatement(
            'UPDATE invitation SET token_hash = ? WHERE token_hash = ?',
            [hash('sha256', $known), $hash],
        );

        return $known;
    }

    private function signInAsAdmin(): void
    {
        $this->createUser('admin@example.com', [User::ROLE_ADMIN]);
        $this->signIn('admin@example.com');
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User($email, 'Test User');
        $user->setRoles($roles);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function signIn(string $email): void
    {
        $this->request('POST', '/api/login', ['email' => $email, 'password' => self::PASSWORD]);
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
