<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Security\Permission\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    /** @return list<Role> */
    public function ordered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.builtIn', 'DESC')
            ->addOrderBy('r.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function byName(string $name): ?Role
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Creates the three built-in roles if they are missing.
     *
     * Called on demand rather than in a migration: a migration cannot
     * know what the permission catalogue looks like at the time it runs,
     * and a new permission has to land in the administrator role.
     */
    public function ensureBuiltIns(): void
    {
        $manager = $this->getEntityManager();
        $created = false;

        foreach (self::builtInDefinitions() as $name => [$label, $permissions]) {
            if ($this->byName($name) !== null) {
                continue;
            }

            $manager->persist(new Role($name, $label, $permissions, builtIn: true));
            $created = true;
        }

        if ($created) {
            $manager->flush();
        }
    }

    /** @return array<string, array{0: string, 1: list<Permission>}> */
    public static function builtInDefinitions(): array
    {
        return [
            Role::BUILT_IN_ADMIN => ['Administrator', Permission::cases()],
            Role::BUILT_IN_SERVER_ADMIN => ['Server administrator', [
                Permission::ViewPlayers,
                Permission::KickPlayers,
                Permission::BanPlayers,
                Permission::TeleportPlayers,
                Permission::SetAccessLevel,
                Permission::GiveItems,
                Permission::ViewLog,
                Permission::ReadChat,
                Permission::SendChat,
                Permission::UseConsole,
                Permission::TriggerEvents,
                Permission::ViewServers,
                Permission::EditServers,
                Permission::ManageBridge,
            ]],
            Role::BUILT_IN_MEMBER => ['Member', []],
        ];
    }
}
