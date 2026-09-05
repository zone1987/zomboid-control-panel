<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The route that serves the isometric render.
 *
 * It once called a reader method that had been deleted, which turned
 * every tile request into a 500 and left the map blank with no clue
 * beyond "HTTP 500" in the viewer.
 */
final class MapTileRouteTest extends WebTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        self::getContainer()->get('cache.app')->clear();
    }

    public function testAnswersADescriptorRequestWithoutFailing(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/map/isometric/layer0.dzi');

        self::assertLessThan(
            500,
            $this->client->getResponse()->getStatusCode(),
            'A missing descriptor must be reported, not raise.',
        );
    }

    public function testAnswersATileRequestWithoutFailing(): void
    {
        $this->signIn();

        $this->client->request('GET', '/api/map/isometric/layer0_files/22/3_4.jpg');

        self::assertLessThan(
            500,
            $this->client->getResponse()->getStatusCode(),
            'A missing tile must be reported, not raise.',
        );
    }

    /** Nothing under the render is readable without signing in. */
    public function testRefusesAnAnonymousRequest(): void
    {
        $this->client->request('GET', '/api/map/isometric/layer0.dzi');

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->client->getResponse()->getStatusCode(),
        );
    }

    private function signIn(): void
    {
        $user = new User('admin@example.com', 'Test User');
        $user->setRoles([User::ROLE_SERVER_ADMIN]);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD),
        );

        $this->em->persist($user);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(
                ['email' => 'admin@example.com', 'password' => self::PASSWORD],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
