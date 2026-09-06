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
use App\Server\Vehicles\VehicleOverlay;
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
        private readonly VehicleOverlay $vehicles,
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
            // The vehicle database is read over FTP and does not need
            // the bridge, so an unreachable bridge still leaves a map
            // with vehicles on it.
            $vehicles = $this->vehiclesFor($server);

            return new JsonResponse([
                'players' => [],
                'safehouses' => [],
                'vehicles' => $vehicles['items'],
                'vehicleSource' => $vehicles['source'],
                'factions' => [],
                'error' => $exception->messageKey(),
            ]);
        }

        $vehicles = $this->vehiclesFor($server);

        // Every layer the panel can draw. The interface decides which
        // ones to show; the answer carries them all, because they come
        // from files the bridge has already written.
        return new JsonResponse([
            'players' => $this->playersOf($server),
            'safehouses' => $this->info->safehouses($server) ?? [],
            'vehicles' => $vehicles['items'],
            'vehicleSource' => $vehicles['source'],
            'factions' => $this->info->factions($server) ?? [],
            'error' => null,
        ]);
    }

    /**
     * Vehicles are a layer of their own, so a role can be given the
     * player roster without the whereabouts of every car.
     *
     * @return array{items: list<array<string, mixed>>, source: string, loaded: int}
     */
    private function vehiclesFor(GameServer $server): array
    {
        if (!$this->isGranted(Permission::ViewVehicles->value)) {
            return ['items' => [], 'source' => 'none', 'loaded' => 0];
        }

        return $this->vehicles->of($server);
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
