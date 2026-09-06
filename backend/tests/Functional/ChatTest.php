<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FtpConfig;
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

final class ChatTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingChatRcon $rcon;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->rcon = new RecordingChatRcon();
        self::getContainer()->set(RconClientInterface::class, $this->rcon);
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\ModerationAction')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\GameServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRefusesChatToSomeoneWithoutTheServerRole(): void
    {
        $server = $this->server();
        $this->createUser('member@example.com', [User::ROLE_USER]);
        $this->signIn('member@example.com');

        $this->request('POST', $this->url($server), ['message' => 'hello']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBroadcastsAMessageAsAQuotedServerMessage(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server), ['message' => 'Restart in 5 minutes']);

        self::assertResponseIsSuccessful();
        self::assertSame(['servermsg "Restart in 5 minutes"'], $this->rcon->sent);
    }

    /**
     * The message travels as a quoted argument, so a quote of its own
     * would close it early and the rest would be read as more arguments.
     */
    public function testNeverLetsAMessageBreakOutOfItsQuotedArgument(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server), ['message' => 'say "hi" now']);

        self::assertResponseIsSuccessful();
        self::assertSame(['servermsg "say \'hi\' now"'], $this->rcon->sent);
    }

    public function testRejectsAMessageOfOnlyWhitespace(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server), ['message' => "  \n  "]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->rcon->sent);
    }

    public function testRecordsWhatWasAnnouncedAndByWhom(): void
    {
        $server = $this->signedInWithServer();

        $this->request('POST', $this->url($server), ['message' => 'Server closing']);

        $actions = $this->em->getRepository(ModerationAction::class)->findAll();
        self::assertCount(1, $actions);
        self::assertSame(ModerationAction::BROADCAST, $actions[0]->getAction());
        self::assertSame('Server closing', $actions[0]->getReason());
    }

    public function testReportsAnUnreachableServerWithoutRecordingTheMessage(): void
    {
        $server = $this->signedInWithServer();
        $this->rcon->failWith = new RconUnreachable('The port is closed.');

        $this->request('POST', $this->url($server), ['message' => 'hello']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertSame([], $this->em->getRepository(ModerationAction::class)->findAll());
    }

    private function url(GameServer $server): string
    {
        return '/api/servers/'.$server->getId()->toRfc4122().'/chat';
    }

    private function signedInWithServer(): GameServer
    {
        $server = $this->server();
        $this->createUser('admin@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('admin@example.com');

        return $server;
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test Server');
        $this->em->persist($server);
        $this->em->persist(new RconConfig($server, '127.0.0.1', 'rcon-password'));
        $this->em->persist(new FtpConfig($server, 'host', 'user'));
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

final class RecordingChatRcon implements RconClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public ?\Throwable $failWith = null;

    public function execute(RconConfig $config, string $command): string
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->sent[] = $command;

        return 'Message sent.';
    }

    public function probe(RconConfig $config): string
    {
        return $this->execute($config, 'players');
    }
}
