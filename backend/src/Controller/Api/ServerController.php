<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Entity\RconConfig;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Bridge\BridgeInstaller;
use App\Server\Bridge\BridgePathMissing;
use App\Server\Bridge\BridgeReloader;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconException;
use App\Server\Storage\ServerFileBrowser;
use App\Server\Storage\StorageException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/servers')]
#[IsGranted(Permission::ViewServers->value)]
final class ServerController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ServerFileBrowser $files,
        private readonly RconClientInterface $rcon,
    ) {
    }

    #[Route('', name: 'api_servers_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([
            'items' => array_map(
                $this->present(...),
                $this->servers->findBy([], ['name' => 'ASC']),
            ),
        ]);
    }

    #[Route('/{id}', name: 'api_servers_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        return $server instanceof GameServer
            ? new JsonResponse($this->present($server))
            : $this->notFound();
    }

    #[Route('', name: 'api_servers_create', methods: ['POST'])]
    #[IsGranted(Permission::EditServers->value)]
    public function create(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $name = $payload['name'] ?? null;

        if (!\is_string($name) || trim($name) === '') {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['name' => 'validation.required'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $server = new GameServer(trim($name), $this->stringOrNull($payload['description'] ?? null));

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        return new JsonResponse($this->present($server), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_servers_update', methods: ['PATCH'])]
    #[IsGranted(Permission::EditServers->value)]
    public function update(string $id, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $payload = $request->toArray();

        if (\array_key_exists('name', $payload)) {
            $name = \is_string($payload['name']) ? trim($payload['name']) : '';

            if ($name === '') {
                return new JsonResponse([
                    'status' => 'failed',
                    'errors' => ['name' => 'validation.required'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $server->setName($name);
        }

        if (\array_key_exists('description', $payload)) {
            $server->setDescription($this->stringOrNull($payload['description']));
        }

        if (isset($payload['ftp']) && \is_array($payload['ftp'])) {
            $this->applyFtp($server, $payload['ftp']);
        }

        if (isset($payload['rcon']) && \is_array($payload['rcon'])) {
            $this->applyRcon($server, $payload['rcon']);
        }

        $violations = $this->validator->validate($server);

        if ($violations->count() > 0) {
            $errors = [];

            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['status' => 'failed', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->present($server));
    }

    #[Route('/{id}', name: 'api_servers_delete', methods: ['DELETE'])]
    #[IsGranted(Permission::EditServers->value)]
    public function delete(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $this->entityManager->remove($server);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/ftp/test', name: 'api_servers_test_ftp', methods: ['POST'])]
    #[IsGranted(Permission::EditServers->value)]
    public function testFtp(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer || $server->getFtpConfig() === null) {
            return $this->notFound();
        }

        try {
            $result = $this->files->verify($server->getFtpConfig());
        } catch (StorageException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $server->getFtpConfig()->recordSuccessfulVerification();
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok', ...$result]);
    }

    #[Route('/{id}/files', name: 'api_servers_browse', methods: ['GET'])]
    #[IsGranted(Permission::EditServers->value)]
    public function browse(string $id, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer || $server->getFtpConfig() === null) {
            return $this->notFound();
        }

        try {
            return new JsonResponse(
                $this->files->listDirectory($server->getFtpConfig(), (string) $request->query->get('path', '')),
            );
        } catch (StorageException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/{id}/files/read', name: 'api_servers_read_file', methods: ['GET'])]
    #[IsGranted(Permission::EditServers->value)]
    public function readFile(string $id, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer || $server->getFtpConfig() === null) {
            return $this->notFound();
        }

        $path = (string) $request->query->get('path', '');

        if ($path === '') {
            return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
        }

        try {
            $contents = $this->files->readTail($server->getFtpConfig(), $path);
        } catch (StorageException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['path' => $path, 'contents' => $contents]);
    }

    #[Route('/{id}/bridge', name: 'api_servers_install_bridge', methods: ['POST'])]
    #[IsGranted(Permission::ManageBridge->value)]
    public function installBridge(string $id, BridgeInstaller $installer, BridgeReloader $reloader): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        try {
            $path = $installer->install($server);
        } catch (BridgePathMissing) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'bridge.pathMissing',
            ], Response::HTTP_CONFLICT);
        } catch (StorageException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $version = $installer->version();

        // The upload has already succeeded, so a reload that does not
        // work is not an error here -- it only means the operator has to
        // restart. Reporting the upload as failed because of it would be
        // a lie in the other direction.
        $reload = $reloader->reload($server, $version);

        return new JsonResponse([
            'status' => 'installed',
            'path' => $path,
            'version' => $version,
            'reload' => $reload->value,
            'restartNeeded' => $reload->needsRestart(),
            'reloadMessage' => $reload->messageKey(),
        ]);
    }

    #[Route('/{id}/rcon/test', name: 'api_servers_test_rcon', methods: ['POST'])]
    #[IsGranted(Permission::EditServers->value)]
    public function testRcon(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer || $server->getRconConfig() === null) {
            return $this->notFound();
        }

        // A hung port must not hold an FPM worker past this point.
        set_time_limit(20);

        try {
            $reply = $this->rcon->probe($server->getRconConfig());
        } catch (RconException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $server->getRconConfig()->recordSuccessfulVerification();
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok', 'reply' => $reply]);
    }

    /** @param array<string, mixed> $data */
    private function applyFtp(GameServer $server, array $data): void
    {
        $config = $server->getFtpConfig();

        if (!$config instanceof FtpConfig) {
            $config = new FtpConfig(
                $server,
                (string) ($data['host'] ?? ''),
                (string) ($data['username'] ?? ''),
            );
            $this->entityManager->persist($config);
        }

        if (isset($data['protocol']) && \is_string($data['protocol'])) {
            $config->setProtocol($data['protocol']);
        }

        if (isset($data['host']) && \is_string($data['host'])) {
            $config->setHost(trim($data['host']));
        }

        if (isset($data['port']) && is_numeric($data['port'])) {
            $config->setPort((int) $data['port']);
        }

        if (isset($data['username']) && \is_string($data['username'])) {
            $config->setUsername(trim($data['username']));
        }

        // An omitted or empty secret means "leave it alone"; the interface
        // never receives the stored value to send back.
        if (!empty($data['password'])) {
            $config->setPassword((string) $data['password']);
        }

        if (!empty($data['privateKey'])) {
            $config->setPrivateKey((string) $data['privateKey']);
        }

        foreach (['basePath' => 'setBasePath', 'luaServerPath' => 'setLuaServerPath', 'logPath' => 'setLogPath'] as $key => $setter) {
            if (\array_key_exists($key, $data)) {
                $value = $this->stringOrNull($data[$key]);
                $config->{$setter}($key === 'basePath' ? ($value ?? '/') : $value);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function applyRcon(GameServer $server, array $data): void
    {
        $config = $server->getRconConfig();

        if (!$config instanceof RconConfig) {
            $config = new RconConfig(
                $server,
                (string) ($data['host'] ?? ''),
                (string) ($data['password'] ?? ''),
            );
            $this->entityManager->persist($config);
        }

        if (isset($data['host']) && \is_string($data['host'])) {
            $config->setHost(trim($data['host']));
        }

        if (isset($data['port']) && is_numeric($data['port'])) {
            $config->setPort((int) $data['port']);
        }

        if (!empty($data['password'])) {
            $config->setPassword((string) $data['password']);
        }
    }

    /** @return array<string, mixed> */
    private function present(GameServer $server): array
    {
        $ftp = $server->getFtpConfig();
        $rcon = $server->getRconConfig();

        return [
            'id' => $server->getId()->toRfc4122(),
            'name' => $server->getName(),
            'description' => $server->getDescription(),
            'createdAt' => $server->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'ftp' => $ftp === null ? null : [
                'protocol' => $ftp->getProtocol(),
                'host' => $ftp->getHost(),
                'port' => $ftp->getPort(),
                'username' => $ftp->getUsername(),
                // Secrets stay on the server; the interface shows whether
                // one is set, never what it is.
                'hasPassword' => $ftp->getPassword() !== null,
                'hasPrivateKey' => $ftp->getPrivateKey() !== null,
                'basePath' => $ftp->getBasePath(),
                'luaServerPath' => $ftp->getLuaServerPath(),
                'logPath' => $ftp->getLogPath(),
                'lastVerifiedAt' => $ftp->getLastVerifiedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'rcon' => $rcon === null ? null : [
                'host' => $rcon->getHost(),
                'port' => $rcon->getPort(),
                'hasPassword' => true,
                'lastVerifiedAt' => $rcon->getLastVerifiedAt()?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
