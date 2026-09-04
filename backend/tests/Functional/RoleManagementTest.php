<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RoleManagementTest extends WebTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Role')->execute();
    }

    public function testRefusesRoleManagementToSomeoneWhoIsNotAnAdministrator(): void
    {
        $this->createUser('server@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('server@example.com');

        $this->request('GET', '/api/roles');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCreatesTheBuiltInRolesOnFirstRead(): void
    {
        $this->signInAsAdmin();

        $this->request('GET', '/api/roles');

        self::assertResponseIsSuccessful();
        $names = array_column($this->payload()['items'], 'name');
        self::assertContains(Role::BUILT_IN_ADMIN, $names);
        self::assertContains(Role::BUILT_IN_SERVER_ADMIN, $names);
        self::assertContains(Role::BUILT_IN_MEMBER, $names);
    }

    public function testReadingTwiceDoesNotCreateThemTwice(): void
    {
        $this->signInAsAdmin();

        $this->request('GET', '/api/roles');
        $this->request('GET', '/api/roles');

        self::assertCount(3, $this->payload()['items']);
    }

    public function testTheAdministratorRoleHoldsEveryPermission(): void
    {
        $this->signInAsAdmin();

        $this->request('GET', '/api/roles');

        $byName = array_column($this->payload()['items'], null, 'name');
        self::assertCount(
            \count(\App\Security\Permission\Permission::cases()),
            $byName[Role::BUILT_IN_ADMIN]['permissions'],
        );
    }

    /** An operator names the role; the identifier follows from it. */
    public function testDerivesTheIdentifierFromTheName(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/roles', [
            'label' => 'Event Moderator',
            'permissions' => ['players.view', 'players.kick'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('event-moderator', $this->payload()['name']);
        self::assertSame(['players.kick', 'players.view'], $this->payload()['permissions']);
    }

    public function testRefusesARoleWithNoName(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/roles', ['label' => '   ']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRefusesASecondRoleWithTheSameName(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/roles', ['label' => 'Moderator']);
        $this->request('POST', '/api/roles', ['label' => 'Moderator']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testIgnoresAPermissionThatDoesNotExist(): void
    {
        $this->signInAsAdmin();

        $this->request('POST', '/api/roles', [
            'label' => 'Moderator',
            'permissions' => ['players.kick', 'players.vaporise'],
        ]);

        self::assertSame(['players.kick'], $this->payload()['permissions']);
    }

    public function testChangesThePermissionsOfARole(): void
    {
        $this->signInAsAdmin();
        $this->request('POST', '/api/roles', ['label' => 'Moderator', 'permissions' => ['players.kick']]);
        $id = $this->payload()['id'];

        $this->request('PATCH', '/api/roles/'.$id, ['permissions' => ['players.ban']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['players.ban'], $this->payload()['permissions']);
    }

    /** Renaming a role has to carry its identifier along, or the two drift. */
    public function testTheIdentifierFollowsARename(): void
    {
        $this->signInAsAdmin();
        $this->request('POST', '/api/roles', ['label' => 'New role']);
        $id = $this->payload()['id'];

        $this->request('PATCH', '/api/roles/'.$id, ['label' => 'Event Moderator']);

        self::assertSame('event-moderator', $this->payload()['name']);
    }

    /** Code refers to a built-in role by name, so that name stays put. */
    public function testABuiltInRoleKeepsItsIdentifierWhenRenamed(): void
    {
        $this->signInAsAdmin();
        $this->request('GET', '/api/roles');
        $byName = array_column($this->payload()['items'], null, 'name');

        $this->request('PATCH', '/api/roles/'.$byName[Role::BUILT_IN_ADMIN]['id'], [
            'label' => 'Chief of everything',
        ]);

        self::assertSame(Role::BUILT_IN_ADMIN, $this->payload()['name']);
        self::assertSame('Chief of everything', $this->payload()['label']);
    }

    public function testRefusesARenameThatCollidesWithAnotherRole(): void
    {
        $this->signInAsAdmin();
        $this->request('POST', '/api/roles', ['label' => 'Moderator']);
        $this->request('POST', '/api/roles', ['label' => 'Helper']);
        $id = $this->payload()['id'];

        $this->request('PATCH', '/api/roles/'.$id, ['label' => 'Moderator']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRemovesARoleThatIsNotBuiltIn(): void
    {
        $this->signInAsAdmin();
        $this->request('POST', '/api/roles', ['label' => 'Moderator']);
        $id = $this->payload()['id'];

        $this->request('DELETE', '/api/roles/'.$id);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    /**
     * Without this an installation could remove the only way back into
     * its own administration.
     */
    public function testRefusesToRemoveABuiltInRole(): void
    {
        $this->signInAsAdmin();
        $this->request('GET', '/api/roles');
        $byName = array_column($this->payload()['items'], null, 'name');

        $this->request('DELETE', '/api/roles/'.$byName[Role::BUILT_IN_ADMIN]['id']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertNotNull(self::getContainer()->get(RoleRepository::class)->byName(Role::BUILT_IN_ADMIN));
    }

    public function testAssignsARoleToAUserAndReportsThePermissionsTheyHold(): void
    {
        $this->signInAsAdmin();
        $this->request('POST', '/api/roles', ['label' => 'Moderator', 'permissions' => ['players.kick']]);
        $roleId = $this->payload()['id'];

        $subject = $this->createUser('mod@example.com', [User::ROLE_USER]);

        $this->request('PATCH', '/api/accounts/'.$subject->getId()->toRfc4122(), [
            'assignedRoles' => [$roleId],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([$roleId], $this->payload()['assignedRoles']);
        self::assertSame(['players.kick'], $this->payload()['permissions']);
    }

    /** Sending a shorter list has to take the missing ones away. */
    public function testReplacesTheAssignedRolesRatherThanAddingToThem(): void
    {
        $this->signInAsAdmin();
        $this->request('POST', '/api/roles', ['label' => 'Moderator', 'permissions' => ['players.kick']]);
        $first = $this->payload()['id'];
        $this->request('POST', '/api/roles', ['label' => 'Helper', 'permissions' => ['players.view']]);
        $second = $this->payload()['id'];

        $subject = $this->createUser('mod@example.com', [User::ROLE_USER]);
        $url = '/api/accounts/'.$subject->getId()->toRfc4122();

        $this->request('PATCH', $url, ['assignedRoles' => [$first, $second]]);
        self::assertCount(2, $this->payload()['assignedRoles']);

        $this->request('PATCH', $url, ['assignedRoles' => [$second]]);

        self::assertSame([$second], $this->payload()['assignedRoles']);
        self::assertSame(['players.view'], $this->payload()['permissions']);
    }

    public function testIgnoresARoleIdThatDoesNotExist(): void
    {
        $this->signInAsAdmin();
        $subject = $this->createUser('mod@example.com', [User::ROLE_USER]);

        $this->request('PATCH', '/api/accounts/'.$subject->getId()->toRfc4122(), [
            'assignedRoles' => ['01920000-0000-7000-8000-000000000000'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['assignedRoles']);
    }

    public function testGroupsThePermissionsForTheInterfaceAndMarksTheSensitiveOnes(): void
    {
        $this->signInAsAdmin();

        $this->request('GET', '/api/roles');

        $catalogue = $this->payload()['permissions'];
        self::assertArrayHasKey('players', $catalogue);
        self::assertArrayHasKey('administration', $catalogue);

        $servers = array_column($catalogue['servers'], 'sensitive', 'name');
        self::assertTrue($servers['servers.edit']);
        self::assertFalse($servers['servers.view']);
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
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
