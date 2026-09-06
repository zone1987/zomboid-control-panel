<?php

declare(strict_types=1);

namespace App\Server\Players;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an administrative action is written down.
 *
 * There were nine `new ModerationAction(...)` literals across eight
 * files, each correct and each repeating the same persist-and-flush. The
 * dossier adds several more, and a notification feed will later want to
 * see every one of them go past — so the seam is worth having before the
 * count doubles rather than after.
 *
 * Two shapes on purpose. `record()` writes one action and flushes,
 * which is what a controller wants after acting. `add()` only queues,
 * because the batch callers -- the expired-ban lifter and the roster
 * watcher -- flush once at the end: turning their single transaction
 * into one per row would be a quiet regression nobody would notice
 * until a busy server slowed down.
 */
final readonly class ModerationRecorder
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Writes one action down and returns it.
     *
     * Called after the act, whether the server liked it or not: an
     * attempt that was refused is still something somebody did, and a
     * log holding only the successes cannot answer "who tried".
     */
    public function record(
        GameServer $server,
        string $action,
        string $username,
        ?User $performedBy,
        ?string $reason = null,
        ?string $reply = null,
        ?\DateTimeImmutable $expiresAt = null,
        /** What was asked for, so the log can say "rain at 70". @var array<string, scalar>|null */
        ?array $inputs = null,
        /** True when the server refused it; null when nobody checked. */
        ?bool $failed = null,
    ): ModerationAction {
        $entry = new ModerationAction(
            $server,
            $action,
            $username,
            $performedBy,
            $reason,
            $reply,
            $expiresAt,
            $inputs,
            $failed,
        );

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /**
     * Queues one action without flushing, for a caller writing several.
     *
     * @see record() for the single-action form
     */
    public function add(
        GameServer $server,
        string $action,
        string $username,
        ?User $performedBy,
        ?string $reason = null,
        ?string $reply = null,
        ?\DateTimeImmutable $expiresAt = null,
        /** What was asked for, so the log can say "rain at 70". @var array<string, scalar>|null */
        ?array $inputs = null,
        /** True when the server refused it; null when nobody checked. */
        ?bool $failed = null,
    ): ModerationAction {
        $entry = new ModerationAction(
            $server,
            $action,
            $username,
            $performedBy,
            $reason,
            $reply,
            $expiresAt,
            $inputs,
            $failed,
        );

        $this->entityManager->persist($entry);

        return $entry;
    }
}
