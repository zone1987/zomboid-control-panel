<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\BridgeInstaller;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{id}/bridge')]
#[IsGranted(Permission::ManageBridge->value)]
final class BridgeStatusEndpoint extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly BridgeInstaller $installer,
    ) {
    }

    #[Route('', name: 'api_servers_bridge_status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'errors.notFound'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse($this->installer->status($server));
    }
}
