<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Counted in the database rather than over a loaded collection: a
     * partially hydrated result would let the last administrator delete
     * themselves and lock everyone out.
     */
    public function countActiveAdministrators(): int
    {
        // Native SQL because DQL has no cast: the column is JSON, and the
        // role names are distinctive enough that a substring match cannot
        // collide with another value.
        $connection = $this->getEntityManager()->getConnection();
        $table = $connection->quoteSingleIdentifier($this->getClassMetadata()->getTableName());

        return (int) $connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE active = true AND roles::text LIKE :role', $table),
            ['role' => '%"'.User::ROLE_ADMIN.'"%'],
        );
    }

    /** @return list<User> */
    public function findAllForManagement(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
