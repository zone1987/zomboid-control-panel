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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventConsoleTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private EventRconClient $rcon;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $this->rcon = new EventRconClient();
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

        $this->request('GET', $this->url($server));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testListsTheActionsWithTheirFields(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['help'] = "* startrain : Start rain.\n";

        $this->request('GET', $this->url($server));

        self::assertResponseIsSuccessful();
        $byId = array_column($this->payload()['items'], null, 'id');
        self::assertArrayHasKey('startRain', $byId);
        self::assertSame('intensity', $byId['startRain']['fields'][0]['name']);
        self::assertSame(50, $byId['startRain']['fields'][0]['default']);
    }

    /**
     * A server reports through "help" only what the connected account may
     * run, so an action whose command is missing is offered greyed out
     * rather than silently failing when it is pressed.
     */
    public function testMarksAnActionUnavailableWhenTheServerDoesNotReportItsCommand(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['help'] = "* startrain : Start rain.\n";

        $this->request('GET', $this->url($server));

        $byId = array_column($this->payload()['items'], null, 'id');
        self::assertTrue($byId['startRain']['available']);
        self::assertFalse($byId['hordeAtPoint']['available']);
        self::assertSame(['createhorde2'], $byId['hordeAtPoint']['missing']);
    }

    public function testOffersEverythingWhenTheCommandListCannotBeRead(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->failWith = new RconUnreachable('The port is closed.');

        $this->request('GET', $this->url($server));

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['commandsKnown']);
        self::assertTrue($this->payload()['items'][0]['available']);
    }

    public function testTriggersAnActionAndReturnsWhatTheServerSaid(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['startrain 70'] = 'Rain started';

        $this->request('POST', $this->url($server).'/startRain', ['intensity' => 70]);

        self::assertResponseIsSuccessful();
        self::assertSame('startrain 70', $this->payload()['command']);
        self::assertSame('Rain started', $this->payload()['reply']);
        self::assertFalse($this->payload()['failed']);
    }

    /** Zomboid answers in prose, so its wording is the only signal. */
    public function testReportsARefusalTheServerPhrasedAsProse(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['thunder "ghost"'] = 'User "ghost" not found';

        $this->request('POST', $this->url($server).'/thunder', ['player' => 'ghost']);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['failed']);
    }

    public function testRefusesAValueOutsideTheActionsRange(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server).'/startRain', ['intensity' => 5000]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertSame([], $this->rcon->sent);
    }

    public function testAnswersNotFoundForAnActionThatDoesNotExist(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server).'/summonMeteor', []);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRecordsWhatWasTriggeredAndByWhom(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server).'/stopRain', []);

        $actions = $this->em->getRepository(ModerationAction::class)->findAll();
        self::assertCount(1, $actions);
        self::assertSame(ModerationAction::EVENT, $actions[0]->getAction());
        self::assertSame('stopRain', $actions[0]->getUsername());
        self::assertSame('stoprain', $actions[0]->getReason());
    }

    /** An attempt the server refused is still something somebody did. */
    public function testRecordsAnAttemptTheServerRefused(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->replies['stoprain'] = 'Error';

        $this->request('POST', $this->url($server).'/stopRain', []);

        self::assertCount(1, $this->em->getRepository(ModerationAction::class)->findAll());
    }

    public function testListsRecentEventsWithoutOtherModerationEntries(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server).'/stopRain', []);

        // The request cleared the manager, so the server has to come back
        // from the database before it can be pointed at again.
        $fresh = $this->em->find(GameServer::class, $server->getId());
        $this->em->persist(new ModerationAction($fresh, ModerationAction::KICK, 'bob', null, null, 'Kicked'));
        $this->em->flush();

        $this->request('GET', $this->url($server).'/recent/actions');

        self::assertResponseIsSuccessful();
        self::assertSame(['stopRain'], array_column($this->payload()['items'], 'action'));
    }

    private function url(GameServer $server): string
    {
        return '/api/servers/'.$server->getId()->toRfc4122().'/events';
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

final class EventRconClient implements RconClientInterface
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
