<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Server\Items\Icons\IconStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * An operator whose server is rented has no game installation to point
 * the extraction command at, so the packs come in through the browser.
 */
final class IconUploadTest extends WebTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IconStore $store;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->store = self::getContainer()->get(IconStore::class);
    }

    public function testRefusesTheUploadToSomeoneWithoutTheServerRole(): void
    {
        $this->createUser('member@example.com', [User::ROLE_USER]);
        $this->signIn('member@example.com');

        $this->client->request('POST', '/api/icons/upload');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRejectsAnUploadWithNoFile(): void
    {
        $this->signInAsServerAdmin();

        $this->client->request('POST', '/api/icons/upload');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTakesAPackAndReportsWhatItFound(): void
    {
        $this->signInAsServerAdmin();
        $before = $this->store->count();

        $this->client->request('POST', '/api/icons/upload', files: [
            'packs' => [$this->pack('ApComUI.pack')],
        ]);

        self::assertResponseIsSuccessful();
        $result = $this->payload()['results'][0];
        self::assertFalse($result['failed'], var_export($result, true));
        self::assertGreaterThan(0, $result['extracted']);
        self::assertGreaterThanOrEqual($before, $this->payload()['count']);
    }

    /** A world-tile pack parses differently and holds no item icons. */
    public function testReportsAFileThatIsNotAnIconPack(): void
    {
        $this->signInAsServerAdmin();

        $this->client->request('POST', '/api/icons/upload', files: [
            'packs' => [$this->rubbish()],
        ]);

        self::assertResponseIsSuccessful();
        $result = $this->payload()['results'][0];
        self::assertTrue($result['failed']);
        self::assertSame('icons.notAnIconPack', $result['error']);
    }

    public function testTellsTheInterfaceWhichPacksAreWanted(): void
    {
        $this->signInAsServerAdmin();

        $this->client->request('GET', '/api/icons');

        self::assertResponseIsSuccessful();
        self::assertSame(['UI.pack', 'UI2.pack', 'ApComUI.pack'], $this->payload()['wanted']);
    }

    private function pack(string $name): UploadedFile
    {
        $source = \dirname(__DIR__).'/Support/packs/'.$name;

        if (!is_file($source)) {
            self::markTestSkipped(sprintf('%s is not in tests/Support/packs.', $name));
        }

        // Copied because an UploadedFile in test mode is moved, not read.
        $copy = sys_get_temp_dir().'/'.uniqid('pack-', true).'.pack';
        copy($source, $copy);

        return new UploadedFile($copy, $name, 'application/octet-stream', test: true);
    }

    private function rubbish(): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('rubbish-', true).'.pack';
        file_put_contents($path, str_repeat("\x00", 64));

        return new UploadedFile($path, 'Tiles.pack', 'application/octet-stream', test: true);
    }

    private function signInAsServerAdmin(): void
    {
        $this->createUser('admin@example.com', [User::ROLE_SERVER_ADMIN]);
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
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
