<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSetting;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Reading a stored secret back.
 *
 * The settings list masks every secret, which meant an operator could
 * never see what they had entered -- the only way to check a token was
 * to paste it again. This hands one back on request, so it travels when
 * somebody asked for that one rather than on every page view.
 */
final class SettingsSecretsTest extends FunctionalTestCase
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

    public function testHandsBackAStoredSecret(): void
    {
        $this->signIn();
        $this->store(AppSetting::STEAM_API_KEY, 'a-secret-key');

        $this->reveal(AppSetting::STEAM_API_KEY);

        self::assertResponseIsSuccessful();
        self::assertSame('a-secret-key', $this->body()['value']);
    }

    /**
     * Nothing stored is not an empty secret, and the interface says
     * different things about the two.
     */
    public function testSaysNullWhenNothingIsStored(): void
    {
        $this->signIn();

        $this->reveal(AppSetting::STEAM_API_KEY);

        self::assertResponseIsSuccessful();
        self::assertNull($this->body()['value']);
    }

    /** Only a key the mask covers; anything else is not ours to read. */
    public function testRefusesAKeyThatIsNotASecret(): void
    {
        $this->signIn();

        $this->reveal('mail.from_name');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRefusesAnInventedKey(): void
    {
        $this->signIn();

        $this->reveal('nothing.like.this');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** The one that matters: a secret is not readable without the right. */
    public function testRefusesSomebodyWhoMayNotEditSettings(): void
    {
        $this->signIn([Permission::ViewServers]);
        $this->store(AppSetting::STEAM_API_KEY, 'a-secret-key');

        $this->reveal(AppSetting::STEAM_API_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRefusesSomebodyWhoIsNotSignedIn(): void
    {
        $this->reveal(AppSetting::STEAM_API_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function reveal(string $name): void
    {
        $this->client->request('GET', '/api/settings/reveal/'.$name, server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    private function store(string $name, string $value): void
    {
        $setting = new AppSetting($name, $value);
        $this->em->persist($setting);
        $this->em->flush();
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
