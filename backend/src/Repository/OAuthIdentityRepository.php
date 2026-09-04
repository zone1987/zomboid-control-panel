<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthIdentity>
 */
class OAuthIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthIdentity::class);
    }
}
