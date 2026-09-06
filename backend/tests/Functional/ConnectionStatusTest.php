<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameServer;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The four lights answer at all, and say what they cannot reach.
 *
 * Untested until a refactor deleted one of its four private methods and
 * every one of 529 tests stayed green: the endpoint had no test of its
 * own, so a 500 reached a live browser instead. This is that test — it
 * would have caught it, because it asks for the response.
 */
final class ConnectionStatusTest extends FunctionalTestCase
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

        $this->server = new GameServer('Connection test');
        $this->em->persist($this->server);
        $this->em->flush();
    }

    /**
     * Nothing is configured on the fixture, so every leg is unreachable —
     * and the endpoint still has to answer, because a status page that
     * fails when the status is bad is a status page for nothing.
     */
    public function testAnswersWithAllFourLegsWhenNothingIsConfigured(): void
    {
        $this->signIn();

        $this->fetchStatus();

        self::assertResponseIsSuccessful();

        $body = $this->body();

        foreach (['ftp', 'rcon', 'bridge', 'game'] as $leg) {
            self::assertArrayHasKey($leg, $body, $leg);
            self::assertIsArray($body[$leg]);
            self::assertArrayHasKey('state', $body[$leg], $leg);
        }
    }

    /** Never set up is not the same as broken, and must not read as red. */
    public function testAnUnconfiguredLegIsNotDown(): void
    {
        $this->signIn();

        $this->fetchStatus();

        $body = $this->body();

        self::assertSame('unconfigured', $body['ftp']['state']);
        self::assertSame('unconfigured', $body['rcon']['state']);
    }

    /**
     * The bridge is judged by what is running, so a server with no
     * reading at all cannot claim to be up to date.
     */
    public function testAMissingBridgeIsDownWithAReason(): void
    {
        $this->signIn();

        $this->fetchStatus();

        $body = $this->body();

        self::assertSame('down', $body['bridge']['state']);
        self::assertSame('bridge.notInstalled', $body['bridge']['detail']);
    }

    /** Without a bridge reading, whether the game runs is unknown, not "no". */
    public function testTheGameIsUnknownWithoutABridgeReading(): void
    {
        $this->signIn();

        $this->fetchStatus();

        self::assertSame('unknown', $this->body()['game']['state']);
    }

    public function testARoleWithoutTheServerPermissionIsRefused(): void
    {
        $this->signIn([Permission::ViewPlayers]);

        $this->fetchStatus();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnUnknownServerIsNotFound(): void
    {
        $this->signIn();

        $this->client->request(
            'GET',
            '/api/servers/01a06d21-0424-7894-a2ac-000000000000/connections',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function fetchStatus(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/servers/%s/connections', $this->server->getId()->toRfc4122()),
            server: ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($body);

        return $body;
    }

    /** @param list<Permission> $permissions */
    private function signIn(array $permissions = [Permission::ViewServers]): void
    {
        $role = new Role('connection-tester', 'Connection tester', $permissions);
        $this->em->persist($role);

        $user = new User('connections@example.com', 'Test User');
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
                ['email' => 'connections@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
