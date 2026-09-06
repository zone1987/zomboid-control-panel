<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameServer;
use App\Entity\RconConfig;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use App\Server\Rcon\RconClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The point of brief 06, demonstrated: a moderator who may kick but not
 * ban, without also holding the FTP credentials.
 */
final class PermissionEnforcementTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->set(RconClientInterface::class, new SilentRconClient());
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAModeratorMayKickButNotBan(): void
    {
        $server = $this->server();
        $this->signInWith([Permission::ViewPlayers, Permission::KickPlayers]);

        $this->request('POST', $this->url($server).'/bob/kick', []);
        self::assertResponseIsSuccessful();

        $this->request('POST', $this->url($server).'/bob/ban', []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAModeratorMayNotChangeAnAccessLevel(): void
    {
        $server = $this->server();
        $this->signInWith([Permission::ViewPlayers, Permission::KickPlayers]);

        $this->request('POST', $this->url($server).'/bob/access-level', ['level' => 'admin']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** Without it the list itself is closed, not merely the buttons on it. */
    public function testSomeoneWithoutTheViewPermissionCannotSeeTheListAtAll(): void
    {
        $server = $this->server();
        $this->signInWith([Permission::KickPlayers]);

        $this->request('GET', $this->url($server));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testARoleGrantingTeleportAllowsIt(): void
    {
        $server = $this->server();
        $this->signInWith([Permission::ViewPlayers, Permission::TeleportPlayers]);

        $this->request('POST', $this->url($server).'/bob/teleport', ['x' => 100, 'y' => 200, 'z' => 0]);

        self::assertResponseIsSuccessful();
    }

    /**
     * The legacy role names still carry what they always carried, so an
     * installation that predates permissions keeps working unchanged.
     */
    public function testALegacyServerAdministratorKeepsEverythingWithoutAnyRole(): void
    {
        $server = $this->server();
        $this->createUser('legacy@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('legacy@example.com');

        $this->request('GET', $this->url($server));
        self::assertResponseIsSuccessful();

        $this->request('POST', $this->url($server).'/bob/ban', []);
        self::assertResponseIsSuccessful();
    }

    /**
     * The separation brief 06 asks for, from the other side: someone who
     * runs servers all day has no business editing accounts.
     */
    public function testARoleForServersDoesNotReachTheAdministration(): void
    {
        $this->signInWith([Permission::ViewPlayers, Permission::EditServers]);

        $this->request('GET', '/api/accounts');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->request('GET', '/api/settings');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->request('GET', '/api/roles');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** And a role for the administration does not reach the servers. */
    public function testARoleForTheAdministrationDoesNotReachTheServers(): void
    {
        $server = $this->server();
        $this->signInWith([Permission::ManageUsers, Permission::EditSettings]);

        $this->request('GET', '/api/accounts');
        self::assertResponseIsSuccessful();

        $this->request('GET', $this->url($server));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Editing a server hands over its FTP and RCON credentials, which is
     * exactly what the operator asked to be able to withhold.
     */
    public function testSeeingServersDoesNotAllowEditingThem(): void
    {
        $server = $this->server();
        $this->signInWith([Permission::ViewServers]);

        $this->request('GET', '/api/servers');
        self::assertResponseIsSuccessful();

        $this->request('PATCH', '/api/servers/'.$server->getId()->toRfc4122(), ['name' => 'Renamed']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testReadingChatDoesNotAllowSendingIt(): void
    {
        $server = $this->server();
        $this->signInWith([Permission::ReadChat]);

        $this->request('POST', '/api/servers/'.$server->getId()->toRfc4122().'/chat', [
            'message' => 'hello',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAPlainMemberWithNoRoleReachesNothing(): void
    {
        $server = $this->server();
        $this->createUser('member@example.com', [User::ROLE_USER]);
        $this->signIn('member@example.com');

        $this->request('GET', $this->url($server));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** @param list<Permission> $permissions */
    private function signInWith(array $permissions): void
    {
        $role = new Role('moderator', 'Moderator', $permissions);
        $this->em->persist($role);

        $user = $this->createUser('mod@example.com', [User::ROLE_USER]);
        $user->assignRole($role);
        $this->em->flush();

        $this->signIn('mod@example.com');
    }

    private function url(GameServer $server): string
    {
        return '/api/servers/'.$server->getId()->toRfc4122().'/players';
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test Server');
        $config = new RconConfig($server, '127.0.0.1', 'rcon-password');
        $this->em->persist($server);
        $this->em->persist($config);
        $this->em->flush();

        return $server;
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles): User
    {
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
}

final class SilentRconClient implements RconClientInterface
{
    public function execute(RconConfig $config, string $command): string
    {
        return 'Command sent.';
    }

    public function probe(RconConfig $config): string
    {
        return 'Players connected (0):';
    }
}
