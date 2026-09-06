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
 * The climate read, and who may ask for it.
 *
 * Written before the page, because the endpoint that lost a method
 * silently taught the lesson: a controller the interface polls needs a
 * test that asks for the response.
 */
final class ClimateReadTest extends FunctionalTestCase
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

        $this->server = new GameServer('Climate test');
        $this->em->persist($this->server);
        $this->em->flush();
    }

    /**
     * The values live in the running game, so with no bridge there is
     * nothing to read — and the reason has to be said, because "no
     * bridge" and "server down" send an operator to different places.
     */
    public function testSaysWhyItCannotReadWithoutABridge(): void
    {
        $this->signIn();

        $this->read();

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);

        $body = $this->body();

        self::assertSame('failed', $body['status']);
        self::assertIsString($body['error']);
        self::assertNotSame('', $body['error']);
    }

    /**
     * Looking is not setting: somebody who may view a server may see what
     * its weather is set to. Changing it needs the event permission on
     * the event endpoint.
     */
    public function testViewingAServerIsEnoughToRead(): void
    {
        $this->signIn([Permission::ViewServers]);

        $this->read();

        // Not 403: the request got as far as the bridge, which is absent.
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
    }

    public function testARoleWithoutTheServerPermissionIsRefused(): void
    {
        $this->signIn([Permission::ViewPlayers]);

        $this->read();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnUnknownServerIsNotFound(): void
    {
        $this->signIn();

        $this->client->request(
            'GET',
            '/api/servers/01a06d21-0424-7894-a2ac-000000000000/climate',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** Reading must not be a write: polling this every few seconds is the point. */
    public function testTheEndpointRefusesAPost(): void
    {
        $this->signIn();

        $this->client->request(
            'POST',
            sprintf('/api/servers/%s/climate', $this->server->getId()->toRfc4122()),
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    private function read(): void
    {
        $this->client->request(
            'GET',
            sprintf('/api/servers/%s/climate', $this->server->getId()->toRfc4122()),
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
        $role = new Role('climate-tester', 'Climate tester', $permissions);
        $this->em->persist($role);

        $user = new User('climate@example.com', 'Test User');
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
                ['email' => 'climate@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
