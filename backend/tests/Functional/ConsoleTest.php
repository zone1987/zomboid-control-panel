<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\RconConfig;
use App\Entity\User;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconUnreachable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ConsoleTest extends WebTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingRconClient $rcon;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Replaced before the first request: the controller is built per
        // request, so it picks up whatever the container holds by then.
        $this->rcon = new RecordingRconClient();
        self::getContainer()->set(RconClientInterface::class, $this->rcon);
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\ModerationAction')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\GameServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        self::getContainer()->get('cache.app')->clear();
    }

    public function testRefusesTheConsoleToSomeoneWithoutTheServerRole(): void
    {
        $server = $this->server();
        $this->createUser('member@example.com', [User::ROLE_USER]);
        $this->signIn('member@example.com');

        $this->request('POST', $this->url($server), ['command' => 'players']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testSendsACommandAndReturnsTheServersReply(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['players'] = "Players connected (1):\n-admin";

        $this->request('POST', $this->url($server), ['command' => 'players']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Players connected', $this->payload()['reply']);
    }

    /** Operators type the in-game form out of habit; both should work. */
    public function testAcceptsACommandWrittenWithALeadingSlash(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server), ['command' => '/players']);

        self::assertResponseIsSuccessful();
        self::assertSame('players', $this->payload()['command']);
        self::assertSame(['players'], $this->rcon->sent);
    }

    public function testRejectsAnEmptyCommand(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server), ['command' => '   ']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->rcon->sent);
    }

    public function testRecordsWhatWasTypedInTheModerationHistory(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server), ['command' => 'servermsg "hello"']);

        $actions = $this->em->getRepository(ModerationAction::class)->findAll();
        self::assertCount(1, $actions);
        self::assertSame(ModerationAction::CONSOLE, $actions[0]->getAction());
        self::assertSame('servermsg', $actions[0]->getUsername());
        self::assertSame('servermsg "hello"', $actions[0]->getReason());
    }

    public function testReportsAnUnreachableServerAsABadGateway(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->failWith = new RconUnreachable('The port is closed.');

        $this->request('POST', $this->url($server), ['command' => 'players']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertSame([], $this->em->getRepository(ModerationAction::class)->findAll());
    }

    public function testAnswersWithAConflictWhenRconIsNotConfigured(): void
    {
        $this->signInAsServerAdmin();
        $server = new GameServer('No RCON');
        $this->em->persist($server);
        $this->em->flush();

        $this->request('POST', $this->url($server), ['command' => 'players']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testListsTheCommandsTheServerReports(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['help'] = "List of server commands : \n"
            ."* players : List connected players.\n"
            ."* additem : Give an item. Count is optional. Use: /additem \"username\" \"module.item\" count\n";

        $this->request('GET', $this->url($server).'/commands');

        self::assertResponseIsSuccessful();
        $items = $this->payload()['items'];
        self::assertSame(['additem', 'players'], array_column($items, 'name'));
        self::assertSame(
            ['username', 'module.item', 'count'],
            array_column($items[0]['parameters'], 'name'),
        );
    }

    public function testMarksCommandsThatStopTheServerAsDangerous(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['help'] = "* players : List players.\n* quit : Save and quit.\n";

        $this->request('GET', $this->url($server).'/commands');

        $byName = array_column($this->payload()['items'], 'dangerous', 'name');
        self::assertTrue($byName['quit']);
        self::assertFalse($byName['players']);
    }

    /** The command set only changes when the server does. */
    public function testAsksTheServerForItsCommandsOnlyOnce(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['help'] = "* players : List players.\n";

        $this->request('GET', $this->url($server).'/commands');
        $this->request('GET', $this->url($server).'/commands');

        self::assertSame(['help'], $this->rcon->sent);
    }

    public function testAsksAgainWhenTheOperatorRequestsARefresh(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['help'] = "* players : List players.\n";

        $this->request('GET', $this->url($server).'/commands');
        $this->request('GET', $this->url($server).'/commands?refresh=1');

        self::assertSame(['help', 'help'], $this->rcon->sent);
    }

    public function testDoesNotRememberAReplyItCouldNotParse(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['help'] = 'Unknown command: help';

        $this->request('GET', $this->url($server).'/commands');
        $this->request('GET', $this->url($server).'/commands');

        self::assertSame([], $this->payload()['items']);
        self::assertSame(['help', 'help'], $this->rcon->sent);
    }

    private function url(GameServer $server): string
    {
        return '/api/servers/'.$server->getId()->toRfc4122().'/console';
    }

    private function signedInWithServer(): GameServer
    {
        $server = $this->server();
        $this->signInAsServerAdmin();

        return $server;
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

    private function signInAsServerAdmin(): void
    {
        $this->createUser('admin@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('admin@example.com');
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class RecordingRconClient implements RconClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    /** @var array<string, string> */
    public array $replies = [];

    public ?\Throwable $failWith = null;

    public function execute(RconConfig $config, string $command): string
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->sent[] = $command;

        return $this->replies[$command] ?? 'Command sent.';
    }

    public function probe(RconConfig $config): string
    {
        return $this->execute($config, 'players');
    }
}
