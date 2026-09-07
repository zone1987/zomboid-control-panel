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
     * What the character sheet shows per skill, beyond the level.
     *
     * The XP inside the current level and the next level's threshold,
     * so a hover can say how much is left; the profession boost the
     * game colours a skill name by (0..3, gold at 3); and the book
     * multiplier, which is above zero only while a read book still
     * applies.
     */
    #[Route('/skills', name: 'api_players_skill_detail', methods: ['GET'])]
    public function skillDetail(string $serverId, string $username): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        try {
            $result = $this->bridge->send(
                $server,
                BridgeCommand::ReadSkillDetail,
                ['player' => $username],
            );
        } catch (BridgeCommandFailed|InvalidBridgeCommand $exception) {
            return $this->throughTheBridge($exception);
        }

        if (!$result->ok || $result->data === null) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'players.skillDetailUnavailable',
                'detail' => $result->message,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['status' => 'ok', ...$result->data]);
    }

    /**
     * Sets one skill to an exact level.
     *
     * Ten clicks on a pip row is the whole interaction, which is why
     * this takes a level rather than a delta: "make it 7" is what the
     * operator means, and a delta would need the current value to be
     * right on both sides at once.
     *
     * Unlike a profession, this genuinely reaches the player:
     * `level0` + `LevelPerk(perk, false)` + `setXPToLevel` all run
     * server-side, and the XP route below is the one the game itself
     * uses to tell a client its skills changed.
     */
    #[Route('/skill', name: 'api_players_skill', methods: ['POST'])]
    #[IsGranted(Permission::KickPlayers->value)]
    public function skill(
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
        $skill = $payload['skill'] ?? null;
        $level = $payload['level'] ?? null;

        if (!\is_string($skill) || !\in_array($skill, BridgeCommand::SKILLS, true)) {
            return $this->invalid('skill');
        }

        if (!is_numeric($level) || $level < 0 || $level > BridgeCommand::MAX_SKILL_LEVEL) {
            return $this->invalid('level');
        }

        return $this->act(
            $server,
            $username,
            $actor,
            BridgeCommand::SetSkillLevel,
            ['player' => $username, 'skill' => $skill, 'level' => (int) $level],
            ModerationAction::SKILL,
            sprintf('%s=%d', $skill, (int) $level),
        );
    }

    /**
     * Adds raw experience to one skill.
     *
     * `addXpNoMultiplier` is the route that reaches the client:
     * `GameServer::addXp` finds the player's connection and calls
     * `NetworkPlayerAI::updateXpChecker`. The multiplier is the
     * operator's choice because a server running one makes "500" mean
     * two different things.
     */
    #[Route('/skill/xp', name: 'api_players_skill_xp', methods: ['POST'])]
    #[IsGranted(Permission::KickPlayers->value)]
    public function skillXp(
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
        $skill = $payload['skill'] ?? null;
        $amount = $payload['amount'] ?? null;

        if (!\is_string($skill) || !\in_array($skill, BridgeCommand::SKILLS, true)) {
            return $this->invalid('skill');
        }

        if (!is_numeric($amount) || $amount < 1 || $amount > BridgeCommand::MAX_SKILL_XP) {
            return $this->invalid('amount');
        }

        return $this->act(
            $server,
            $username,
            $actor,
            BridgeCommand::AddSkillXp,
            [
                'player' => $username,
                'skill' => $skill,
                'amount' => $amount + 0,
                'multiplied' => ($payload['multiplied'] ?? null) === true,
            ],
            ModerationAction::EXPERIENCE,
            sprintf('%s +%s', $skill, $amount),
        );
    }

    /**
     * Adds or removes one character trait.
     *
     * **The change is server-side at once, but the player's own screen
     * does not update until they reconnect** — `SyncXp` is guarded by
     * `GameClient.client` and does nothing on a server, and the one
     * server-side broadcast that exists (`ExtraInfo`) carries roles and
     * cheat flags, not traits. The interface says so rather than
     * implying an immediate effect.
     *
     * Professions are deliberately absent: `setCharacterProfession`
     * exists but nothing carries it to the client, and the game itself
     * only ever calls it client-side. A control that reports success
     * while changing nothing the player can see is worse than none.
     */
    #[Route('/trait', name: 'api_players_trait', methods: ['POST'])]
    #[IsGranted(Permission::KickPlayers->value)]
    public function trait(
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
        $trait = $payload['trait'] ?? null;
        $adding = ($payload['adding'] ?? null) === true;

        if (!\is_string($trait) || $trait === '') {
            return $this->invalid('trait');
        }

        return $this->act(
            $server,
            $username,
            $actor,
            BridgeCommand::SetTrait,
            ['player' => $username, 'trait' => $trait, 'adding' => $adding],
            ModerationAction::TRAIT,
            sprintf('%s %s', $adding ? '+' : '-', $trait),
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
