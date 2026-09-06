<?php

declare(strict_types=1);

namespace App\Server\Players;

use App\Entity\ModerationAction;
use App\Repository\ModerationActionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifts temporary bans whose time is up.
 *
 * Zomboid bans permanently and offers no duration, so a timed ban only
 * ends because this runs. While the panel is down, bans simply stay in
 * place — inconvenient, but never the other way round.
 */
final readonly class ExpiredBanLifter
{
    public function __construct(
        private ModerationActionRepository $actions,
        private PlayerModerator $moderator,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ModerationRecorder $recorder,
    ) {
    }

    /**
     * @return array{lifted: int, failed: int}
     */
    public function run(): array
    {
        $lifted = 0;
        $failed = 0;

        foreach ($this->actions->expiredTemporaryBans() as $ban) {
            \assert($ban instanceof ModerationAction);

            try {
                $reply = $this->moderator->unban($ban->getServer(), $ban->getUsername());
            } catch (\Throwable $exception) {
                // The server may be down or unreachable; leave the ban
                // marked as pending so the next run tries again.
                ++$failed;

                $this->logger->info('Could not lift an expired ban.', [
                    'username' => $ban->getUsername(),
                    'exception' => $exception,
                ]);

                continue;
            }

            $ban->markLifted();

            $this->recorder->add(
                $ban->getServer(),
                ModerationAction::UNBAN,
                $ban->getUsername(),
                null,
                'banExpired',
                $reply,
            );

            ++$lifted;
        }

        if ($lifted > 0 || $failed > 0) {
            $this->entityManager->flush();
        }

        return ['lifted' => $lifted, 'failed' => $failed];
    }
}
