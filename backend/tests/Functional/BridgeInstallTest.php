<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use App\Server\Bridge\BridgeReloadOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The upload endpoint answers with a reload verdict, not just a version.
 *
 * The verdict is what decides whether the operator has to go and restart
 * a game server, so the interface has to receive it on every response —
 * including the ones where the reload could not even be attempted.
 */
final class BridgeInstallTest extends FunctionalTestCase
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

        $this->server = new GameServer('Bridge install test');
        $this->em->persist($this->server);
        $this->em->flush();
    }

    /**
     * No Lua path, so there is nothing to upload to. It must be a
     * conflict naming the cause, never a 500.
     */
    public function testAServerWithNoLuaPathIsRefusedWithAReason(): void
    {
        $this->signIn();

        $this->install();

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('bridge.pathMissing', $this->body()['error']);
    }

    /**
     * With a path but no reachable server the upload itself fails, which
     * is a gateway error — and still not a 500.
     */
    public function testAnUnreachableServerIsAGatewayError(): void
    {
        $this->givenAnFtpConfig();
        $this->signIn();

        $this->install();

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertArrayHasKey('error', $this->body());
    }

    public function testARoleWithoutTheBridgePermissionIsRefused(): void
    {
        $this->signIn([Permission::ViewServers]);

        $this->install();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnUnknownServerIsNotFound(): void
    {
        $this->signIn();

        $this->client->request(
            'POST',
            '/api/servers/01a06d21-0424-7894-a2ac-000000000000/bridge',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Every outcome has a translation key of the shape the interface
     * builds, so a new outcome cannot reach the operator as a raw key.
     */
    public function testEveryOutcomeHasATranslationKey(): void
    {
        $locales = [
            __DIR__.'/../../../frontend/src/i18n/locales/de.json',
            __DIR__.'/../../../frontend/src/i18n/locales/en.json',
        ];

        foreach ($locales as $path) {
            $raw = file_get_contents($path);

            self::assertIsString($raw, $path);

            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            self::assertIsArray($decoded);
            self::assertIsArray($decoded['bridge']['reload'] ?? null, 'bridge.reload is missing from '.$path);

            foreach (BridgeReloadOutcome::cases() as $case) {
                self::assertIsString(
                    $decoded['bridge']['reload'][$case->value] ?? null,
                    sprintf('"%s" has no translation in %s', $case->messageKey(), basename($path)),
                );
            }
        }
    }

    private function install(): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/servers/%s/bridge', $this->server->getId()->toRfc4122()),
            // Without the JSON content type the request is treated as a
            // form post and the CSRF guard refuses it as a 403, which
            // looks exactly like a missing permission.
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );
    }

    private function givenAnFtpConfig(): void
    {
        $config = new FtpConfig($this->server, '127.0.0.1', 'zomboid', 'secret');
        $config->setBasePath('/home/zomboid');
        $config->setLuaServerPath('/home/zomboid/media/lua/server');

        $this->em->persist($config);
        $this->em->flush();
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

    /**
     * ServerController carries `#[IsGranted(ViewServers)]` on the class
     * as well, so uploading a bridge needs both -- ManageBridge alone is
     * refused before the action is reached.
     *
     * @param list<Permission> $permissions
     */
    private function signIn(array $permissions = [Permission::ViewServers, Permission::ManageBridge]): void
    {
        $role = new Role('bridge-installer', 'Bridge installer', $permissions);
        $this->em->persist($role);

        $user = new User('bridge@example.com', 'Test User');
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
                ['email' => 'bridge@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );

    }
}
