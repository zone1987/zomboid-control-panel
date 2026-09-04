<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameServer;
use App\Entity\PlayerSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerSnapshot>
 */
class PlayerSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerSnapshot::class);
    }

    /** @return list<PlayerSnapshot> */
    public function findForServer(GameServer $server, bool $onlineOnly = false): array
    {
        $builder = $this->createQueryBuilder('p')
            ->andWhere('p.server = :server')
            ->setParameter('server', $server)
            ->orderBy('p.online', 'DESC')
            ->addOrderBy('p.username', 'ASC');

        if ($onlineOnly) {
            $builder->andWhere('p.online = true');
        }

        return $builder->getQuery()->getResult();
    }

    public function findOneForServer(GameServer $server, string $username): ?PlayerSnapshot
    {
        return $this->findOneBy(['server' => $server, 'username' => $username]);
    }

    /**
     * @param list<string> $stillOnline
     */
    public function markEveryoneElseOffline(GameServer $server, array $stillOnline): void
    {
        $builder = $this->createQueryBuilder('p')
            ->update()
            ->set('p.online', ':offline')
            ->setParameter('offline', false)
            ->andWhere('p.server = :server')
            ->andWhere('p.online = true')
            ->setParameter('server', $server);

        if ($stillOnline !== []) {
            $builder->andWhere('p.username NOT IN (:names)')
                ->setParameter('names', $stillOnline);
        }

        $builder->getQuery()->execute();
    }
}
