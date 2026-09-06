<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\BridgeCommand;
use App\Server\Bridge\BridgeCommandFailed;
use App\Server\Bridge\BridgeCommandSender;
use App\Server\Bridge\InvalidBridgeCommand;
use App\Server\Bridge\UtilityReading;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Whether the power and the water are still on.
 *
 * A read of its own rather than part of the climate: the utilities live
 * in the sandbox options, not the climate manager, and the world page
 * needs them while the climate page does not.
 *
 * Viewing needs `ViewServers`; switching one is an event and goes
 * through the event endpoint with `TriggerEvents`. Somebody allowed to
 * look at a server may see whether its water runs without being allowed
 * to cut it off.
 */
#[Route('/api/servers/{id}/utilities')]
#[IsGranted(Permission::ViewServers->value)]
final class UtilityEndpoint extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly BridgeCommandSender $bridge,
    ) {
    }

    #[Route('', name: 'api_servers_utilities', methods: ['GET'])]
    public function read(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'errors.notFound'],
                Response::HTTP_NOT_FOUND,
            );
        }

        try {
            $result = $this->bridge->send($server, BridgeCommand::ReadUtilities);
        } catch (BridgeCommandFailed|InvalidBridgeCommand $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception instanceof BridgeCommandFailed
                    ? $exception->messageKey()
                    : 'events.invalidInput',
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        if (!$result->ok || $result->data === null) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'bridge.refused',
                'detail' => $result->message,
            ], Response::HTTP_BAD_GATEWAY);
        }

        try {
            $reading = UtilityReading::fromBridge($result->data);
        } catch (BridgeCommandFailed $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'ok', ...$reading->toArray()]);
    }
}
