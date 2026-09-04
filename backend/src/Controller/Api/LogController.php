<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Logs\LogFileFinder;
use App\Server\Logs\LogTailer;
use App\Server\Storage\StorageException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/servers/{serverId}/logs')]
#[IsGranted('ROLE_SERVER_ADMIN')]
final class LogController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly LogFileFinder $finder,
        private readonly LogTailer $tailer,
    ) {
    }

    /** The newest log of each kind the server is writing. */
    #[Route('', name: 'api_logs_list', methods: ['GET'])]
    public function list(string $serverId): JsonResponse
    {
        $config = $this->requireConfig($serverId);

        if (!$config instanceof FtpConfig) {
            return $this->notFound();
        }

        try {
            $files = $this->finder->findAll($config);
        } catch (StorageException $exception) {
            return $this->storageFailure($exception);
        }

        return new JsonResponse([
            'items' => array_map(
                static fn (string $kind, array $file): array => ['kind' => $kind, ...$file],
                array_keys($files),
                array_values($files),
            ),
            'kinds' => array_keys(LogFileFinder::KINDS),
        ]);
    }

    /**
     * Whatever has been written since the given offset. The caller passes
     * back the offset it was given, which is what makes this a tail
     * rather than a repeated download of the whole file.
     */
    #[Route('/tail', name: 'api_logs_tail', methods: ['GET'])]
    public function tail(string $serverId, Request $request): JsonResponse
    {
        $config = $this->requireConfig($serverId);

        if (!$config instanceof FtpConfig) {
            return $this->notFound();
        }

        $path = $request->query->getString('path');

        if ($path === '') {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['path' => 'validation.required'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A path is only ever one the panel itself listed, so anything
        // reaching outside the log directory is a caller gone wrong.
        if (str_contains($path, '..')) {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['path' => 'validation.invalid'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $offset = $request->query->has('offset') ? $request->query->getInt('offset') : null;

        try {
            $result = $this->tailer->read($config, $path, $offset === null || $offset < 0 ? null : $offset);
        } catch (StorageException $exception) {
            return $this->storageFailure($exception);
        }

        return new JsonResponse($result);
    }

    private function requireConfig(string $serverId): ?FtpConfig
    {
        $server = $this->servers->find($serverId);

        return $server instanceof GameServer ? $server->getFtpConfig() : null;
    }

    private function storageFailure(StorageException $exception): JsonResponse
    {
        return new JsonResponse([
            'status' => 'failed',
            'error' => $exception->messageKey(),
            'detail' => $exception->getMessage(),
        ], Response::HTTP_BAD_GATEWAY);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
