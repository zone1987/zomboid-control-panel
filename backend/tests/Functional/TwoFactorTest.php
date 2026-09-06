<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TwoFactorTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\WebauthnCredential')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        $user = new User('admin@example.com', 'Admin');
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );

        $this->em->persist($user);
        $this->em->flush();
    }

    public function testReportsDisabledInitially(): void
    {
        $this->signIn();
        $this->request('GET', '/api/two-factor/status');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['enabled']);
    }

    public function testSetupReturnsASecretAndQrContent(): void
    {
        $this->signIn();
        $this->request('POST', '/api/two-factor/setup');

        self::assertResponseIsSuccessful();

        $payload = $this->payload();

        self::assertNotEmpty($payload['secret']);
        self::assertStringStartsWith('otpauth://totp/', $payload['qrContent']);
    }

    public function testSetupAloneDoesNotEnableTwoFactor(): void
    {
        $this->signIn();
        $this->request('POST', '/api/two-factor/setup');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);

        // The secret stays in the session until a code proves the
        // authenticator holds it too.
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testActivatesWithAValidCode(): void
    {
        $this->signIn();
        $this->request('POST', '/api/two-factor/setup');
        $secret = $this->payload()['secret'];

        $this->request('POST', '/api/two-factor/activate', ['code' => TOTP::create($secret)->now()]);

        self::assertResponseIsSuccessful();

        $payload = $this->payload();

        self::assertSame('enabled', $payload['status']);
        self::assertCount(10, $payload['backupCodes']);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);

        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertSame(10, $user->getBackupCodeCount());
    }

    public function testRejectsAWrongActivationCode(): void
    {
        $this->signIn();
        $this->request('POST', '/api/two-factor/setup');

        $this->request('POST', '/api/two-factor/activate', ['code' => '000000']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);

        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testActivationWithoutSetupIsRefused(): void
    {
        $this->signIn();
        $this->request('POST', '/api/two-factor/activate', ['code' => '123456']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('twoFactor.setupExpired', $this->payload()['error']);
    }

    public function testBackupCodesAreStoredHashed(): void
    {
        $this->enableTwoFactor();

        $codes = $this->payload()['backupCodes'];

        $stored = $this->em->getConnection()->fetchOne(
            'SELECT backup_codes FROM app_user WHERE email = ?',
            ['admin@example.com'],
        );

        self::assertStringNotContainsString($codes[0], $stored);
    }

    public function testDisablingRequiresThePassword(): void
    {
        $this->enableTwoFactor();

        $this->request('DELETE', '/api/two-factor', ['password' => 'wrong-password']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);

        self::assertTrue($user->isTotpAuthenticationEnabled());
    }

    public function testDisablesWithTheCorrectPassword(): void
    {
        $this->enableTwoFactor();

        $this->request('DELETE', '/api/two-factor', ['password' => self::PASSWORD]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);

        self::assertFalse($user->isTotpAuthenticationEnabled());
        // Disabling must not leave orphaned recovery codes behind.
        self::assertSame(0, $user->getBackupCodeCount());
    }

    public function testRegeneratingBackupCodesReplacesTheOldSet(): void
    {
        $this->enableTwoFactor();
        $first = $this->payload()['backupCodes'];

        $this->request('POST', '/api/two-factor/backup-codes', ['password' => self::PASSWORD]);

        self::assertResponseIsSuccessful();

        $second = $this->payload()['backupCodes'];

        self::assertCount(10, $second);
        self::assertEmpty(array_intersect($first, $second));
    }

    public function testLoginStopsAtTheSecondFactor(): void
    {
        $this->enableTwoFactor();
        $this->client->request('POST', '/api/logout');

        $this->signIn();

        self::assertResponseIsSuccessful();

        $payload = $this->payload();

        self::assertSame('two_factor_required', $payload['status']);
        self::assertFalse($payload['twoFactorComplete']);
        self::assertArrayNotHasKey('user', $payload);
    }

    public function testProtectedEndpointsStayClosedUntilTheCodeIsGiven(): void
    {
        $this->enableTwoFactor();
        $this->client->request('POST', '/api/logout');
        $this->signIn();

        $this->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertFalse($this->payload()['twoFactorComplete']);
    }

    private function enableTwoFactor(): void
    {
        $this->signIn();
        $this->request('POST', '/api/two-factor/setup');
        $secret = $this->payload()['secret'];
        $this->request('POST', '/api/two-factor/activate', ['code' => TOTP::create($secret)->now()]);
    }

    private function signIn(): void
    {
        $this->request('POST', '/api/login', ['email' => 'admin@example.com', 'password' => self::PASSWORD]);
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
