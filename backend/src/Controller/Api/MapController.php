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
use App\Server\Map\TileGeometry;
use App\Server\Map\TileRenderer;
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
        private readonly GameServerRepository $servers,
        private readonly BridgeStatusReader $bridge,
        private readonly PlayerSnapshotRepository $snapshots,
        private readonly ServerInfoReader $info,
        private readonly IsometricTiles $isometric,
        private readonly TileRenderer $renderer,
        private readonly \App\Server\Map\RenderProgress $progress,
        private readonly \Symfony\Component\Messenger\MessageBusInterface $bus,
    ) {
    }

    #[Route('', name: 'api_map_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse([
            'isometric' => $this->isometric->describe(),
        ]);
    }

    /**
     * Renders the cells behind a tile that is not there.
     *
     * Returns null when the path is not a tile, when nothing can render,
     * or when the render produced nothing -- an empty stretch of world
     * has no tile, and asking again will not change that.
     */
    private function renderMissing(string $path): ?string
    {
        if (!$this->renderer->isAvailable()) {
            return null;
        }

        $tile = self::parseTilePath($path);

        if ($tile === null) {
            return null;
        }

        $geometry = $this->isometric->geometry();

        if ($geometry === null) {
            return null;
        }

        $cells = TileGeometry::fromGeometry(
            $geometry,
            $this->isometric->tileSize() ?? 1024,
            $this->isometric->deepestLevel() ?? 22,
        )->cellsUnder($tile['level'], $tile['column'], $tile['row'], $tile['floor']);

        if (!$this->renderer->render($cells)) {
            return null;
        }

        return $this->isometric->resolve($path);
    }

    /**
     * Reads layer<floor>_files/<level>/<column>_<row>.<ext>.
     *
     * @return array{floor: int, level: int, column: int, row: int}|null
     */
    private static function parseTilePath(string $path): ?array
    {
        $matched = preg_match(
            '#^layer(-?\d+)_files/(\d+)/(\d+)_(\d+)\.\w+$#',
            $path,
            $parts,
        );

        return $matched === 1
            ? [
                'floor' => (int) $parts[1],
                'level' => (int) $parts[2],
                'column' => (int) $parts[3],
                'row' => (int) $parts[4],
            ]
            : null;
    }

    /**
     * Fetches the map from a game server.
     *
     * Offered in the interface rather than only on the command line: an
     * operator running a rented server has a browser, not a shell on the
     * machine the panel runs on.
     */
    /** Asks a running render to stop after the batch it is on. */
    #[Route('/render/stop', name: 'api_map_render_stop', methods: ['POST'])]
    #[IsGranted(Permission::EditSettings->value)]
    public function stopRender(): JsonResponse
    {
        if (!$this->progress->isRunning()) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'map.renderNotRunning'],
                Response::HTTP_CONFLICT,
            );
        }

        $this->progress->requestStop();

        return new JsonResponse(['status' => 'stopping']);
    }

    /** Holds a running render where it is, without ending it. */
    #[Route('/render/pause', name: 'api_map_render_pause', methods: ['POST'])]
    #[IsGranted(Permission::EditSettings->value)]
    public function pauseRender(Request $request): JsonResponse
    {
        if (!$this->progress->isRunning()) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'map.renderNotRunning'],
                Response::HTTP_CONFLICT,
            );
        }

        if ($request->getPayload()->getBoolean('resume')) {
            $this->progress->resume();

            return new JsonResponse(['status' => 'running']);
        }

        $this->progress->requestPause();

        return new JsonResponse(['status' => 'paused']);
    }

    /**
     * The render's state, for a client that cannot hold a stream.
     *
     * Vite's dev proxy buffers an event stream until it ends, and a
     * corporate proxy may do the same, so the interface needs an
     * answer that arrives without one.
     */
    #[Route('/render', name: 'api_map_render_state', methods: ['GET'])]
    #[IsGranted(Permission::EditSettings->value)]
    public function renderState(\Doctrine\DBAL\Connection $database): JsonResponse
    {
        $state = $this->progress->read();

        // "Queued" with nobody consuming means no worker is running,
        // which looks identical to a slow start from the outside.
        if (($state['phase'] ?? '') === 'queued') {
            try {
                $state['queueDepth'] = (int) $database->fetchOne('SELECT count(*) FROM messenger_messages');
            } catch (\Throwable) {
                $state['queueDepth'] = null;
            }
        }

        return new JsonResponse($state);
    }

    /**
     * The render's own account of itself, as it happens.
     *
     * A stream rather than polling: an operator watching a three-hour
     * job wants to see the tiles go past, and a request a second for
     * three hours is a lot of requests to answer.
     */
    #[Route('/render/stream', name: 'api_map_render_stream', methods: ['GET'])]
    #[IsGranted(Permission::EditSettings->value)]
    public function renderStream(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function (): void {
            $sent = null;
            // The pool gives a stream 60 seconds; EventSource reconnects
            // on its own, which is cheaper than holding a worker longer.
            $until = time() + 55;

            while (time() < $until) {
                $state = $this->progress->read();
                $encoded = json_encode($state);

                if ($encoded !== $sent) {
                    $sent = $encoded;
                    echo 'data: '.$encoded."\n\n";
                    flush();
                }

                if (\in_array($state['state'] ?? '', [
                    \App\Server\Map\RenderProgress::DONE,
                    \App\Server\Map\RenderProgress::FAILED,
                ], true)) {
                    break;
                }

                // Four times a second: fast enough to read tile names
                // going past, slow enough not to spin a core.
                usleep(250_000);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Starts a world render, unless one is already going.
     *
     * The work is hours long and happens in a worker; this only says
     * whether it was accepted.
     */
    #[Route('/render/{serverId}', name: 'api_map_render', methods: ['POST'], requirements: ['serverId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(Permission::EditSettings->value)]
    public function startRender(string $serverId, Request $request): JsonResponse
    {
        if ($this->progress->isRunning()) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'map.renderAlreadyRunning'],
                Response::HTTP_CONFLICT,
            );
        }

        $readiness = $this->renderer->readiness();

        if (!$readiness['ready']) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $readiness['renderer'] ? 'map.texturesMissing' : 'map.rendererMissing',
                'missingPacks' => $readiness['missingPacks'],
            ], Response::HTTP_CONFLICT);
        }

        $server = $this->servers->find($serverId);

        if ($server === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'servers.notFound'], Response::HTTP_NOT_FOUND);
        }

        $this->progress->write(['state' => \App\Server\Map\RenderProgress::RUNNING, 'phase' => 'queued']);
        // Off by default: tiles are named by position, so a second run
        // overwrites them. Only a tile the new render no longer
        // produces -- where a building was demolished in-game -- would
        // survive, and deleting 1.5 million objects to catch that is
        // the wrong trade.
        $fresh = $request->getPayload()->getBoolean('fresh', false);

        $this->bus->dispatch(new \App\Message\RenderWorld($server->getId(), upload: true, fresh: $fresh));

        return new JsonResponse(['status' => 'started']);
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
            // Not there yet: the deepest zoom level is three quarters of
            // a render, so the panel holds the levels above it and makes
            // this one when somebody looks that closely.
            $file = $this->renderMissing($path);
        }

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
