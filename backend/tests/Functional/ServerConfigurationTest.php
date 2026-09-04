<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Entity\RconConfig;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ServerConfigurationTest extends WebTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\GameServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRefusesAnonymousCallers(): void
    {
        $this->request('GET', '/api/servers');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefusesPlainUsers(): void
    {
        $this->createUser('plain@example.com', [User::ROLE_USER]);
        $this->signIn('plain@example.com');

        $this->request('GET', '/api/servers');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCreatesAServer(): void
    {
        $this->signInAsServerAdmin();

        $this->request('POST', '/api/servers', ['name' => 'Main Server', 'description' => 'PVE']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $payload = $this->payload();

        self::assertSame('Main Server', $payload['name']);
        self::assertNull($payload['ftp']);
        self::assertNull($payload['rcon']);
    }

    public function testRejectsAServerWithoutAName(): void
    {
        $this->signInAsServerAdmin();

        $this->request('POST', '/api/servers', ['name' => '   ']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testStoresTransferCredentials(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', "/api/servers/$id", [
            'ftp' => [
                'protocol' => 'sftp',
                'host' => 'game.example.com',
                'port' => 2222,
                'username' => 'zomboid',
                'password' => 'transfer-secret',
                'basePath' => '/home/zomboid',
                'luaServerPath' => '/home/zomboid/media/lua/server',
            ],
        ]);

        self::assertResponseIsSuccessful();

        $ftp = $this->payload()['ftp'];

        self::assertSame('game.example.com', $ftp['host']);
        self::assertSame(2222, $ftp['port']);
        self::assertTrue($ftp['hasPassword']);
        self::assertArrayNotHasKey('password', $ftp);
    }

    public function testNeverReturnsStoredSecrets(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', "/api/servers/$id", [
            'ftp' => ['host' => 'h', 'username' => 'u', 'password' => 'transfer-secret'],
            'rcon' => ['host' => 'h', 'password' => 'rcon-secret'],
        ]);

        $body = $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('transfer-secret', $body);
        self::assertStringNotContainsString('rcon-secret', $body);
    }

    public function testSecretsAreEncryptedInTheDatabase(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', "/api/servers/$id", [
            'rcon' => ['host' => 'h', 'password' => 'rcon-plaintext'],
        ]);

        $stored = $this->em->getConnection()->fetchOne('SELECT password FROM rcon_config LIMIT 1');

        self::assertStringNotContainsString('rcon-plaintext', $stored);
        self::assertStringStartsWith('v1:', $stored);
    }

    public function testAnEmptyPasswordLeavesTheStoredOneAlone(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', "/api/servers/$id", [
            'rcon' => ['host' => 'h', 'password' => 'keep-me'],
        ]);

        $this->request('PATCH', "/api/servers/$id", [
            'rcon' => ['host' => 'other.example.com', 'password' => ''],
        ]);

        $this->em->clear();
        $config = $this->em->getRepository(RconConfig::class)->findOneBy([]);

        self::assertSame('keep-me', $config->getPassword());
        self::assertSame('other.example.com', $config->getHost());
    }

    public function testReportsAnUnreachableRconServer(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', "/api/servers/$id", [
            'rcon' => ['host' => '127.0.0.1', 'port' => 27099, 'password' => 'secret'],
        ]);

        $this->request('POST', "/api/servers/$id/rcon/test");

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertSame('rcon.unreachable', $this->payload()['error']);
    }

    public function testReportsAnUnreachableTransferHost(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', "/api/servers/$id", [
            'ftp' => ['protocol' => 'sftp', 'host' => '127.0.0.1', 'port' => 2299, 'username' => 'nobody', 'password' => 'x'],
        ]);

        $this->request('POST', "/api/servers/$id/ftp/test");

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertContains($this->payload()['error'], ['storage.unreachable', 'storage.operationFailed']);
    }

    public function testDeletesAServerWithItsCredentials(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', "/api/servers/$id", [
            'ftp' => ['host' => 'h', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->request('DELETE', "/api/servers/$id");

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->em->clear();

        self::assertCount(0, $this->em->getRepository(GameServer::class)->findAll());
        // The cascade must take the credentials with it, or they linger
        // in the database with nothing pointing at them.
        self::assertCount(0, $this->em->getRepository(FtpConfig::class)->findAll());
    }

    public function testReportsAnUnknownServer(): void
    {
        $this->signInAsServerAdmin();

        $this->request('GET', '/api/servers/01920000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function createServer(): string
    {
        $this->request('POST', '/api/servers', ['name' => 'Test Server']);

        return $this->payload()['id'];
    }

    private function signInAsServerAdmin(): void
    {
        $this->createUser('server-admin@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('server-admin@example.com');
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles): void
    {
        $user = new User($email, 'Test User');
        $user->setRoles($roles);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );

        $this->em->persist($user);
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
