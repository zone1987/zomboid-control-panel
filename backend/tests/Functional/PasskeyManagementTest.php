<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyManagementTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\WebauthnCredential')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->owner = $this->createUser('owner@example.com');
    }

    public function testListsOnlyTheOwnPasskeys(): void
    {
        $this->addCredential($this->owner, 'MacBook', 'cred-owner');
        $this->addCredential($this->createUser('other@example.com'), 'Their Phone', 'cred-other');

        $this->signIn('owner@example.com');
        $this->request('GET', '/api/passkeys');

        self::assertResponseIsSuccessful();

        $items = $this->payload()['items'];

        self::assertCount(1, $items);
        self::assertSame('MacBook', $items[0]['name']);
    }

    public function testRenamesAPasskey(): void
    {
        $credential = $this->addCredential($this->owner, 'Old Name', 'cred-rename');

        $this->signIn('owner@example.com');
        $this->request('PATCH', '/api/passkeys/'.$credential->getId(), ['name' => 'Work Laptop']);

        self::assertResponseIsSuccessful();
        self::assertSame('Work Laptop', $this->payload()['name']);
    }

    public function testRejectsAnEmptyName(): void
    {
        $credential = $this->addCredential($this->owner, 'Old Name', 'cred-empty');

        $this->signIn('owner@example.com');
        $this->request('PATCH', '/api/passkeys/'.$credential->getId(), ['name' => '   ']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testDeletesAPasskey(): void
    {
        $credential = $this->addCredential($this->owner, 'MacBook', 'cred-delete');

        $this->signIn('owner@example.com');
        $this->request('DELETE', '/api/passkeys/'.$credential->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->em->clear();
        self::assertNull($this->em->getRepository(WebauthnCredential::class)->find($credential->getId()));
    }

    public function testRefusesToDeleteAnotherUsersPasskey(): void
    {
        $theirs = $this->addCredential($this->createUser('other@example.com'), 'Their Phone', 'cred-theirs');

        $this->signIn('owner@example.com');
        $this->request('DELETE', '/api/passkeys/'.$theirs->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(WebauthnCredential::class)->find($theirs->getId()));
    }

    public function testKeepsTheLastPasskeyWhenNoPasswordIsSet(): void
    {
        $passwordless = $this->createUser('passwordless@example.com', withPassword: false);
        $onlyKey = $this->addCredential($passwordless, 'Only Key', 'cred-only');

        $this->client->loginUser($passwordless);
        $this->request('DELETE', '/api/passkeys/'.$onlyKey->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('passkey.lastOneWithoutPassword', $this->payload()['error']);
    }

    public function testRequiresAuthentication(): void
    {
        $this->request('GET', '/api/passkeys');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function createUser(string $email, bool $withPassword = true): User
    {
        $user = new User($email, 'Test User');

        if ($withPassword) {
            $user->setPassword(
                self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
            );
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function addCredential(User $user, string $name, string $rawId): WebauthnCredential
    {
        $credential = new WebauthnCredential(
            $user,
            $name,
            $rawId,
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'public-key-bytes',
            $user->getId()->toRfc4122(),
            0,
        );

        $this->em->persist($credential);
        $this->em->flush();

        return $credential;
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
