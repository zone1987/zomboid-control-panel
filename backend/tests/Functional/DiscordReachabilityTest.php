<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameServer;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Whether the panel admits Discord cannot reach it.
 *
 * The failure this exists for: slash commands answered "the application
 * is not responding" in Discord while every setting was correct, because
 * `APP_PUBLIC_URL` was a `.ddev.site` address that only resolves on the
 * developer's machine. Discord calls *us*, so no amount of configuration
 * fixes that — and the symptom points nowhere useful.
 *
 * Notifications and outbound chat are unaffected, because for those the
 * panel calls Discord. Two capabilities, reported separately.
 */
final class DiscordReachabilityTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clearDatabase();
    }

    /**
     * The test environment's own URL is a development one, so this is
     * the case that must report false — and it is the case a developer
     * actually hits.
     */
    public function testTheSettingsEndpointSaysWhetherDiscordCanReachUs(): void
    {
        $this->signIn([Permission::EditSettings]);

        $this->client->request('GET', '/api/settings', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();

        $body = $this->body();

        self::assertArrayHasKey('discordReachable', $body);
        self::assertIsBool($body['discordReachable']);
        self::assertArrayHasKey('discordInteractionUrl', $body);
        self::assertStringContainsString('/api/discord/interactions', $body['discordInteractionUrl']);
    }

    /** The Discord page reports it too, since that is where it matters. */
    public function testTheDiscordPageSaysWhetherCommandsCanWork(): void
    {
        $server = new GameServer('Reachability test');
        $this->em->persist($server);
        $this->em->flush();

        $this->signIn([Permission::ViewServers, Permission::ManageDiscord]);

        $this->client->request(
            'GET',
            sprintf('/api/servers/%s/discord', $server->getId()->toRfc4122()),
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();
        self::assertIsBool($this->body()['commandsReachable']);
    }

    /**
     * A local address is not reachable, whatever else is configured.
     *
     * Asserted against the environment the suite runs in, which uses a
     * `.ddev.site` host — so this test would have caught the original
     * fault before Discord did.
     */
    public function testADevelopmentAddressIsNotReachable(): void
    {
        $this->signIn([Permission::EditSettings]);

        $this->client->request('GET', '/api/settings', server: ['HTTP_ACCEPT' => 'application/json']);

        $body = $this->body();
        $host = (string) parse_url($body['discordInteractionUrl'], PHP_URL_HOST);

        // Only meaningful while the suite runs against a local host; on
        // a public one the expectation flips, and saying so beats a test
        // that silently checks nothing.
        $local = !str_contains($host, '.')
            || str_ends_with($host, '.ddev.site')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');

        self::assertSame(
            !$local,
            $body['discordReachable'],
            sprintf('"%s" was judged wrongly', $host),
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
    private function signIn(array $permissions): void
    {
        $role = new Role('reach-tester', 'Reachability tester', $permissions);
        $this->em->persist($role);

        $user = new User('reach@example.com', 'Test User');
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
                ['email' => 'reach@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
