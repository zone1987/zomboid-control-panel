<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DeployNewRelease;
use App\Panel\DeployTrigger;
use App\Panel\PanelUpdateChecker;
use App\Repository\AppSettingRepository;
use App\Settings\SettingsProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deploys a release the panel has noticed, once.
 *
 * Twice would be the easy mistake, and there are two ways to make it.
 * The check answers the same version every hour until the container
 * actually restarts, so a slow or refusing platform would be asked again
 * and again. And two panels sharing a database both notice the same
 * release in the same minute.
 *
 * A row named after the version answers both: the database lets one
 * caller insert it and refuses the rest.
 */
#[AsMessageHandler]
final readonly class DeployNewReleaseHandler
{
    /** Prefix rather than a bare version, so the row is recognisable. */
    private const CLAIM = 'deploy.requested.';

    /** When the check last actually ran, so an interval can be honoured. */
    private const LAST_CHECK = 'deploy.last_check';

    public function __construct(
        private PanelUpdateChecker $updates,
        private DeployTrigger $deployer,
        private AppSettingRepository $settings,
        private SettingsProvider $store,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeployNewRelease $message): void
    {
        if (!$this->deployer->isEnabled()) {
            return;
        }

        // The scheduler fires at the shortest interval on offer; the
        // operator's choice is honoured here, so changing it takes
        // effect without a restart.
        if (!$this->dueNow()) {
            return;
        }

        $status = $this->updates->status();
        $latest = $status['latest'];

        // `upToDate` is null when the check itself failed, and not
        // knowing is not a reason to restart somebody's panel.
        if ($latest === null || $status['upToDate'] !== false) {
            return;
        }

        // Claimed before the call, not after: a hook that times out may
        // still have started a deployment, and asking twice is worse
        // than waiting for the next release.
        if (!$this->settings->claim(self::CLAIM.$latest)) {
            return;
        }

        $outcome = $this->deployer->fire();

        $this->logger->info('Deployment for {version} requested: {state}', [
            'version' => $latest,
            'state' => $outcome->state,
        ]);
    }

    /** Whether the operator's chosen interval has elapsed. */
    private function dueNow(): bool
    {
        $now = $this->clock->now();
        $last = $this->store->get(self::LAST_CHECK);
        $due = $last === null
            || $now->getTimestamp() - (int) $last >= $this->deployer->checkIntervalMinutes() * 60;

        if ($due) {
            $this->store->set(self::LAST_CHECK, (string) $now->getTimestamp());
        }

        return $due;
    }
}
