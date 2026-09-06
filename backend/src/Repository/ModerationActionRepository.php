<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModerationAction>
 */
class ModerationActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationAction::class);
    }

    /** @return list<ModerationAction> */
    public function history(GameServer $server, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.server = :server')
            ->setParameter('server', $server)
            ->orderBy('a.performedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * What was done to one player, newest first.
     *
     * The dossier's own log. The reference panel shows every player's
     * activity inside a view already scoped to one, and then needs a
     * second search box to make that usable — this is the same data
     * asked the right question instead.
     *
     * `idx_server_username` already exists, so this is one indexed
     * lookup rather than a filter over the whole history.
     *
     * @return list<ModerationAction>
     */
    public function forPlayer(GameServer $server, string $username, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.server = :server')
            ->andWhere('a.username = :username')
            ->setParameter('server', $server)
            ->setParameter('username', $username)
            ->orderBy('a.performedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Temporary bans whose time is up and that nobody has lifted yet.
     *
     * @return list<ModerationAction>
     */
    public function expiredTemporaryBans(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.action = :ban')
            ->andWhere('a.expiresAt IS NOT NULL')
            ->andWhere('a.liftedAt IS NULL')
            ->andWhere('a.expiresAt <= :now')
            ->setParameter('ban', ModerationAction::BAN)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Names currently believed to be banned: those with a ban not followed
     * by an unban. The game server holds the truth, but it offers no way to
     * ask, so this is the best available answer.
     *
     * @return list<array{username: string, reason: string|null, bannedAt: \DateTimeImmutable, expiresAt: \DateTimeImmutable|null}>
     */
    public function activeBans(GameServer $server): array
    {
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.server = :server')
            ->andWhere('a.action IN (:actions)')
            ->setParameter('server', $server)
            ->setParameter('actions', [ModerationAction::BAN, ModerationAction::UNBAN])
            ->orderBy('a.performedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $state = [];

        foreach ($rows as $row) {
            \assert($row instanceof ModerationAction);

            if ($row->getAction() === ModerationAction::BAN) {
                $state[$row->getUsername()] = [
                    'username' => $row->getUsername(),
                    'reason' => $row->getReason(),
                    'bannedAt' => $row->getPerformedAt(),
                    'expiresAt' => $row->getExpiresAt(),
                ];
            } else {
                unset($state[$row->getUsername()]);
            }
        }

        return array_values($state);
    }
}
