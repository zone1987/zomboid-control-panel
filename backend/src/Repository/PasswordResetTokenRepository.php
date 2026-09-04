<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetToken>
 */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function removeAllForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
