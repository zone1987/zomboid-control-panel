<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\PlayerSnapshot;
use App\Entity\User;
use App\Repository\GameServerRepository;
use App\Repository\ModerationActionRepository;
use App\Repository\PlayerSnapshotRepository;
use App\Server\Players\BridgeStatusReader;
use App\Server\Players\BridgeUnavailable;
use App\Server\Players\PlayerModerator;
use App\Server\Rcon\RconException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{serverId}/players')]
#[IsGranted('ROLE_SERVER_ADMIN')]
final class PlayerController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly PlayerSnapshotRepository $snapshots,
        private readonly BridgeStatusReader $bridge,
        private readonly PlayerModerator $moderator,
        private readonly ModerationActionRepository $actions,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'api_players_list', methods: ['GET'])]
    public function list(string $serverId, Request $request): JsonResponse
    {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $status = null;
        $error = null;

        // Refreshing on read keeps the list current without a worker; the
        // bridge file is small, so the cost is a single SFTP round trip.
        if ($request->query->getBoolean('refresh', true)) {
            try {
                $status = $this->bridge->refresh($server);
            } catch (BridgeUnavailable $exception) {
                $error = $exception->messageKey();
            }
        }

        $players = $this->snapshots->findForServer($server, $request->query->getBoolean('onlineOnly'));

        return new JsonResponse([
            'items' => array_map($this->present(...), $players),
            'bridge' => $status === null ? null : [
                'playerCount' => $status['playerCount'],
                'generatedAt' => $status['generatedAt']->format(\DateTimeInterface::ATOM),
                'version' => $status['bridgeVersion'],
                'stale' => $status['stale'],
            ],
            'error' => $error,
        ]);
    }

    #[Route('/{username}/kick', name: 'api_players_kick', methods: ['POST'])]
    public function kick(string $serverId, string $username, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $reason = $this->reasonFrom($request);

        return $this->moderate(
            $serverId,
            fn (GameServer $server): string => $this->moderator->kick($server, $username, $reason),
            ModerationAction::KICK,
            $username,
            $actor,
            $reason,
        );
    }

    #[Route('/{username}/ban', name: 'api_players_ban', methods: ['POST'])]
    public function ban(string $serverId, string $username, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $payload = $this->payloadOf($request);

        $reason = $this->reasonFrom($request);
        $expiresAt = $this->expiryFrom($payload);

        if ($expiresAt === false) {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['durationMinutes' => 'validation.invalid'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->moderate(
            $serverId,
            fn (GameServer $server): string => $this->moderator->ban(
                $server,
                $username,
                $reason,
                (bool) ($payload['includeIp'] ?? false),
            ),
            ModerationAction::BAN,
            $username,
            $actor,
            $reason,
            $expiresAt,
        );
    }

    /**
     * Zomboid bans permanently, so a duration is the panel's own promise
     * to lift it later rather than something the game enforces.
     *
     * @param array<string, mixed> $payload
     *
     * @return \DateTimeImmutable|null|false false when the value is unusable
     */
    private function expiryFrom(array $payload): \DateTimeImmutable|null|false
    {
        $minutes = $payload['durationMinutes'] ?? null;

        if ($minutes === null || $minutes === '') {
            return null;
        }

        if (!is_numeric($minutes)) {
            return false;
        }

        $minutes = (int) $minutes;

        // A year is well past any sensible cool-off; beyond that the
        // operator means permanent and should say so.
        if ($minutes < 1 || $minutes > 525600) {
            return false;
        }

        return new \DateTimeImmutable(sprintf('+%d minutes', $minutes));
    }

    #[Route('/{username}/unban', name: 'api_players_unban', methods: ['POST'])]
    public function unban(string $serverId, string $username, #[CurrentUser] User $actor): JsonResponse
    {
        return $this->moderate(
            $serverId,
            fn (GameServer $server): string => $this->moderator->unban($server, $username),
            ModerationAction::UNBAN,
            $username,
            $actor,
        );
    }

    /**
     * Names this panel believes are banned. RCON offers no way to list
     * them, so the answer comes from what the panel itself did.
     */
    #[Route('/bans', name: 'api_players_bans', methods: ['GET'], priority: 10)]
    public function bans(string $serverId): JsonResponse
    {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        return new JsonResponse([
            'items' => array_map(
                static fn (array $ban): array => [
                    'username' => $ban['username'],
                    'reason' => $ban['reason'],
                    'bannedAt' => $ban['bannedAt']->format(\DateTimeInterface::ATOM),
                    'expiresAt' => $ban['expiresAt']?->format(\DateTimeInterface::ATOM),
                    'permanent' => $ban['expiresAt'] === null,
                ],
                $this->actions->activeBans($server),
            ),
            'note' => 'panelRecordOnly',
        ]);
    }

    #[Route('/history', name: 'api_players_history', methods: ['GET'], priority: 10)]
    public function history(string $serverId): JsonResponse
    {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        return new JsonResponse([
            'items' => array_map(
                static fn (ModerationAction $a): array => [
                    'action' => $a->getAction(),
                    'username' => $a->getUsername(),
                    'reason' => $a->getReason(),
                    'performedBy' => $a->getPerformedBy()?->getDisplayName(),
                    'performedAt' => $a->getPerformedAt()->format(\DateTimeInterface::ATOM),
                    'expiresAt' => $a->getExpiresAt()?->format(\DateTimeInterface::ATOM),
                ],
                $this->actions->history($server),
            ),
        ]);
    }

    #[Route('/{username}/access-level', name: 'api_players_access_level', methods: ['POST'])]
    public function setAccessLevel(string $serverId, string $username, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $level = $this->payloadOf($request)['level'] ?? null;

        if (!\is_string($level) || !\in_array($level, PlayerModerator::ACCESS_LEVELS, true)) {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['level' => 'validation.invalid'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->moderate(
            $serverId,
            fn (GameServer $server): string => $this->moderator->setAccessLevel($server, $username, $level),
            ModerationAction::ACCESS_LEVEL,
            $username,
            $actor,
            $level,
        );
    }

    private function moderate(
        string $serverId,
        \Closure $action,
        string $kind,
        string $username,
        User $actor,
        ?string $reason = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): JsonResponse {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        try {
            $reply = $action($server);
        } catch (RconException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Recorded only after the server accepted it, so the log does not
        // claim bans that never happened.
        $this->entityManager->persist(
            new ModerationAction($server, $kind, $username, $actor, $reason, $reply, $expiresAt),
        );
        $this->entityManager->flush();

        // Zomboid answers in prose, so the reply is passed through rather
        // than interpreted; the caller decides what it means.
        return new JsonResponse(['status' => 'sent', 'reply' => $reply]);
    }

    /** @return array<string, mixed> */
    private function present(PlayerSnapshot $player): array
    {
        return [
            'username' => $player->getUsername(),
            'steamId' => $player->getSteamId(),
            'online' => $player->isOnline(),
            'position' => ['x' => $player->getX(), 'y' => $player->getY(), 'z' => $player->getZ()],
            'health' => $player->getHealth(),
            'infected' => $player->isInfected(),
            'infectionLevel' => $player->getInfectionLevel(),
            'hoursSurvived' => $player->getHoursSurvived(),
            'accessLevel' => $player->getAccessLevel(),
            'skills' => $player->getSkills(),
            'traits' => $player->getTraits(),
            'lastSeenAt' => $player->getLastSeenAt()->format(\DateTimeInterface::ATOM),
            'firstSeenAt' => $player->getFirstSeenAt()->format(\DateTimeInterface::ATOM),
        ];
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

    private function reasonFrom(Request $request): ?string
    {
        $reason = $this->payloadOf($request)['reason'] ?? null;

        return \is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
