<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameServer;
use App\Entity\PlayerNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerNote>
 */
class PlayerNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerNote::class);
    }

    public function findFor(GameServer $server, string $username): ?PlayerNote
    {
        return $this->findOneBy(['server' => $server, 'username' => $username]);
    }

    /**
     * The tags in use on this server, most-used first.
     *
     * Offered as suggestions beside the fixed ones, so an operator's own
     * vocabulary comes back to them rather than being retyped — which is
     * how "greifer" and "griefer" end up as two tags.
     *
     * @return list<string>
     */
    public function tagsInUse(GameServer $server, int $limit = 20): array
    {
        $counts = [];

        /** @var list<PlayerNote> $notes */
        $notes = $this->findBy(['server' => $server]);

        foreach ($notes as $note) {
            foreach ($note->getTags() as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        arsort($counts);

        return \array_slice(array_keys($counts), 0, $limit);
    }
}
