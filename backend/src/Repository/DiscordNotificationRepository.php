<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordNotification;
use App\Server\Discord\NotificationSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscordNotification>
 */
final class DiscordNotificationRepository extends ServiceEntityRepository implements NotificationSettings
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscordNotification::class);
    }

    /**
     * The setting for one server and event type, if there is one.
     *
     * Absent means never configured, which means off — a missing row is
     * not a reason to announce something.
     */
    public function forEvent(string $serverId, string $eventType): ?DiscordNotification
    {
        return $this->createQueryBuilder('n')
            ->andWhere('IDENTITY(n.server) = :server')
            ->andWhere('n.eventType = :type')
            ->setParameter('server', $serverId)
            ->setParameter('type', $eventType)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every setting for one server, keyed by event type.
     *
     * @return array<string, DiscordNotification>
     */
    public function forServer(string $serverId): array
    {
        $found = [];

        foreach ($this->findBy(['server' => $serverId]) as $notification) {
            $found[$notification->getEventType()] = $notification;
        }

        return $found;
    }
}
