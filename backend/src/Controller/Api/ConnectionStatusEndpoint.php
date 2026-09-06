<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\BridgeInstaller;
use App\Server\Bridge\ServerInfoReader;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconException;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The four things that have to be reachable, as four lights.
 *
 * Read-only and safe to poll, which the existing ftp/test and rcon/test
 * endpoints are not: those are POST, record a verification on the entity
 * and flush. A light that writes to the database every few seconds is a
 * light nobody should have built.
 */
#[Route('/api/servers/{id}/connections')]
#[IsGranted(Permission::ViewServers->value)]
final class ConnectionStatusEndpoint extends AbstractController
{
    /**
     * How old the bridge's own timestamp may be and still mean "running".
     *
     * The bridge writes time and weather every ten seconds, so three
     * missed writes is a server that stopped rather than one under load.
     */
    private const RUNNING_WITHIN_SECONDS = 35;

    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly BridgeInstaller $bridge,
        private readonly ServerInfoReader $info,
        private readonly RconClientInterface $rcon,
        private readonly FileBrowserInterface $files,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'api_servers_connections', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'errors.notFound'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse([
            'ftp' => $this->ftp($server),
            'rcon' => $this->rconState($server),
            'bridge' => $this->bridgeState($server),
            'game' => $this->game($server),
        ]);
    }

    /**
     * @return array{state: string, detail: string|null}
     */
    private function ftp(GameServer $server): array
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return self::unconfigured();
        }

        try {
            $this->files->listDirectory($config, '.');
        } catch (StorageException $exception) {
            return ['state' => 'down', 'detail' => $exception->messageKey()];
        }

        return ['state' => 'up', 'detail' => null];
    }

    /** @return array{state: string, detail: string|null} */
    private function rconState(GameServer $server): array
    {
        $config = $server->getRconConfig();

        if ($config === null) {
            return self::unconfigured();
        }

        // A hung port must not hold a worker for the whole request.
        set_time_limit(20);

        try {
            $this->rcon->probe($config);
        } catch (RconException $exception) {
            return ['state' => 'down', 'detail' => $exception->messageKey()];
        }

        return ['state' => 'up', 'detail' => null];
    }

    /** @return array{state: string, detail: string|null, version: string|null} */
    private function bridgeState(GameServer $server): array
    {
        $status = $this->bridge->status($server);

        if ($status['installed'] !== true) {
            return ['state' => 'down', 'detail' => 'bridge.notInstalled', 'version' => null];
        }

        return [
            // Installed but outdated is not "down": it works, it is just
            // behind, and a red light would overstate that.
            'state' => $status['upToDate'] === true ? 'up' : 'stale',
            'detail' => $status['upToDate'] === true ? null : 'bridge.outdated',
            'version' => $status['installedVersion'],
        ];
    }

    /**
     * Whether the game itself is running, which only the bridge can say.
     *
     * RCON answering means the server is up, but not the reverse: without
     * RCON configured there would be nothing to go on. The bridge writes
     * its files from inside the running game, so a fresh timestamp is
     * proof the game is running and nothing else is.
     *
     * @return array{state: string, detail: string|null, secondsAgo: int|null}
     */
    private function game(GameServer $server): array
    {
        $generatedAt = $this->info->serverInfo($server)['generatedAt'] ?? null;

        if (!\is_int($generatedAt)) {
            return ['state' => 'unknown', 'detail' => 'bridge.noReading', 'secondsAgo' => null];
        }

        $age = $this->clock->now()->getTimestamp() - $generatedAt;

        return [
            'state' => $age <= self::RUNNING_WITHIN_SECONDS ? 'up' : 'down',
            'detail' => $age <= self::RUNNING_WITHIN_SECONDS ? null : 'bridge.stale',
            'secondsAgo' => max(0, $age),
        ];
    }

    /** @return array{state: string, detail: string|null} */
    private static function unconfigured(): array
    {
        // Not red: nothing is broken, it was never set up. A light that
        // cannot tell those apart sends somebody hunting for a fault.
        return ['state' => 'unconfigured', 'detail' => null];
    }
}
