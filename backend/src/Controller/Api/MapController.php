<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\PlayerSnapshot;
use App\Repository\GameServerRepository;
use App\Repository\PlayerSnapshotRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\ServerInfoReader;
use App\Server\Map\IsometricTiles;
use App\Server\Map\MapTileStore;
use App\Server\Players\BridgeStatusReader;
use App\Server\Players\BridgeUnavailable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/map')]
#[IsGranted('ROLE_USER')]
final class MapController extends AbstractController
{
    public function __construct(
        private readonly MapTileStore $tiles,
        private readonly GameServerRepository $servers,
        private readonly BridgeStatusReader $bridge,
        private readonly PlayerSnapshotRepository $snapshots,
        private readonly ServerInfoReader $info,
        private readonly IsometricTiles $isometric,
    ) {
    }

    #[Route('', name: 'api_map_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse([
            ...$this->tiles->describe(),
            // The isometric render, when the operator has made one. The
            // game ships no such tiles, so this is usually absent.
            'isometric' => $this->isometric->describe(),
        ]);
    }

    /**
     * A file out of the isometric render: a .dzi, or one of its tiles.
     *
     * Served through the panel rather than from a web root of its own,
     * so a render inherits the panel's authentication instead of being
     * readable by anyone who finds the URL.
     */
    #[Route(
        '/isometric/{path}',
        name: 'api_map_isometric',
        methods: ['GET'],
        requirements: ['path' => '.+'],
    )]
    public function isometric(string $path, Request $request): Response
    {
        $file = $this->isometric->resolve($path);

        if ($file === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($file);
        $response->setPublic();
        $response->setMaxAge(604800);
        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }

    #[Route(
        '/tiles/{level}/{column}/{row}.png',
        name: 'api_map_tile',
        methods: ['GET'],
        requirements: ['level' => '\d{1,2}', 'column' => '\d{1,4}', 'row' => '\d{1,4}'],
    )]
    public function tile(int $level, int $column, int $row, Request $request): Response
    {
        $png = $this->tiles->tile($level, $column, $row);

        if ($png === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($png, Response::HTTP_OK, ['Content-Type' => 'image/png']);

        // The world only changes when the game is updated, and then the
        // operator imports it again under the same names.
        $response->setEtag(md5($png));
        $response->setPublic();
        $response->setMaxAge(604800);
        $response->isNotModified($request);

        return $response;
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
