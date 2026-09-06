<?php

declare(strict_types=1);

namespace App\Server\Players;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use Doctrine\ORM\EntityManagerInterface;

/** Records who joined and who left, by comparing two rosters. */
final readonly class RosterWatcher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $wasOnline names the snapshots held before the read
     * @param list<string> $isOnline  names the bridge has just reported
     * @param bool         $answered  whether the bridge produced a fresh file
     *
     * @return array{joined: int, left: int}
     */
    public function record(GameServer $server, array $wasOnline, array $isOnline, bool $answered = true): array
    {
        // A bridge that did not answer reports nobody; that is not a departure.
        if (!$answered) {
            return ['joined' => 0, 'left' => 0];
        }

        $before = $this->normalise($wasOnline);
        $after = $this->normalise($isOnline);

        $left = array_diff($before, $after);
        $joined = array_diff($after, $before);

        foreach ($joined as $username) {
            $this->entityManager->persist(
                new ModerationAction($server, ModerationAction::JOIN, $username, null, 'players.joined'),
            );
        }

        foreach ($left as $username) {
            $this->entityManager->persist(
                new ModerationAction($server, ModerationAction::LEAVE, $username, null, 'players.left'),
            );
        }

        if ($joined !== [] || $left !== []) {
            $this->entityManager->flush();
        }

        return ['joined' => \count($joined), 'left' => \count($left)];
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function normalise(array $names): array
    {
        $trimmed = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name !== '') {
                $trimmed[$name] = $name;
            }
        }

        return array_values($trimmed);
    }
}
