<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSetting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SettingsTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\AppSetting')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRefusesAnonymousCallers(): void
    {
        $this->request('GET', '/api/settings');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefusesNonAdministrators(): void
    {
        $this->createUser('server-admin@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('server-admin@example.com');

        $this->request('GET', '/api/settings');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testListsEveryEditableSetting(): void
    {
        $this->signInAsAdmin();
        $this->request('GET', '/api/settings');

        self::assertResponseIsSuccessful();

        $items = $this->payload()['items'];

        self::assertArrayHasKey(AppSetting::STEAM_API_KEY, $items);
        self::assertArrayHasKey(AppSetting::GOOGLE_CLIENT_ID, $items);
        self::assertArrayHasKey(AppSetting::MAILER_DSN, $items);
    }

    public function testStoresAndReportsASetting(): void
    {
        $this->signInAsAdmin();

        $this->request('PATCH', '/api/settings', [AppSetting::STEAM_API_KEY => 'ABC123']);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['items'][AppSetting::STEAM_API_KEY]['configured']);
    }

    public function testNeverReturnsASecretValue(): void
    {
        $this->signInAsAdmin();
        $this->request('PATCH', '/api/settings', [AppSetting::STEAM_API_KEY => 'super-secret-key']);

        $body = $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('super-secret-key', $body);
        self::assertNull($this->payload()['items'][AppSetting::STEAM_API_KEY]['value']);
    }

    public function testReturnsNonSecretValues(): void
    {
        $this->signInAsAdmin();
        $this->request('PATCH', '/api/settings', [AppSetting::MAIL_FROM_NAME => 'My Server']);

        self::assertSame('My Server', $this->payload()['items'][AppSetting::MAIL_FROM_NAME]['value']);
    }

    public function testSecretsAreEncryptedInTheDatabase(): void
    {
        $this->signInAsAdmin();
        $this->request('PATCH', '/api/settings', [AppSetting::STEAM_API_KEY => 'plaintext-key-here']);

        $stored = $this->em->getConnection()->fetchOne(
            'SELECT value FROM app_setting WHERE name = ?',
            [AppSetting::STEAM_API_KEY],
        );

        self::assertStringNotContainsString('plaintext-key-here', $stored);
        self::assertStringStartsWith('v1:', $stored);
    }

    public function testRejectsAnUnknownKey(): void
    {
        $this->signInAsAdmin();

        $this->request('PATCH', '/api/settings', ['some.made.up.key' => 'value']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('settings.unknownKey', $this->payload()['error']);
    }

    public function testClearingASettingRemovesIt(): void
    {
        $this->signInAsAdmin();
        $this->request('PATCH', '/api/settings', [AppSetting::STEAM_API_KEY => 'ABC123']);

        $this->request('PATCH', '/api/settings', [AppSetting::STEAM_API_KEY => '']);

        self::assertFalse($this->payload()['items'][AppSetting::STEAM_API_KEY]['configured']);
    }

    public function testTestingAnUnconfiguredSteamKeyIsRefused(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/settings/steam/test');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('settings.steamKeyMissing', $this->payload()['error']);
    }

    public function testRemovedStorageSettingsCannotBeWritten(): void
    {
        $this->signInAsAdmin();
        $this->request('PATCH', '/api/settings', ['s3.secret_key' => 'obsolete-secret']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('settings.unknownKey', $this->payload()['error']);
        $this->request('GET', '/api/settings');
        self::assertArrayNotHasKey('s3.secret_key', $this->payload()['items']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM app_setting WHERE name = 's3.secret_key'",
        ));
    }

    private function signInAsAdmin(): void
    {
        $this->createUser('admin@example.com', [User::ROLE_ADMIN]);
        $this->signIn('admin@example.com');
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
