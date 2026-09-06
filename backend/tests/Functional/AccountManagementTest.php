<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountManagementTest extends FunctionalTestCase
{
    private const PASSWORD = 'a-sufficiently-long-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\Invitation')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRefusesTheListToSomeoneWhoIsNotAnAdministrator(): void
    {
        $this->createUser('member@example.com', [User::ROLE_SERVER_ADMIN]);
        $this->signIn('member@example.com');

        $this->request('GET', '/api/accounts');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testListsEveryAccountAndMarksTheOneSigningIn(): void
    {
        $this->signInAsAdmin();
        $this->createUser('other@example.com', [User::ROLE_USER]);

        $this->request('GET', '/api/accounts');

        self::assertResponseIsSuccessful();
        $items = $this->payload()['items'];
        self::assertCount(2, $items);

        $self = array_values(array_filter($items, static fn (array $i): bool => $i['self'] === true));
        self::assertCount(1, $self);
        self::assertSame('admin@example.com', $self[0]['email']);
    }

    public function testNeverExposesAPasswordHash(): void
    {
        $this->signInAsAdmin();

        $this->request('GET', '/api/accounts');

        $encoded = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('$2y$', $encoded);
        self::assertStringNotContainsString('password', $encoded);
    }

    public function testChangesTheDisplayNameAndEmailOfAnotherAccount(): void
    {
        $this->signInAsAdmin();
        $user = $this->createUser('old@example.com', [User::ROLE_USER]);

        $this->request('PATCH', '/api/accounts/'.$user->getId()->toRfc4122(), [
            'displayName' => 'Renamed',
            'email' => 'New@Example.com',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Renamed', $this->payload()['displayName']);
        self::assertSame('new@example.com', $this->payload()['email']);
    }

    public function testRejectsAnInvalidEmail(): void
    {
        $this->signInAsAdmin();
        $user = $this->createUser('member@example.com', [User::ROLE_USER]);

        $this->request('PATCH', '/api/accounts/'.$user->getId()->toRfc4122(), ['email' => 'not-an-address']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation.emailInvalid', $this->payload()['errors']['email']);
    }

    public function testGrantsAndRemovesRoles(): void
    {
        $this->signInAsAdmin();
        $user = $this->createUser('member@example.com', [User::ROLE_USER]);

        $this->request('PATCH', '/api/accounts/'.$user->getId()->toRfc4122(), [
            'roles' => [User::ROLE_SERVER_ADMIN],
        ]);

        self::assertResponseIsSuccessful();
        self::assertContains(User::ROLE_SERVER_ADMIN, $this->payload()['roles']);
    }

    public function testIgnoresARoleThatIsNotAssignable(): void
    {
        $this->signInAsAdmin();
        $user = $this->createUser('member@example.com', [User::ROLE_USER]);

        $this->request('PATCH', '/api/accounts/'.$user->getId()->toRfc4122(), [
            'roles' => ['ROLE_SUPER_SECRET', User::ROLE_USER],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([User::ROLE_USER], $this->payload()['roles']);
    }

    public function testRefusesToTakeTheAdministratorRoleFromTheAccountSigningIn(): void
    {
        $admin = $this->createUser('admin@example.com', [User::ROLE_ADMIN]);
        $this->createUser('second-admin@example.com', [User::ROLE_ADMIN]);
        $this->signIn('admin@example.com');

        $this->request('PATCH', '/api/accounts/'.$admin->getId()->toRfc4122(), [
            'roles' => [User::ROLE_USER],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('users.cannotDemoteSelf', $this->payload()['error']);
    }

    public function testRefusesToDeactivateTheAccountSigningIn(): void
    {
        $admin = $this->createUser('admin@example.com', [User::ROLE_ADMIN]);
        $this->createUser('second-admin@example.com', [User::ROLE_ADMIN]);
        $this->signIn('admin@example.com');

        $this->request('PATCH', '/api/accounts/'.$admin->getId()->toRfc4122(), ['active' => false]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('users.cannotDeactivateSelf', $this->payload()['error']);
    }

    public function testRefusesToDeleteTheAccountSigningIn(): void
    {
        $admin = $this->createUser('admin@example.com', [User::ROLE_ADMIN]);
        $this->createUser('second-admin@example.com', [User::ROLE_ADMIN]);
        $this->signIn('admin@example.com');

        $this->request('DELETE', '/api/accounts/'.$admin->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('users.cannotDeleteSelf', $this->payload()['error']);
    }

    /**
     * Without this rule an installation can end up with nobody able to
     * manage it, and no way back in through the interface.
     */
    public function testRefusesToDeleteTheOnlyRemainingAdministrator(): void
    {
        $this->signInAsAdmin();
        $second = $this->createUser('second-admin@example.com', [User::ROLE_ADMIN]);

        // The account signing in is the other administrator, so removing
        // this one leaves exactly one — allowed.
        $this->request('DELETE', '/api/accounts/'.$second->getId()->toRfc4122());
        self::assertResponseIsSuccessful();

        // Now only the signed-in administrator is left. Deactivating any
        // administrator at this point would empty the role.
        $this->request('PATCH', '/api/accounts/'.$this->userId('admin@example.com'), ['active' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testRefusesToDemoteTheOnlyRemainingAdministrator(): void
    {
        $admin = $this->createUser('admin@example.com', [User::ROLE_ADMIN]);
        $this->signIn('admin@example.com');
        $second = $this->createUser('second-admin@example.com', [User::ROLE_ADMIN]);

        $this->request('PATCH', '/api/accounts/'.$second->getId()->toRfc4122(), [
            'roles' => [User::ROLE_USER],
        ]);
        self::assertResponseIsSuccessful();

        // One administrator is left, and it is the one signing in — the
        // self rule catches it before the last-administrator rule does.
        $this->request('PATCH', '/api/accounts/'.$admin->getId()->toRfc4122(), [
            'roles' => [User::ROLE_USER],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testDeletesAnotherAccount(): void
    {
        $this->signInAsAdmin();
        $user = $this->createUser('gone@example.com', [User::ROLE_USER]);
        $id = $user->getId()->toRfc4122();

        $this->request('DELETE', '/api/accounts/'.$id);

        self::assertResponseIsSuccessful();

        $this->request('GET', '/api/accounts');
        self::assertNotContains('gone@example.com', array_column($this->payload()['items'], 'email'));
    }

    public function testSetsAPasswordSoALockedOutAccountCanSignInAgain(): void
    {
        $this->signInAsAdmin();
        $user = $this->createUser('locked@example.com', [User::ROLE_USER]);

        $this->request('PATCH', '/api/accounts/'.$user->getId()->toRfc4122(), [
            'password' => 'a-brand-new-long-password',
        ]);
        self::assertResponseIsSuccessful();

        $this->request('POST', '/api/logout');
        $this->request('POST', '/api/login', [
            'email' => 'locked@example.com',
            'password' => 'a-brand-new-long-password',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testRejectsAPasswordThatIsTooShort(): void
    {
        $this->signInAsAdmin();
        $user = $this->createUser('member@example.com', [User::ROLE_USER]);

        $this->request('PATCH', '/api/accounts/'.$user->getId()->toRfc4122(), ['password' => 'short']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnswersNotFoundForAnIdThatIsNotAUuid(): void
    {
        $this->signInAsAdmin();

        $this->request('PATCH', '/api/accounts/not-a-uuid', ['displayName' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function userId(string $email): string
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user->getId()->toRfc4122();
    }

    private function signInAsAdmin(): void
    {
        $this->createUser('admin@example.com', [User::ROLE_ADMIN]);
        $this->signIn('admin@example.com');
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existing instanceof User) {
            return $existing;
        }

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
