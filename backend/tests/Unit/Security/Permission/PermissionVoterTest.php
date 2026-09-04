<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Permission;

use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission\Permission;
use App\Security\Permission\PermissionVoter;
use PHPUnit\Framework\TestCase;

/**
 * Permissions and the three legacy role names are both in force while
 * checks move over one at a time, so an installation that predates
 * configurable roles keeps working unchanged.
 */
final class PermissionVoterTest extends TestCase
{
    public function testAnAdministratorKeepsEverythingWithoutAnAssignedRole(): void
    {
        foreach (Permission::cases() as $permission) {
            self::assertTrue(
                PermissionVoter::legacyRoleGrants([User::ROLE_ADMIN], $permission),
                $permission->value,
            );
        }
    }

    /**
     * A server administrator ran servers, not the panel itself -- which
     * is exactly the separation the permission catalogue makes explicit.
     */
    public function testAServerAdministratorKeepsServersButNotAdministration(): void
    {
        $roles = [User::ROLE_SERVER_ADMIN];

        self::assertTrue(PermissionVoter::legacyRoleGrants($roles, Permission::KickPlayers));
        self::assertTrue(PermissionVoter::legacyRoleGrants($roles, Permission::EditServers));
        self::assertTrue(PermissionVoter::legacyRoleGrants($roles, Permission::TriggerEvents));
        self::assertFalse(PermissionVoter::legacyRoleGrants($roles, Permission::ManageUsers));
        self::assertFalse(PermissionVoter::legacyRoleGrants($roles, Permission::EditSettings));
        self::assertFalse(PermissionVoter::legacyRoleGrants($roles, Permission::InviteUsers));
    }

    public function testAPlainMemberGetsNothingFromTheirRoleName(): void
    {
        foreach (Permission::cases() as $permission) {
            self::assertFalse(PermissionVoter::legacyRoleGrants([User::ROLE_USER], $permission));
        }
    }

    public function testAnAssignedRoleGrantsWhatItHolds(): void
    {
        $user = new User('mod@example.com', 'Moderator');
        $user->assignRole(new Role('moderator', 'Moderator', [
            Permission::ViewPlayers,
            Permission::KickPlayers,
        ]));

        self::assertTrue($user->hasPermission(Permission::KickPlayers));
        self::assertFalse($user->hasPermission(Permission::BanPlayers));
        self::assertFalse($user->hasPermission(Permission::EditServers));
    }

    public function testPermissionsFromSeveralRolesAddUpWithoutDuplicates(): void
    {
        $user = new User('mod@example.com', 'Moderator');
        $user->assignRole(new Role('a', 'A', [Permission::ViewPlayers, Permission::KickPlayers]));
        $user->assignRole(new Role('b', 'B', [Permission::ViewPlayers, Permission::BanPlayers]));

        $held = array_map(static fn (Permission $p): string => $p->value, $user->getPermissions());

        sort($held);

        self::assertSame(['players.ban', 'players.kick', 'players.view'], $held);
    }

    public function testTakingARoleAwayTakesItsPermissionsWithIt(): void
    {
        $user = new User('mod@example.com', 'Moderator');
        $role = new Role('moderator', 'Moderator', [Permission::KickPlayers]);
        $user->assignRole($role);
        $user->unassignRole($role);

        self::assertFalse($user->hasPermission(Permission::KickPlayers));
    }

    public function testAssigningTheSameRoleTwiceChangesNothing(): void
    {
        $user = new User('mod@example.com', 'Moderator');
        $role = new Role('moderator', 'Moderator', [Permission::KickPlayers]);

        $user->assignRole($role);
        $user->assignRole($role);

        self::assertCount(1, $user->getAssignedRoles());
    }

    /** Every permission has to appear in the interface exactly once. */
    public function testTheCatalogueCoversEveryPermissionOnce(): void
    {
        $grouped = [];

        foreach (Permission::grouped() as $permissions) {
            foreach ($permissions as $permission) {
                $grouped[] = $permission->value;
            }
        }

        sort($grouped);
        $all = Permission::values();
        sort($all);

        self::assertSame($all, $grouped);
    }

    public function testAnUnknownPermissionNameIsRefused(): void
    {
        self::assertNull(Permission::tryFrom('players.vaporise'));
    }
}
