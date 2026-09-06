<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use App\Server\Vehicles\Models\ModelStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A model or texture never changes under the same name, so the map
 * should be told to keep it and a repeat fetch should transfer nothing.
 */
final class VehicleModelCachingTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private const NAME = 'zzz-caching-test-model.txt';

    private const BODY = 'version 1.0
mesh CachingTest
';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ModelStore $models;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Role')->execute();

        $this->models = self::getContainer()->get(ModelStore::class);
        $this->models->write(self::NAME, self::BODY);
    }

    protected function tearDown(): void
    {
        $this->models->delete(self::NAME);

        parent::tearDown();
    }

    public function testAFirstRequestCarriesAStrongEtagAndTheBody(): void
    {
        $this->signInWith([Permission::ViewVehicles]);

        $this->client->request('GET', '/api/vehicle-models/'.self::NAME);

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame(self::BODY, $response->getContent());

        $etag = $response->getEtag();
        self::assertNotNull($etag);
        self::assertStringNotContainsString('W/', (string) $etag);
        self::assertSame('"'.md5(self::BODY).'"', $etag);
    }

    public function testAnUnchangedModelAnswers304WithNoBody(): void
    {
        $this->signInWith([Permission::ViewVehicles]);

        $this->client->request('GET', '/api/vehicle-models/'.self::NAME);
        $etag = (string) $this->client->getResponse()->getEtag();

        $this->client->request(
            'GET',
            '/api/vehicle-models/'.self::NAME,
            server: ['HTTP_IF_NONE_MATCH' => $etag],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
    }

    public function testAStaleEtagStillGetsTheContent(): void
    {
        $this->signInWith([Permission::ViewVehicles]);

        $this->client->request(
            'GET',
            '/api/vehicle-models/'.self::NAME,
            server: ['HTTP_IF_NONE_MATCH' => '"not-the-current-etag"'],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(self::BODY, (string) $this->client->getResponse()->getContent());
    }

    /** A shared cache must not hold a file served behind a session. */
    public function testTheModelIsKeptPrivatelyAndTreatedAsImmutable(): void
    {
        $this->signInWith([Permission::ViewVehicles]);

        $this->client->request('GET', '/api/vehicle-models/'.self::NAME);

        $control = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $control);
        self::assertStringNotContainsString('public', $control);
        self::assertStringContainsString('immutable', $control);
        self::assertStringContainsString('max-age=31536000', $control);
    }

    /** Rewriting the file under the same name changes what the name means. */
    public function testDifferentContentUnderTheSameNameGetsADifferentEtag(): void
    {
        $this->signInWith([Permission::ViewVehicles]);

        $this->client->request('GET', '/api/vehicle-models/'.self::NAME);
        $first = (string) $this->client->getResponse()->getEtag();

        $this->models->write(self::NAME, self::BODY.'mesh Second');
        $this->client->request('GET', '/api/vehicle-models/'.self::NAME);

        self::assertResponseIsSuccessful();
        self::assertNotSame($first, (string) $this->client->getResponse()->getEtag());
    }

    public function testARoleWithoutTheVehiclePermissionIsRefused(): void
    {
        $this->signInWith([Permission::ViewPlayers]);

        $this->client->request('GET', '/api/vehicle-models/'.self::NAME);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** A conditional request must not become a way past the guard. */
    public function testAConditionalRequestWithoutThePermissionIsRefusedToo(): void
    {
        $this->signInWith([Permission::ViewVehicles]);
        $this->client->request('GET', '/api/vehicle-models/'.self::NAME);
        $etag = (string) $this->client->getResponse()->getEtag();

        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Role')->execute();
        $this->signInWith([Permission::ViewPlayers], 'other@example.com');

        $this->client->request(
            'GET',
            '/api/vehicle-models/'.self::NAME,
            server: ['HTTP_IF_NONE_MATCH' => $etag],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAMissingModelIsStillReportedAsNotFound(): void
    {
        $this->signInWith([Permission::ViewVehicles]);

        $this->client->request('GET', '/api/vehicle-models/zzz-there-is-no-such-model.fbx');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<Permission> $permissions */
    private function signInWith(array $permissions, string $email = 'viewer@example.com'): void
    {
        $role = new Role('vehicle-viewer-'.md5($email), 'Vehicle viewer', $permissions);
        $this->em->persist($role);

        $user = new User($email, 'Test User');
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
            content: json_encode(['email' => $email, 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        );
    }
}
