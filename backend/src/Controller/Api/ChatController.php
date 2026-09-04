<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Repository\GameServerRepository;
use App\Server\Chat\ChatBroadcaster;
use App\Server\Chat\ChatLine;
use App\Server\Logs\LogFileFinder;
use App\Server\Logs\LogTailer;
use App\Server\Rcon\RconException;
use App\Server\Storage\StorageException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{serverId}/chat')]
#[IsGranted('ROLE_SERVER_ADMIN')]
final class ChatController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly LogFileFinder $finder,
        private readonly LogTailer $tailer,
        private readonly ChatBroadcaster $broadcaster,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Whatever the chat log gained since the given offset. The caller
     * passes back the offset it was handed, which is what makes this a
     * conversation rather than a repeated download.
     */
    #[Route('', name: 'api_chat_read', methods: ['GET'])]
    public function read(string $serverId, Request $request): JsonResponse
    {
        $server = $this->servers->find($serverId);
        $config = $server instanceof GameServer ? $server->getFtpConfig() : null;

        if ($config === null) {
            return $this->notFound();
        }

        try {
            $files = $this->finder->findAll($config);
        } catch (StorageException $exception) {
            return $this->storageFailure($exception);
        }

        $chat = $files['chat'] ?? null;

        if ($chat === null) {
            return new JsonResponse([
                'lines' => [],
                'offset' => null,
                'file' => null,
                'error' => 'chat.noLog',
            ]);
        }

        // A new log means the server restarted, so the offset from the
        // previous one means nothing.
        $sameFile = $request->query->getString('file') === $chat['name'];
        $offset = $sameFile && $request->query->has('offset')
            ? $request->query->getInt('offset')
            : null;

        try {
            $result = $this->tailer->read($config, $chat['path'], $offset);
        } catch (StorageException $exception) {
            return $this->storageFailure($exception);
        }

        $lines = array_map(ChatLine::parse(...), $result['lines']);

        return new JsonResponse([
            'lines' => array_values(array_map(
                static fn (ChatLine $line): array => (array) $line,
                array_filter(
                    $lines,
                    // The server logs each message twice; the duplicate is
                    // marked rather than guessed at again here.
                    static fn (ChatLine $line): bool => $line->kind !== ChatLine::KIND_IGNORE,
                ),
            )),
            'offset' => $result['offset'],
            'file' => $chat['name'],
            'rotated' => $result['rotated'],
            'error' => null,
        ]);
    }

    #[Route('', name: 'api_chat_send', methods: ['POST'])]
    public function send(string $serverId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $server = $this->servers->find($serverId);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $message = $this->payloadOf($request)['message'] ?? null;

        if (!\is_string($message) || ChatBroadcaster::sanitise($message) === '') {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['message' => 'validation.required'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $reply = $this->broadcaster->broadcast($server, $message);
        } catch (RconException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Recorded like any other command, so it is clear afterwards who
        // announced what.
        $this->entityManager->persist(new ModerationAction(
            $server,
            ModerationAction::BROADCAST,
            $actor->getDisplayName(),
            $actor,
            ChatBroadcaster::sanitise($message),
            $reply,
        ));
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'sent',
            'message' => ChatBroadcaster::sanitise($message),
            'reply' => $reply,
        ]);
    }

    private function storageFailure(StorageException $exception): JsonResponse
    {
        return new JsonResponse([
            'status' => 'failed',
            'error' => $exception->messageKey(),
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

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
