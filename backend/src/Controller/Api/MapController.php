<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\PlayerSnapshot;
use App\Repository\GameServerRepository;
use App\Repository\PlayerSnapshotRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\ServerInfoReader;
use App\Server\Players\BridgeStatusReader;
use App\Server\Players\BridgeUnavailable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/map')]
#[IsGranted('ROLE_USER')]
final class MapController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly BridgeStatusReader $bridge,
        private readonly PlayerSnapshotRepository $snapshots,
        private readonly ServerInfoReader $info,
    ) {
    }

    /**
     * Everything to draw on top of the map for one server.
     *
     * Read from the bridge files rather than over RCON: positions come
     * from players.json, which the bridge refreshes every three seconds.
     */
    #[Route('/{serverId}/overlay', name: 'api_map_overlay', methods: ['GET'])]
    #[IsGranted(Permission::ViewPlayers->value)]
    public function overlay(string $serverId): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->bridge->refresh($server);
        } catch (BridgeUnavailable $exception) {
            return new JsonResponse([
                'players' => [],
                'safehouses' => [],
                'vehicles' => [],
                'factions' => [],
                'error' => $exception->messageKey(),
            ]);
        }

        // Every layer the panel can draw. The interface decides which
        // ones to show; the answer carries them all, because they come
        // from files the bridge has already written.
        return new JsonResponse([
            'players' => $this->playersOf($server),
            'safehouses' => $this->info->safehouses($server) ?? [],
            'vehicles' => $this->info->vehicles($server) ?? [],
            'factions' => $this->info->factions($server) ?? [],
            'error' => null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function playersOf(GameServer $server): array
    {
        $players = [];

        foreach ($this->snapshots->findForServer($server, onlineOnly: true) as $player) {
            if ($player->getX() === null || $player->getY() === null) {
                continue;
            }

            $players[] = self::present($player);
        }

        return $players;
    }

    /** @return array<string, mixed> */
    private static function present(PlayerSnapshot $player): array
    {
        return [
            'username' => $player->getUsername(),
            'x' => $player->getX(),
            'y' => $player->getY(),
            'z' => $player->getZ(),
            'health' => $player->getHealth(),
            'infected' => $player->isInfected(),
            'accessLevel' => $player->getAccessLevel(),
        ];
    }
}
