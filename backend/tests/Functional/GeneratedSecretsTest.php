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
 * The encryption key is retrievable exactly when the container made it.
 *
 * A deployment that generates its own key leaves nobody holding a copy,
 * and a database restored without it loses every stored FTP and RCON
 * password. Showing it once is the only way the operator can save it —
 * so a container that generated one must hand it over, and one that did
 * not must say so rather than returning an empty string that reads like
 * a key.
 */
final class GeneratedSecretsTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/zc-secrets-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);

        $_ENV['GENERATED_SECRETS_PATH'] = $this->directory;
        $_SERVER['GENERATED_SECRETS_PATH'] = $this->directory;

        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clearDatabase();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        unset($_ENV['GENERATED_SECRETS_PATH'], $_SERVER['GENERATED_SECRETS_PATH']);

        parent::tearDown();
    }

    public function testHandsOverAKeyTheContainerGenerated(): void
    {
        $key = str_repeat('ab', 32);
        file_put_contents($this->directory.'/credentials-key', $key);

        $this->signIn([Permission::EditSettings]);

        $payload = $this->fetch();

        self::assertTrue($payload['generated']);
        self::assertSame($key, $payload['key']);
    }

    public function testSaysNothingWasGeneratedWhenTheOperatorSetTheKey(): void
    {
        $this->signIn([Permission::EditSettings]);

        $payload = $this->fetch();

        self::assertFalse($payload['generated']);
        self::assertNull($payload['key'], 'a key nobody generated must not be invented');
    }

    public function testAnEmptyFileIsNotAKey(): void
    {
        file_put_contents($this->directory.'/credentials-key', "\n  \n");

        $this->signIn([Permission::EditSettings]);

        $payload = $this->fetch();

        self::assertFalse($payload['generated']);
        self::assertNull($payload['key']);
    }

    /**
     * Found on the released 1.0.0 image: the entrypoint generated the key
     * as root with 0600, PHP runs as www-data, and the endpoint reported
     * "you set this yourself" — telling the operator there was nothing to
     * save while the only copy sat unreadable inside the container.
     */
    public function testAKeyItCannotReadIsNotReportedAsTheOperatorsOwn(): void
    {
        $file = $this->directory.'/credentials-key';
        file_put_contents($file, str_repeat('ef', 32));
        chmod($file, 0000);

        $this->signIn([Permission::EditSettings]);

        $payload = $this->fetch();

        if (is_readable($file)) {
            chmod($file, 0600);
            self::markTestSkipped('this user can read a 0000 file, so the case cannot be staged');
        }

        chmod($file, 0600);

        self::assertTrue($payload['unreadable'], 'an unreadable key must say so');
        self::assertTrue($payload['generated'], 'the key does exist, it just cannot be read');
        self::assertNull($payload['key']);
    }

    public function testTheKeyIsRefusedWithoutTheSettingsPermission(): void
    {
        file_put_contents($this->directory.'/credentials-key', str_repeat('cd', 32));

        $this->signIn([Permission::ViewPlayers]);

        $this->client->request('GET', '/api/settings/generated-secrets');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /** @return array{generated: bool, key: ?string, unreadable: bool} */
    private function fetch(): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING | E_NOTICE);

        try {
            $this->client->request('GET', '/api/settings/generated-secrets');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();

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
        $role = new Role('secret-tester', 'Secret tester', $permissions);
        $this->em->persist($role);

        $user = new User('secret@example.com', 'Test User');
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
                ['email' => 'secret@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
