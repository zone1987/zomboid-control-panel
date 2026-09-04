<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Bridge\ServerInfoReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{id}')]
#[IsGranted('ROLE_SERVER_ADMIN')]
final class ServerInfoEndpoint extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly ServerInfoReader $info,
    ) {
    }

    /** In-game time, weather and capacity, as the bridge last wrote them. */
    #[Route('/world', name: 'api_servers_world', methods: ['GET'])]
    public function world(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        // Null when the server still runs a bridge older than 0.4.0,
        // which writes no such file. Not an error, just nothing to show.
        return new JsonResponse($this->info->serverInfo($server));
    }

    #[Route('/safehouses', name: 'api_servers_safehouses', methods: ['GET'])]
    public function safehouses(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $houses = $this->info->safehouses($server);

        return new JsonResponse([
            'items' => $houses ?? [],
            'available' => $houses !== null,
        ]);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
