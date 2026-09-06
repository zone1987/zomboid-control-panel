<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\BridgeCommand;
use App\Server\Bridge\BridgeCommandFailed;
use App\Server\Bridge\BridgeCommandSender;
use App\Server\Bridge\ClimateReading;
use App\Server\Bridge\InvalidBridgeCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the world's climate is set to, right now.
 *
 * A read of its own rather than an event: the thirteen values are a
 * state to look at before changing anything, and every write already has
 * a home in the event catalogue. Reading needs the bridge — the values
 * live in the running game, not in a file — so this is a command round
 * trip rather than a file read, and it is the one endpoint here that
 * cannot answer without the bridge.
 *
 * Viewing is `ViewServers`; changing anything is `TriggerEvents` on the
 * event endpoint. Somebody allowed to look at a server may see what its
 * weather is set to without being allowed to set it.
 */
#[Route('/api/servers/{id}/climate')]
#[IsGranted(Permission::ViewServers->value)]
final class ClimateEndpoint extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly BridgeCommandSender $bridge,
    ) {
    }

    /**
     * The two colours the game keeps: the global light and the fog.
     *
     * A read of their own rather than part of the climate values,
     * because a colour is four channels for indoors and four for out —
     * a shape nothing else on that page shares.
     */
    #[Route('/colours', name: 'api_servers_climate_colours', methods: ['GET'], priority: 10)]
    public function colours(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'errors.notFound'],
                Response::HTTP_NOT_FOUND,
            );
        }

        try {
            $result = $this->bridge->send($server, BridgeCommand::ReadClimateColours);
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

        return new JsonResponse([
            'status' => 'ok',
            // Handed through as the bridge reported it: the channels are
            // already 0..1 floats, which is what the interface converts
            // to hex and back at its own edge.
            'colours' => $result->data['colours'] ?? [],
        ]);
    }

    #[Route('', name: 'api_servers_climate', methods: ['GET'])]
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
            $result = $this->bridge->send($server, BridgeCommand::ReadClimate);
        } catch (BridgeCommandFailed|InvalidBridgeCommand $exception) {
            // The reason is worth stating: "the bridge is not installed"
            // and "the server is not running" send an operator to
            // different places.
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
            $reading = ClimateReading::fromBridge($result->data);
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
