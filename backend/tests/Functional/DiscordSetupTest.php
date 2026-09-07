<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\DiscordConfig;
use App\Entity\GameServer;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use App\Server\Discord\CommandCatalogue;
use App\Server\Discord\NotifiableEvents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The endpoint the Discord page is drawn from.
 *
 * Written after a fresh setup answered 500: `$rights[$name]?->…` reads
 * a *missing key* rather than a null value, and on a server nobody has
 * configured every key is missing. A controller the interface polls
 * needs a test that asks for the response — CLAUDE.md 10g.
 */
final class DiscordSetupTest extends FunctionalTestCase
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

        $this->server = new GameServer('Discord test');
        $this->em->persist($this->server);
        $this->em->flush();
    }

    /**
     * The case that produced the 500: nothing configured at all.
     *
     * The response is checked **and** so is PHP's own error output. In
     * the test environment an undefined array key is a warning and the
     * request still answers 200, so asserting only the status would
     * have passed while dev answered 500. PHPUnit has no
     * `failOnPhpWarning`, so the test collects them itself.
     */
    public function testAnswersForAServerWithNothingConfigured(): void
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
            $this->fetch();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();

        $body = $this->body();

        self::assertFalse($body['tokenConfigured']);
        self::assertSame('', $body['guildId']);
        self::assertCount(count(NotifiableEvents::all()), $body['events']);
        self::assertCount(count(CommandCatalogue::names()), $body['commands']);
    }

    /** Every event is listed, and every one of them starts off. */
    public function testEveryEventIsListedAndOffByDefault(): void
    {
        $this->signIn();

        $this->fetch();

        foreach ($this->body()['events'] as $event) {
            self::assertFalse($event['enabled'], $event['type'].' must start off');
            self::assertFalse($event['active'], $event['type'].' must start inactive');
            self::assertNotNull($event['defaultTemplate'], $event['type'].' has no wording');
            self::assertNotSame([], $event['tokens'], $event['type'].' declares no tokens');
        }
    }

    /**
     * Every command says what it costs in the panel. A null permission
     * would mean the command is ungated, which is a bug rather than a
     * configuration.
     */
    public function testEveryCommandNamesItsPanelPermission(): void
    {
        $this->signIn();

        $this->fetch();

        foreach ($this->body()['commands'] as $command) {
            self::assertNotNull(
                $command['permission'],
                $command['name'].' would run with no panel permission',
            );
            self::assertSame([], $command['roleIds'], $command['name'].' must start with no roles');
        }
    }

    public function testLinkingAndUnlinkingAGuild(): void
    {
        $this->signIn();

        $this->patch(['guildId' => '111111111111111111']);

        self::assertResponseIsSuccessful();

        $this->fetch();
        self::assertSame('111111111111111111', $this->body()['guildId']);

        $this->patch(['guildId' => '']);

        self::assertResponseIsSuccessful();

        $this->fetch();
        self::assertSame('', $this->body()['guildId']);
    }

    public function testRefusesAGuildIdThatIsNotOne(): void
    {
        $this->signIn();

        $this->patch(['guildId' => 'my-guild']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Chat settings need a guild; without one there is nothing to set. */
    public function testRefusesChatSettingsWithNoGuild(): void
    {
        $this->signIn();

        $this->patch(['chatScope' => 'allPublic']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testSavesAnEventsWordingAndReportsAnUnknownToken(): void
    {
        $this->signIn();
        $this->linked();

        $this->client->request(
            'PUT',
            $this->url().'/events/moderation.kick',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(
                ['enabled' => true, 'template' => '{plyer} went'],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        // A warning, not a refusal: the token stays visible, which is
        // how the operator notices the typo.
        self::assertSame(['plyer'], $this->body()['unknownTokens']);
    }

    public function testRefusesAnEventTypeItDoesNotKnow(): void
    {
        $this->signIn();
        $this->linked();

        $this->client->request(
            'PUT',
            $this->url().'/events/moderation.invented',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['enabled' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testSavesTheRolesForACommand(): void
    {
        $this->signIn();
        $this->linked();

        $this->client->request(
            'PUT',
            $this->url().'/commands/spieler.kick',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(
                ['roleIds' => ['222222222222222222', 'nonsense']],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        // The nonsense one is dropped rather than stored.
        self::assertSame(['222222222222222222'], $this->body()['roleIds']);
    }

    public function testARoleWithoutTheDiscordPermissionIsRefused(): void
    {
        $this->signIn([Permission::ViewServers]);

        $this->fetch();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function linked(): void
    {
        $config = new DiscordConfig($this->server, '111111111111111111');

        $this->em->persist($config);
        $this->em->flush();
    }

    private function url(): string
    {
        return sprintf('/api/servers/%s/discord', $this->server->getId()->toRfc4122());
    }

    private function fetch(): void
    {
        $this->client->request('GET', $this->url(), server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /** @param array<string, mixed> $payload */
    private function patch(array $payload): void
    {
        $this->client->request(
            'PATCH',
            $this->url(),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param list<Permission> $permissions */
    private function signIn(array $permissions = [Permission::ViewServers, Permission::ManageDiscord]): void
    {
        $role = new Role('discord-tester', 'Discord tester', $permissions);
        $this->em->persist($role);

        $user = new User('discord@example.com', 'Test User');
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
                ['email' => 'discord@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
