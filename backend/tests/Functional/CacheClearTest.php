<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameServer;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use App\Server\Cache\CacheClearVerdict;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Settings -> Server -> Cache.
 *
 * It exists because a week-long cached failure left a production panel
 * showing English item names long after the cause was gone, and an
 * operator on Coolify has no console to clear a pool from.
 */
final class CacheClearTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private GameServer $server;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clearDatabase();

        $this->server = new GameServer('Cache test');
        $this->em->persist($this->server);
        $this->em->flush();
    }

    /** The response the button reads, warnings included -- CLAUDE.md 10h2. */
    public function testClearingAnswersWithWhatItCleared(): void
    {
        $this->signIn();

        $warnings = [];

        set_error_handler(
            static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            },
            E_WARNING | E_NOTICE,
        );

        try {
            $this->clear();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();

        $body = $this->body();

        self::assertSame('ok', $body['status']);
        self::assertSame(CacheClearVerdict::CLEARED, $body['state']);
        self::assertSame(1, $body['servers']);
        self::assertGreaterThan(1, $body['keys'], 'a server contributes several keys');
        self::assertNull($body['detail']);
    }

    /** The point of the button: a held verdict is gone afterwards. */
    public function testTheHeldItemTranslationIsGoneAfterwards(): void
    {
        $this->signIn();

        $cache = self::getContainer()->get(CacheItemPoolInterface::class);
        $key = sprintf('items.names.%s.DE', $this->server->getId()->toRfc4122());

        $entry = $cache->getItem($key);
        $entry->set('a stale failure')->expiresAfter(604800);
        $cache->save($entry);

        self::assertTrue($cache->getItem($key)->isHit(), 'the fixture did not take');

        $this->clear();

        self::assertResponseIsSuccessful();
        self::assertFalse($cache->getItem($key)->isHit(), 'the stale verdict survived the clear');
    }

    /**
     * A read position is not a cache: dropping it makes the chat mirror
     * skip whatever arrived in between rather than replay it.
     */
    public function testLeavesTheChatPositionAndTheCommandCounterAlone(): void
    {
        $this->signIn();

        $cache = self::getContainer()->get(CacheItemPoolInterface::class);
        $id = $this->server->getId()->toRfc4122();

        foreach (['discord.chat.'.$id, 'events.bridge.'.$id, 'bridge.sequence.'.$id] as $key) {
            $entry = $cache->getItem($key);
            $entry->set('keep me');
            $cache->save($entry);
        }

        $this->clear();

        foreach (['discord.chat.'.$id, 'events.bridge.'.$id, 'bridge.sequence.'.$id] as $key) {
            self::assertTrue($cache->getItem($key)->isHit(), $key.' is a position, not a cache');
        }
    }

    public function testSaysSoWhenThereIsNoServerYet(): void
    {
        $this->em->remove($this->server);
        $this->em->flush();

        $this->signIn();

        $this->clear();

        self::assertResponseIsSuccessful();
        self::assertSame(CacheClearVerdict::NOTHING, $this->body()['state']);
        self::assertSame(0, $this->body()['servers']);
    }

    public function testRefusesSomebodyWhoMayNotEditSettings(): void
    {
        $this->signIn([Permission::ViewServers]);

        $this->clear();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRefusesSomebodyWhoIsNotSignedIn(): void
    {
        $this->clear();

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** A GET must not clear anything; the browser prefetches those. */
    public function testAnswersOnlyToPost(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/settings/cache', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    private function clear(): void
    {
        $this->client->request('POST', '/api/settings/cache', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<Permission> $permissions */
    private function signIn(array $permissions = [Permission::ViewServers, Permission::EditSettings]): void
    {
        $role = new Role('cache-tester', 'Cache tester', $permissions);
        $this->em->persist($role);

        $user = new User('cache@example.com', 'Test User');
        $user->setRoles([User::ROLE_USER]);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );
        $user->assignRole($role);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(
                ['email' => 'cache@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
