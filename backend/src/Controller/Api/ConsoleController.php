<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Rcon\CommandCatalogueProvider;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{serverId}/console')]
#[IsGranted(Permission::UseConsole->value)]
final class ConsoleController extends AbstractController
{
    /**
     * Commands that stop the server or lock people out. They still run --
     * refusing them would make the console less useful than an SSH
     * session -- but the interface asks first.
     */
    private const DANGEROUS = ['quit', 'save', 'worldgen', 'removezombies', 'reloadalllua', 'reloadlua'];

    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly RconClientInterface $rcon,
        private readonly CommandCatalogueProvider $catalogue,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/commands', name: 'api_console_commands', methods: ['GET'])]
    public function commands(string $serverId, Request $request): JsonResponse
    {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        try {
            $catalogue = $this->catalogue->forServer($server, $request->query->getBoolean('refresh'));
        } catch (RconException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'items' => array_map(
                static fn (array $command): array => [
                    ...$command,
                    'dangerous' => \in_array($command['name'], self::DANGEROUS, true),
                ],
                $catalogue->commands,
            ),
            // The server lists what the connected account may run, which
            // is fewer than the build contains.
            'note' => 'reportedByServer',
        ]);
    }

    #[Route('', name: 'api_console_execute', methods: ['POST'])]
    public function execute(string $serverId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $server = $this->requireServer($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $line = $this->payloadOf($request)['command'] ?? null;

        if (!\is_string($line) || trim($line) === '') {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['command' => 'validation.required'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $line = ltrim(trim($line), '/');
        $config = $server->getRconConfig();

        if ($config === null) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'servers.rconNotConfigured',
            ], Response::HTTP_CONFLICT);
        }

        try {
            $reply = $this->rcon->execute($config, $line);
        } catch (RconException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Recorded so the moderation history shows what was done by hand
        // as well as what went through the interface.
        $this->entityManager->persist(
            new ModerationAction($server, ModerationAction::CONSOLE, $this->verbOf($line), $actor, $line, $reply),
        );
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'sent', 'command' => $line, 'reply' => $reply]);
    }

    private function verbOf(string $line): string
    {
        $parts = preg_split('/\s+/', trim($line), 2) ?: [''];

        return substr(strtolower($parts[0]), 0, 100);
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
