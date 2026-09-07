<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscordCommandRight;
use App\Server\Discord\CommandRights;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscordCommandRight>
 */
final class DiscordCommandRightRepository extends ServiceEntityRepository implements CommandRights
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscordCommandRight::class);
    }

    /**
     * The rights for one subcommand, if any were ever set.
     *
     * Absent means nobody: a command whose rights were never configured
     * must not be open to the whole guild.
     */
    public function forCommand(string $serverId, string $command): ?DiscordCommandRight
    {
        return $this->findOneBy(['server' => $serverId, 'command' => $command]);
    }

    /** @return array<string, DiscordCommandRight> keyed by command */
    public function forServer(string $serverId): array
    {
        $found = [];

        foreach ($this->findBy(['server' => $serverId]) as $right) {
            $found[$right->getCommand()] = $right;
        }

        return $found;
    }
}
