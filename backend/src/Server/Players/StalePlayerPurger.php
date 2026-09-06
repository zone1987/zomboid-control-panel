<?php

declare(strict_types=1);

namespace App\Server\Players;

use App\Entity\AppSetting;
use App\Repository\PlayerSnapshotRepository;
use App\Settings\SettingsProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes the snapshots of players nobody has seen for the configured
 * number of days.
 *
 * Bans live in ModerationAction and are never touched here: a name can be
 * changed, so a ban has to outlive any retention window.
 */
final readonly class StalePlayerPurger
{
    public function __construct(
        private PlayerSnapshotRepository $snapshots,
        private SettingsProvider $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{purged: int, cutoff: ?\DateTimeImmutable}
     */
    public function run(bool $dryRun = false): array
    {
        $cutoff = $this->cutoff();

        if ($cutoff === null) {
            return ['purged' => 0, 'cutoff' => null];
        }

        if ($dryRun) {
            return ['purged' => $this->snapshots->countLastSeenBefore($cutoff), 'cutoff' => $cutoff];
        }

        $purged = $this->snapshots->deleteLastSeenBefore($cutoff);

        if ($purged > 0) {
            $this->logger->info('Purged stale player snapshots.', [
                'purged' => $purged,
                'cutoff' => $cutoff->format(\DATE_ATOM),
            ]);
        }

        return ['purged' => $purged, 'cutoff' => $cutoff];
    }

    /** Null when retention is off, which is the default. */
    public function cutoff(): ?\DateTimeImmutable
    {
        $days = $this->retentionDays();

        if ($days === null) {
            return null;
        }

        return $this->clock->now()->sub(new \DateInterval('P'.$days.'D'));
    }

    public function retentionDays(): ?int
    {
        $configured = $this->settings->get(AppSetting::PLAYER_RETENTION_DAYS);

        if ($configured === null || !ctype_digit($configured)) {
            return null;
        }

        $days = (int) $configured;

        return $days > 0 ? $days : null;
    }
}
