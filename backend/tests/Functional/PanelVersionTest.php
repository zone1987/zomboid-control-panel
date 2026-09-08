<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The endpoint the top bar polls.
 *
 * It gained three fields so the interface can say the panel is updating
 * itself rather than leaving a reader to wonder why it restarted -- and
 * a controller the interface polls needs a test that asks for the
 * response, per CLAUDE.md 10g.
 */
final class PanelVersionTest extends FunctionalTestCase
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

    /** Warnings included: in dev an undefined key is a 500, not a 200. */
    public function testAnswersWithoutWarningsOnAFreshInstallation(): void
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
            $this->client->request('GET', '/api/panel/version', server: [
                'HTTP_ACCEPT' => 'application/json',
            ]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();

        $body = $this->body();

        foreach (['current', 'latest', 'upToDate', 'url', 'autoDeploy', 'deploying', 'reloadPanel'] as $key) {
            self::assertArrayHasKey($key, $body, $key.' is missing from the answer');
        }
    }

    /** Nothing configured must not read as a deployment in flight. */
    public function testSaysNothingIsDeployingWhenDeploymentIsOff(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/panel/version', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->body()['autoDeploy']);
        self::assertFalse($this->body()['deploying']);
        self::assertFalse($this->body()['reloadPanel']);
    }

    public function testNamesTheVersionThePanelIsRunning(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/panel/version', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->body()['current']);
    }

    public function testRefusesSomebodyWhoIsNotSignedIn(): void
    {
        $this->client->request('GET', '/api/panel/version', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function signIn(): void
    {
        $role = new Role('version-tester', 'Version tester', [Permission::ViewServers]);
        $this->em->persist($role);

        $user = new User('version@example.com', 'Test User');
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
                ['email' => 'version@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
