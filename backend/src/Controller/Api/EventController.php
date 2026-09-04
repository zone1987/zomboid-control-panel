<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Repository\GameServerRepository;
use App\Repository\ModerationActionRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\BridgeCommand;
use App\Server\Bridge\BridgeCommandFailed;
use App\Server\Bridge\BridgeCommandSender;
use App\Server\Bridge\InvalidBridgeCommand;
use App\Server\Events\EventAction;
use App\Server\Events\EventCatalogue;
use App\Server\Events\EventDispatcher;
use App\Server\Events\EventOutcome;
use App\Server\Events\VehicleScripts;
use App\Server\Rcon\CommandCatalogueProvider;
use App\Server\Rcon\RconException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{serverId}/events')]
#[IsGranted(Permission::TriggerEvents->value)]
final class EventController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly EventDispatcher $events,
        private readonly CommandCatalogueProvider $catalogue,
        private readonly ModerationActionRepository $actions,
        private readonly EntityManagerInterface $entityManager,
        private readonly BridgeCommandSender $bridge,
    ) {
    }

    /**
     * The catalogue, marked with what this particular server can run.
     *
     * A server reports through "help" only the commands the connected
     * account may use, so an action is offered when every command it
     * needs appears there — and greyed out with a reason when it does not.
     */
    #[Route('', name: 'api_events_list', methods: ['GET'])]
    public function list(string $serverId): JsonResponse
    {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $available = null;
        $error = null;

        try {
            $available = array_column($this->catalogue->forServer($server)->commands, 'name');
        } catch (RconException $exception) {
            $error = $exception->messageKey();
        }

        return new JsonResponse([
            'items' => array_map(
                static function (EventAction $action) use ($available): array {
                    $missing = $available === null
                        ? []
                        : array_values(array_diff($action->commands, $available));

                    return [
                        ...$action->toArray(),
                        'available' => $missing === [],
                        'missing' => $missing,
                    ];
                },
                EventCatalogue::all(),
            ),
            'vehicles' => VehicleScripts::NAMES,
            // Null means the command list could not be read; the interface
            // then offers everything rather than hiding it all.
            'commandsKnown' => $available !== null,
            'error' => $error,
        ]);
    }

    #[Route('/{actionId}', name: 'api_events_trigger', methods: ['POST'])]
    public function trigger(string $serverId, string $actionId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        if (EventCatalogue::find($actionId) === null) {
            return $this->notFound();
        }

        $inputs = $this->payloadOf($request);
        $action = EventCatalogue::find($actionId);

        try {
            $outcome = $action?->channel === EventAction::CHANNEL_BRIDGE
                ? $this->throughBridge($server, $actionId, $inputs)
                : $this->events->dispatch($server, $actionId, $inputs);
        } catch (InvalidBridgeCommand $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'events.invalidInput',
                'detail' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (BridgeCommandFailed $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        } catch (RconException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Recorded whether or not the server liked it: an attempt that was
        // refused is still something somebody did.
        $this->entityManager->persist(new ModerationAction(
            $server,
            ModerationAction::EVENT,
            $actionId,
            $actor,
            $outcome->command,
            $outcome->reply,
        ));
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'sent', ...$outcome->toArray()]);
    }

    /**
     * Runs an action through the bridge's command queue.
     *
     * The catalogue's action ids and the bridge's own vocabulary are
     * deliberately separate: the panel offers "set the fog", the bridge
     * knows only a climate value by index.
     *
     * @param array<string, mixed> $inputs
     *
     * @throws BridgeCommandFailed
     * @throws InvalidBridgeCommand
     */
    private function throughBridge(GameServer $server, string $actionId, array $inputs): EventOutcome
    {
        [$command, $arguments] = match ($actionId) {
            'setTime' => [BridgeCommand::SetTime, ['hour' => $inputs['hour'] ?? null]],
            'setDate' => [BridgeCommand::SetDate, [
                'day' => $inputs['day'] ?? null,
                'month' => $inputs['month'] ?? null,
            ]],
            'bridgeStartRain' => [BridgeCommand::StartRain, ['intensity' => $inputs['intensity'] ?? null]],
            'bridgeStopRain' => [BridgeCommand::StopRain, []],
            'soundAtPlayer', 'soundAtPoint' => [BridgeCommand::PlaySound, $inputs],
            default => [BridgeCommand::SetClimateValue, [
                'name' => self::CLIMATE_ACTIONS[$actionId] ?? null,
                // The interface works in percent; the game works in 0..1,
                // except temperature, which is degrees either way.
                'value' => self::climateValue($actionId, $inputs['value'] ?? null),
            ]],
        };

        $result = $this->bridge->send($server, $command, $arguments);

        return new EventOutcome(
            $actionId,
            $command->value,
            $result->message === '' ? ($result->ok ? 'done' : 'refused') : $result->message,
            failed: !$result->ok,
        );
    }

    /** Action id to the climate value it sets. */
    private const CLIMATE_ACTIONS = [
        'setFog' => 'fog',
        'setWind' => 'wind',
        'setTemperature' => 'temperature',
        'setClouds' => 'clouds',
        'setDaylight' => 'daylight',
        'setViewDistance' => 'viewDistance',
    ];

    private static function climateValue(string $actionId, mixed $value): float|int|null
    {
        if (!is_numeric($value)) {
            return null;
        }

        return $actionId === 'setTemperature' ? $value + 0 : ($value + 0) / 100;
    }

    /** What this page has done lately, newest first. */
    #[Route('/recent/actions', name: 'api_events_recent', methods: ['GET'])]
    public function recent(string $serverId): JsonResponse
    {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $entries = array_filter(
            $this->actions->history($server, 200),
            static fn (ModerationAction $a): bool => $a->getAction() === ModerationAction::EVENT,
        );

        return new JsonResponse([
            'items' => array_map(
                static fn (ModerationAction $a): array => [
                    'action' => $a->getUsername(),
                    'command' => $a->getReason(),
                    'reply' => $a->getReply(),
                    'performedBy' => $a->getPerformedBy()?->getDisplayName(),
                    'performedAt' => $a->getPerformedAt()->format(\DateTimeInterface::ATOM),
                ],
                array_slice(array_values($entries), 0, 25),
            ),
        ]);
    }

    private function requireServer(string $serverId): ?GameServer
    {
        return $this->servers->find($serverId);
    }

    /** @return array<string, mixed> */
    private function payloadOf(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
