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
 * The spawn endpoint's own guards.
 *
 * The command used to be built by the event catalogue, whose dispatcher
 * test pinned the injection guard. Spawning now owns its command, so the
 * guard is pinned here instead -- the endpoint is the only thing between
 * a request body and an RCON argument.
 */
final class VehicleSpawnTest extends FunctionalTestCase
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

        $this->server = new GameServer('Spawn test');
        $this->em->persist($this->server);
        $this->em->flush();
    }

    public function testRefusesAScriptNameCarryingAQuote(): void
    {
        $this->signIn();

        $this->spawn('Base.Van" ; quit "', 'bob');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('vehicles.invalidScript', $this->error());
    }

    public function testRefusesAScriptNameCarryingASemicolon(): void
    {
        $this->signIn();

        $this->spawn('Base.Van; quit', 'bob');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('vehicles.invalidScript', $this->error());
    }

    public function testRefusesAnEmptyScript(): void
    {
        $this->signIn();

        $this->spawn('', 'bob');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('vehicles.invalidScript', $this->error());
    }

    /** The vehicle appears beside somebody, so there has to be somebody. */
    public function testRefusesAMissingPlayer(): void
    {
        $this->signIn();

        $this->spawn('Base.Van', '');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('vehicles.playerRequired', $this->error());
    }

    /**
     * A modded vehicle is spawnable, so the shape is all that is checked:
     * a name the panel has never heard of must pass the guard and fail
     * later on RCON, not be refused here.
     */
    public function testAcceptsTheShapeOfAModdedName(): void
    {
        $this->signIn();

        $this->spawn('SomeMod.WhateverVan_02', 'bob');

        // No RCON is configured on the fixture, so the far end fails --
        // which is precisely not a 422 from the guard.
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
    }

    public function testARoleWithoutTheEventPermissionIsRefused(): void
    {
        $this->signIn([Permission::ViewPlayers]);

        $this->spawn('Base.Van', 'bob');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function spawn(string $script, string $player): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/servers/%s/vehicles/spawn', $this->server->getId()->toRfc4122()),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['script' => $script, 'player' => $player], JSON_THROW_ON_ERROR),
        );
    }

    private function error(): string
    {
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return \is_array($body) && \is_string($body['error'] ?? null) ? $body['error'] : '';
    }

    /** @param list<Permission> $permissions */
    private function signIn(array $permissions = [Permission::TriggerEvents]): void
    {
        $role = new Role('spawn-tester', 'Spawn tester', $permissions);
        $this->em->persist($role);

        $user = new User('spawner@example.com', 'Test User');
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
                ['email' => 'spawner@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
