<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Panel\DeployTrigger;
use App\Panel\PanelUpdateChecker;
use App\Repository\AppSettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/panel')]
#[IsGranted('ROLE_USER')]
final class PanelUpdateEndpoint extends AbstractController
{
    /** Written by the handler that claimed the deployment. */
    private const CLAIM = 'deploy.requested.';

    public function __construct(
        private readonly PanelUpdateChecker $updates,
        private readonly DeployTrigger $deployer,
        private readonly AppSettingRepository $settings,
    ) {
    }

    #[Route('/version', name: 'api_panel_version', methods: ['GET'])]
    public function version(): JsonResponse
    {
        $status = $this->updates->status();

        return new JsonResponse($status + [
            // So the interface can say the panel is updating itself
            // rather than leaving the reader to wonder why it restarted.
            'autoDeploy' => $this->deployer->isEnabled(),
            'deploying' => $this->isBeingDeployed($status['latest']),
            'reloadPanel' => $this->deployer->reloadsThePanel(),
        ]);
    }

    private function isBeingDeployed(?string $latest): bool
    {
        if ($latest === null || !$this->deployer->isEnabled()) {
            return false;
        }

        return $this->settings->claimed(self::CLAIM.$latest);
    }
}
