<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use App\Panel\DeployProbeVerdict;
use App\Security\Permission\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The endpoints behind "Verbindung prüfen" and the progress display.
 *
 * The old test button called the deploy hook, so asking whether the
 * connection worked started a real rollout and restarted the panel.
 */
final class DeployProbeEndpointTest extends FunctionalTestCase
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

    /** Nothing configured is a conflict, not a failure -- and no warnings. */
    public function testSaysSoWhenNoWebhookIsStored(): void
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
            $this->probe();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $body = $this->body();

        self::assertSame(DeployProbeVerdict::NOT_CONFIGURED, $body['state']);
        self::assertSame('settings.deploy.probe.notConfigured', $body['messageKey']);
        self::assertNull($body['status'], 'nothing was asked of the platform');
    }

    public function testTheProgressEndpointAnswersWithoutWarnings(): void
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
            $this->client->request('GET', '/api/settings/deploy/status/dep123456789', server: [
                'HTTP_ACCEPT' => 'application/json',
            ]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('settled', $this->body());
    }

    public function testRefusesSomebodyWhoMayNotEditSettings(): void
    {
        $this->signIn([Permission::ViewServers]);

        $this->probe();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRefusesSomebodyWhoIsNotSignedIn(): void
    {
        $this->probe();

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** A GET must not probe: the browser prefetches those. */
    public function testTheProbeAnswersOnlyToPost(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/settings/deploy/probe', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    private function probe(): void
    {
        $this->client->request('POST', '/api/settings/deploy/probe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ]);
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

    /** @param list<Permission> $permissions */
    private function signIn(array $permissions = [Permission::ViewServers, Permission::EditSettings]): void
    {
        $role = new Role('deploy-tester', 'Deploy tester', $permissions);
        $this->em->persist($role);

        $user = new User('deploy@example.com', 'Test User');
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
                ['email' => 'deploy@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
