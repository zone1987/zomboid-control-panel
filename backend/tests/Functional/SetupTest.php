<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SetupTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testStatusReportsIncompleteWhileNoAccountExists(): void
    {
        $this->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['setupComplete']);
    }

    public function testCreatesTheFirstAdministrator(): void
    {
        $this->request('POST', '/api/setup', [
            'email' => 'admin@example.com',
            'displayName' => 'First Admin',
            'password' => 'a-sufficiently-long-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'admin@example.com']);

        self::assertNotNull($user);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
        self::assertContains(User::ROLE_SERVER_ADMIN, $user->getRoles());
        self::assertNotSame('a-sufficiently-long-password', $user->getPassword());
    }

    public function testStatusReportsCompleteAfterTheFirstAccount(): void
    {
        $this->createAdmin();

        $this->request('GET', '/api/setup/status');

        self::assertTrue($this->payload()['setupComplete']);
    }

    public function testRefusesASecondAccountThroughTheWizard(): void
    {
        $this->createAdmin();

        $this->request('POST', '/api/setup', [
            'email' => 'intruder@example.com',
            'displayName' => 'Intruder',
            'password' => 'another-long-enough-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('setup.alreadyCompleted', $this->payload()['error']);
    }

    public function testRejectsAShortPassword(): void
    {
        $this->request('POST', '/api/setup', [
            'email' => 'admin@example.com',
            'displayName' => 'First Admin',
            'password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey('password', $this->payload()['errors']);
    }

    public function testRejectsAnInvalidEmail(): void
    {
        $this->request('POST', '/api/setup', [
            'email' => 'not-an-address',
            'displayName' => 'First Admin',
            'password' => 'a-sufficiently-long-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey('email', $this->payload()['errors']);
    }

    private function createAdmin(): void
    {
        $this->request('POST', '/api/setup', [
            'email' => 'admin@example.com',
            'displayName' => 'First Admin',
            'password' => 'a-sufficiently-long-password',
        ]);
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
