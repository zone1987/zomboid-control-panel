<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Security\OAuth\IdentityAlreadyLinked;
use App\Security\OAuth\IdentityLinker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\TrustPath\EmptyTrustPath;

final class IdentityLinkingTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IdentityLinker $linker;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->linker = self::getContainer()->get(IdentityLinker::class);

        $this->em->createQuery('DELETE FROM App\Entity\OAuthIdentity')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\WebauthnCredential')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUnknownIdentityResolvesToNoUser(): void
    {
        self::assertNull($this->linker->findUser(OAuthIdentity::PROVIDER_STEAM, '76561198000000000'));
    }

    public function testLinksAnIdentityToAnAccount(): void
    {
        $user = $this->createUser('owner@example.com');

        $this->linker->link($user, OAuthIdentity::PROVIDER_STEAM, '76561198000000001', 'SomePlayer');

        self::assertSame(
            $user->getId()->toRfc4122(),
            $this->linker->findUser(OAuthIdentity::PROVIDER_STEAM, '76561198000000001')?->getId()->toRfc4122(),
        );
    }

    public function testLinkingTwiceToTheSameAccountIsIdempotent(): void
    {
        $user = $this->createUser('owner@example.com');

        $first = $this->linker->link($user, OAuthIdentity::PROVIDER_STEAM, '76561198000000002');
        $second = $this->linker->link($user, OAuthIdentity::PROVIDER_STEAM, '76561198000000002');

        self::assertSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
    }

    public function testRefusesToStealAnIdentityFromAnotherAccount(): void
    {
        $owner = $this->createUser('owner@example.com');
        $other = $this->createUser('other@example.com');

        $this->linker->link($owner, OAuthIdentity::PROVIDER_STEAM, '76561198000000003');

        $this->expectException(IdentityAlreadyLinked::class);
        $this->linker->link($other, OAuthIdentity::PROVIDER_STEAM, '76561198000000003');
    }

    public function testUnlinkIsAllowedWhileAPasswordRemains(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->linker->link($user, OAuthIdentity::PROVIDER_GOOGLE, 'google-1');

        self::assertTrue($this->linker->canUnlink($user, OAuthIdentity::PROVIDER_GOOGLE));
    }

    public function testUnlinkIsRefusedWhenItIsTheLastWayIn(): void
    {
        $user = $this->createUser('passwordless@example.com', withPassword: false);
        $this->linker->link($user, OAuthIdentity::PROVIDER_GOOGLE, 'google-2');

        self::assertFalse($this->linker->canUnlink($user, OAuthIdentity::PROVIDER_GOOGLE));
    }

    public function testUnlinkIsAllowedWhenAPasskeyRemains(): void
    {
        $user = $this->createUser('passwordless@example.com', withPassword: false);
        $this->linker->link($user, OAuthIdentity::PROVIDER_GOOGLE, 'google-3');
        $this->addCredential($user);

        $this->em->refresh($user);

        self::assertTrue($this->linker->canUnlink($user, OAuthIdentity::PROVIDER_GOOGLE));
    }

    public function testUnlinkIsAllowedWhenAnotherProviderRemains(): void
    {
        $user = $this->createUser('passwordless@example.com', withPassword: false);
        $this->linker->link($user, OAuthIdentity::PROVIDER_GOOGLE, 'google-4');
        $this->linker->link($user, OAuthIdentity::PROVIDER_STEAM, '76561198000000004');

        self::assertTrue($this->linker->canUnlink($user, OAuthIdentity::PROVIDER_GOOGLE));
    }

    public function testEndpointRefusesToRemoveTheLastWayIn(): void
    {
        $user = $this->createUser('passwordless@example.com', withPassword: false);
        $this->linker->link($user, OAuthIdentity::PROVIDER_GOOGLE, 'google-5');

        $this->client->loginUser($user);
        $this->request('DELETE', '/api/connect/google');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('auth.lastSignInMethod', $this->payload()['error']);
    }

    public function testEndpointRemovesALinkWhenOthersRemain(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->linker->link($user, OAuthIdentity::PROVIDER_GOOGLE, 'google-6');

        $this->signIn('owner@example.com');
        $this->request('DELETE', '/api/connect/google');

        self::assertResponseIsSuccessful();
        self::assertNull($this->linker->findUser(OAuthIdentity::PROVIDER_GOOGLE, 'google-6'));
    }

    public function testEndpointRejectsAnUnknownProvider(): void
    {
        $this->createUser('owner@example.com');
        $this->signIn('owner@example.com');

        $this->request('DELETE', '/api/connect/facebook');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testListsLinkedProviders(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->linker->link($user, OAuthIdentity::PROVIDER_STEAM, '76561198000000005', 'SomePlayer');

        $this->signIn('owner@example.com');
        $this->request('GET', '/api/connect');

        self::assertResponseIsSuccessful();

        $items = $this->payload()['items'];

        self::assertCount(1, $items);
        self::assertSame('steam', $items[0]['provider']);
        self::assertSame('SomePlayer', $items[0]['label']);
    }

    public function testSteamLoginRouteRedirectsToSteam(): void
    {
        $this->client->request('GET', '/api/connect/steam');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertStringStartsWith(
            'https://steamcommunity.com/openid/login',
            $this->client->getResponse()->headers->get('Location'),
        );
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

    private function addCredential(User $user): void
    {
        $credential = new \App\Entity\WebauthnCredential(
            $user,
            'Test Key',
            'cred-'.bin2hex(random_bytes(4)),
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
