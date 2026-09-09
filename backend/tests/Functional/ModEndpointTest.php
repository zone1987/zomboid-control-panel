<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Server\Mods\WorkshopItem;
use App\Server\Mods\WorkshopResult;
use App\Server\Mods\WorkshopSource;
use App\Server\Mods\WorkshopState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The endpoints the mods screen polls.
 *
 * Written because a controller answering a shape the interface reads
 * can lose a method to a refactor while every other test stays green
 * (rule 10g). Each case asks for the response rather than only its
 * status, and watches for PHP warnings: in the test environment an
 * undefined key is a warning and a 200, in dev the same line is a 500
 * (rule 10h2).
 */
final class ModEndpointTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Without this the kernel rebuilds its container per request and
        // throws the double away, so the real client answers instead.
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\GameServer')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRefusesAnonymousCallers(): void
    {
        $this->request('GET', '/api/servers/'.self::anyUuid().'/mods/search');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefusesAUserWithoutTheModPermission(): void
    {
        $this->createUser('plain@example.com', [User::ROLE_USER]);
        $this->signIn('plain@example.com');

        $this->request('GET', '/api/servers/'.self::anyUuid().'/mods/search');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testSearchAnswersWithoutAKeyRatherThanFailing(): void
    {
        $this->useWorkshop(WorkshopResult::failed(WorkshopState::NoKey), hasKey: false);
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $warnings = $this->watchWarnings(fn () => $this->request('GET', '/api/servers/'.$id.'/mods/search?q=fire'));

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();

        $payload = $this->payload();

        self::assertSame('noKey', $payload['state']);
        self::assertFalse($payload['hasKey']);
        self::assertSame([], $payload['items']);
    }

    public function testSearchDescribesWhatItFound(): void
    {
        $this->useWorkshop(WorkshopResult::ok([self::item('123', 'Fitted Sheets')], 42));
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $warnings = $this->watchWarnings(fn () => $this->request('GET', '/api/servers/'.$id.'/mods/search?q=sheets'));

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();

        $payload = $this->payload();

        self::assertSame(42, $payload['total']);
        self::assertSame('Fitted Sheets', $payload['items'][0]['title']);
        self::assertTrue($payload['items'][0]['resolved']);
        self::assertSame('42', $payload['items'][0]['declaredBuild']);
    }

    /**
     * The user's requirement: what a server sees depends on the build it
     * runs. Filtered at Steam, so paging does not develop gaps.
     */
    public function testSearchAsksSteamOnlyForTheSelectedServersBuild(): void
    {
        $workshop = $this->useRecordingWorkshop();
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', '/api/servers/'.$id, ['gameBuild' => '42.20']);
        self::assertResponseIsSuccessful();

        $this->request('GET', '/api/servers/'.$id.'/mods/search?q=fire');

        self::assertResponseIsSuccessful();
        self::assertSame(['Build 42'], $workshop->lastTags);
        self::assertSame('Build 42', $this->payload()['buildFilter']);
    }

    /**
     * An unknown build must not filter: guessing one would hide mods
     * that are perfectly fine and look like a broken search.
     */
    public function testSearchDoesNotFilterWhenNobodySaidWhichBuildTheServerRuns(): void
    {
        $workshop = $this->useRecordingWorkshop();
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('GET', '/api/servers/'.$id.'/mods/search?q=fire');

        self::assertResponseIsSuccessful();
        self::assertSame([], $workshop->lastTags);
        self::assertNull($this->payload()['buildFilter']);
    }

    public function testRefusesABuildNobodyCouldRead(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('PATCH', '/api/servers/'.$id, ['gameBuild' => 'the newest one']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation.invalid', $this->payload()['errors']['gameBuild']);
    }

    /**
     * Without transfer credentials there is no file to read, which is a
     * state the screen explains rather than an error it reports.
     */
    public function testInstalledSaysWhenAServerHasNoTransferCredentials(): void
    {
        $this->useWorkshop(WorkshopResult::ok([]));
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $warnings = $this->watchWarnings(fn () => $this->request('GET', '/api/servers/'.$id.'/mods/installed'));

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();
        self::assertSame('noTransfer', $this->payload()['state']);
    }

    public function testRefusesAnIdThatIsNotANumber(): void
    {
        $this->signInAsServerAdmin();
        $id = $this->createServer();

        $this->request('POST', '/api/servers/'.$id.'/mods/installed', ['workshopId' => 'not-an-id']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('mods.invalidId', $this->payload()['error']);
    }

    public function testAnUnknownServerIsNotFound(): void
    {
        $this->signInAsServerAdmin();

        $this->request('GET', '/api/servers/'.self::anyUuid().'/mods/installed');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function useRecordingWorkshop(): object
    {
        $double = new class implements WorkshopSource {
            /** @var list<string> */
            public array $lastTags = [];

            public function hasKey(): bool
            {
                return true;
            }

            public function itemsById(array $workshopIds): WorkshopResult
            {
                return WorkshopResult::ok([]);
            }

            public function search(
                string $term = '',
                array $tags = [],
                string $sort = 'trend',
                int $page = 1,
                int $perPage = 30,
            ): WorkshopResult {
                $this->lastTags = $tags;

                return WorkshopResult::ok([]);
            }

            public function details(string $workshopId): WorkshopResult
            {
                return WorkshopResult::ok([]);
            }
        };

        self::getContainer()->set(WorkshopSource::class, $double);

        return $double;
    }

    /**
     * @param WorkshopResult<WorkshopItem> $answer
     */
    private function useWorkshop(WorkshopResult $answer, bool $hasKey = true): void
    {
        self::getContainer()->set(WorkshopSource::class, new class($answer, $hasKey) implements WorkshopSource {
            public function __construct(
                private readonly WorkshopResult $answer,
                private readonly bool $key,
            ) {
            }

            public function hasKey(): bool
            {
                return $this->key;
            }

            public function itemsById(array $workshopIds): WorkshopResult
            {
                return $this->answer;
            }

            public function search(
                string $term = '',
                array $tags = [],
                string $sort = 'trend',
                int $page = 1,
                int $perPage = 30,
            ): WorkshopResult {
                return $this->answer;
            }

            public function details(string $workshopId): WorkshopResult
            {
                return $this->answer;
            }
        });
    }

    private static function item(string $id, string $title): WorkshopItem
    {
        return new WorkshopItem(
            workshopId: $id,
            title: $title,
            description: 'A description.',
            previewUrl: 'https://example.invalid/preview.jpg',
            tags: ['Build 42', 'Textures'],
            fileSize: 1024,
            createdAt: new \DateTimeImmutable('2026-01-01'),
            updatedAt: new \DateTimeImmutable('2026-02-01'),
            subscriptions: 10,
            favourites: 2,
            views: 100,
        );
    }

    /**
     * @return list<string>
     */
    private function watchWarnings(callable $call): array
    {
        $warnings = [];

        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, \E_WARNING | \E_NOTICE);

        try {
            $call();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    private static function anyUuid(): string
    {
        return '01936b7a-0000-7000-8000-000000000000';
    }

    private function createServer(): string
    {
        $this->request('POST', '/api/servers', ['name' => 'Test Server']);

        return $this->payload()['id'];
    }

    private function signInAsServerAdmin(): void
    {
        $this->createUser('server-admin@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('server-admin@example.com');
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles): void
    {
        $user = new User($email, 'Test User');
        $user->setRoles($roles);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );

        $this->em->persist($user);
        $this->em->flush();
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
