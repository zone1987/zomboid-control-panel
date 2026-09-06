<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Panel\PanelUpdateChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/panel')]
#[IsGranted('ROLE_USER')]
final class PanelUpdateEndpoint extends AbstractController
{
    public function __construct(private readonly PanelUpdateChecker $updates)
    {
    }

    #[Route('/version', name: 'api_panel_version', methods: ['GET'])]
    public function version(): JsonResponse
    {
        return new JsonResponse($this->updates->status());
    }
}
