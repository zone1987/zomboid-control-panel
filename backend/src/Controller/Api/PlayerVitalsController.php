<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\BridgeCommand;
use App\Server\Bridge\BridgeCommandFailed;
use App\Server\Bridge\BridgeCommandSender;
use App\Server\Bridge\InvalidBridgeCommand;
use App\Server\Players\ModerationRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A character's own condition: the statistics, the weight, and healing.
 *
 * **Every bound comes from the game.** Measured on a live server, the
 * twenty-four statistics run 0..1, 0..100, −1..1, 20..40 and even
 * 0..0.51 — a table in the panel would have been wrong for most of them,
 * and `Stats::set` clamps rather than refusing, so being wrong would
 * apply something else and report success.
 *
 * Reading needs `ViewPlayers`; changing anything needs `KickPlayers`,
 * the same permission as removing somebody. Setting a player's hunger is
 * not a smaller act than kicking them.
 */
#[Route('/api/servers/{serverId}/players/{username}')]
#[IsGranted(Permission::ViewPlayers->value)]
final class PlayerVitalsController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly BridgeCommandSender $bridge,
        private readonly ModerationRecorder $recorder,
    ) {
    }

    #[Route('/vitals', name: 'api_players_vitals_read', methods: ['GET'])]
    public function read(string $serverId, string $username): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        try {
            $result = $this->bridge->send(
                $server,
                BridgeCommand::ReadPlayerStats,
                ['player' => $username],
            );
        } catch (BridgeCommandFailed|InvalidBridgeCommand $exception) {
            return $this->throughTheBridge($exception);
        }

        if (!$result->ok || $result->data === null) {
            // The commonest reason is that the player logged out between
            // the roster and this request, which is worth saying rather
            // than showing as a failure.
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'players.vitalsUnavailable',
                'detail' => $result->message,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'ok', ...$result->data]);
    }

    #[Route('/vitals', name: 'api_players_vitals_write', methods: ['POST'])]
    #[IsGranted(Permission::KickPlayers->value)]
    public function write(
        string $serverId,
        string $username,
        Request $request,
        #[CurrentUser] User $actor,
    ): JsonResponse {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $payload = $this->payloadOf($request);
        $stat = $payload['stat'] ?? null;
        $value = $payload['value'] ?? null;

        if (!\is_string($stat) || !\in_array($stat, BridgeCommand::CHARACTER_STATS, true)) {
            return $this->invalid('stat');
        }

        if (!is_numeric($value)) {
            return $this->invalid('value');
        }

        return $this->act(
            $server,
            $username,
            $actor,
            BridgeCommand::SetPlayerStat,
            ['player' => $username, 'stat' => $stat, 'value' => $value + 0],
            ModerationAction::STATISTIC,
            sprintf('%s=%s', $stat, $value),
        );
    }

    #[Route('/weight', name: 'api_players_weight', methods: ['POST'])]
    #[IsGranted(Permission::KickPlayers->value)]
    public function weight(
        string $serverId,
        string $username,
        Request $request,
        #[CurrentUser] User $actor,
    ): JsonResponse {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $weight = $this->payloadOf($request)['weight'] ?? null;

        if (!is_numeric($weight)) {
            return $this->invalid('weight');
        }

        return $this->act(
            $server,
            $username,
            $actor,
            BridgeCommand::SetPlayerWeight,
            ['player' => $username, 'weight' => $weight + 0],
            ModerationAction::STATISTIC,
            sprintf('weight=%s', $weight),
        );
    }

    /**
     * Restores every body part, which is what the game's own heal does.
     *
     * Not undone by anything: a healed character stays healed, so this
     * is recorded like any other act on a player.
     */
    #[Route('/heal', name: 'api_players_heal', methods: ['POST'])]
    #[IsGranted(Permission::KickPlayers->value)]
    public function heal(
        string $serverId,
        string $username,
        #[CurrentUser] User $actor,
    ): JsonResponse {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        return $this->act(
            $server,
            $username,
            $actor,
            BridgeCommand::HealPlayer,
            ['player' => $username],
            ModerationAction::HEAL,
            null,
        );
    }

    /**
     * One bridge command, recorded whether or not the server liked it.
     *
     * @param array<string, mixed> $arguments
     */
    private function act(
        GameServer $server,
        string $username,
        User $actor,
        BridgeCommand $command,
        array $arguments,
        string $kind,
        ?string $reason,
    ): JsonResponse {
        try {
            $result = $this->bridge->send($server, $command, $arguments);
        } catch (BridgeCommandFailed|InvalidBridgeCommand $exception) {
            return $this->throughTheBridge($exception);
        }

        $this->recorder->record(
            $server,
            $kind,
            $username,
            $actor,
            $reason,
            $result->message,
            inputs: self::scalarsOf($arguments),
            failed: !$result->ok,
        );

        if (!$result->ok) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'players.vitalRefused',
                'detail' => $result->message,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'sent', 'reply' => $result->message, ...($result->data ?? [])]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, scalar>|null
     */
    private static function scalarsOf(array $arguments): ?array
    {
        // The player's name is in the row already; what belongs in the
        // log is what was asked for.
        unset($arguments['player']);

        $kept = array_filter($arguments, static fn (mixed $value): bool => \is_scalar($value));

        return $kept === [] ? null : $kept;
    }

    private function throughTheBridge(\Throwable $exception): JsonResponse
    {
        return new JsonResponse([
            'status' => 'failed',
            'error' => $exception instanceof BridgeCommandFailed
                ? $exception->messageKey()
                : 'events.invalidInput',
            'detail' => $exception->getMessage(),
        ], Response::HTTP_BAD_GATEWAY);
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

    private function invalid(string $field): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'errors' => [$field => 'validation.invalid']],
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
