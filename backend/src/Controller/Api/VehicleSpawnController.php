<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Players\ModerationRecorder;
use App\Server\Events\EventDispatcher;
use App\Server\Rcon\RconException;
use App\Server\Vehicles\SpawnableVehicles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{serverId}/vehicles')]
#[IsGranted(Permission::TriggerEvents->value)]
final class VehicleSpawnController extends AbstractController
{
    /** Recorded on the moderation log, and the outcome's own label. */
    private const ACTION = 'spawnVehicle';

    /** Mods name their vehicles freely; only the shape is constrained. */
    private const NAME_PATTERN = '/^[A-Za-z0-9.-]*[A-Za-z][A-Za-z0-9_.-]*$/';

    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly SpawnableVehicles $catalogue,
        private readonly EventDispatcher $dispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly ModerationRecorder $recorder,
    ) {
    }

    #[Route('', name: 'api_vehicles_list', methods: ['GET'])]
    public function list(string $serverId, Request $request): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        return new JsonResponse($this->catalogue->forServer(
            $server,
            $request->query->getBoolean('refresh'),
        ));
    }

    #[Route('/spawn', name: 'api_vehicles_spawn', methods: ['POST'])]
    public function spawn(string $serverId, Request $request): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $payload = $request->toArray();
        $script = \is_string($payload['script'] ?? null) ? trim($payload['script']) : '';
        $player = \is_string($payload['player'] ?? null) ? trim($payload['player']) : '';

        if ($script === '' || preg_match(self::NAME_PATTERN, $script) !== 1) {
            return $this->refuse('vehicles.invalidScript');
        }

        if ($player === '') {
            return $this->refuse('vehicles.playerRequired');
        }

        try {
            // The command is built here rather than through the event
            // catalogue: spawning is picking a thing and giving it to
            // somebody, not triggering an event. NAME_PATTERN above admits
            // no quote, which is what keeps the arguments from breaking out.
            $outcome = $this->dispatcher->run(
                $server,
                self::ACTION,
                sprintf('addvehicle "%s" "%s"', $script, EventDispatcher::clean($player)),
            );
        } catch (RconException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Recorded whether or not the server accepted it: an attempt is
        // still something somebody did.
        $this->recorder->record(
            $server,
            ModerationAction::EVENT,
            self::ACTION,
            $this->getUser(),
            sprintf('%s -> %s', $script, $player),
            $outcome->reply,
        );

        return new JsonResponse([
            'status' => 'sent',
            ...$outcome->toArray(),
        ]);
    }

    private function refuse(string $error): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'error' => $error],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'error' => 'errors.notFound'],
            Response::HTTP_NOT_FOUND,
        );
    }
}
