<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Config\ConfigFileLocator;
use App\Server\Config\ConfigKind;
use App\Server\Config\ConfigReader;
use App\Server\Config\ConfigReloader;
use App\Server\Config\ConfigWriteRefused;
use App\Server\Config\ConfigWriter;
use App\Server\Config\SandboxSchema;
use App\Server\Config\ServerIniSchema;
use App\Server\Storage\StorageException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Reading a server's own settings files, and what this build knows of them. */
#[Route('/api/servers/{id}/config')]
#[IsGranted(Permission::EditServerConfig->value)]
final class ServerConfigController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly ConfigFileLocator $locator,
        private readonly ConfigReader $reader,
        private readonly ConfigWriter $writer,
        private readonly ConfigReloader $reloader,
    ) {
    }

    /**
     * Which files this server has, by their real names.
     *
     * Never guessed: `servertest.ini` is named after the server, so on
     * another host it is called something else. Several matches is a
     * state of its own — the operator chooses — and none says where it
     * looked.
     */
    #[Route('/files', name: 'api_server_config_files', methods: ['GET'])]
    public function files(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $config = $server->getFtpConfig();

        if ($config === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'config.noTransfer'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->locator->locate($config));
    }

    /** The values a file holds, described through the schema. */
    #[Route('/{kind}', name: 'api_server_config_read', methods: ['GET'], requirements: ['kind' => 'sandbox|ini'])]
    public function read(string $id, string $kind): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $config = $server->getFtpConfig();

        if ($config === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'config.noTransfer'], Response::HTTP_CONFLICT);
        }

        $found = $this->locator->locate($config);
        $names = $kind === 'sandbox' ? $found['sandbox'] : $found['ini'];

        if ($names === []) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'config.fileNotFound',
                'searched' => $found['searched'],
            ], Response::HTTP_NOT_FOUND);
        }

        // More than one is the operator's choice, not ours; until they
        // pick, the first by name is read and all of them are reported.
        $path = rtrim((string) $found['directory'], '/').'/'.$names[0];

        try {
            $read = $kind === 'sandbox'
                ? $this->reader->sandbox($config, $path)
                : $this->reader->ini($config, $path);
        } catch (StorageException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'kind' => $kind,
            'path' => $path,
            'candidates' => $names,
            'buildId' => SandboxSchema::BUILD_ID,
            'groups' => $kind === 'sandbox' ? SandboxSchema::GROUPS : ServerIniSchema::GROUPS,
            'values' => $read['values'],
            // How many the file holds that this build has never heard of
            // -- a mod's, and they are shown rather than hidden.
            'unknown' => $read['unknown'],
        ]);
    }

    /**
     * Changes values in one file.
     *
     * **Editing only.** A key the body names must already be in the
     * file, and one the file holds that the body omits is left alone --
     * that is what keeps a mod's options alive through a save. The write
     * is backed up, read back and compared per key before it is called a
     * success.
     */
    #[Route('/{kind}', name: 'api_server_config_write', methods: ['PATCH'], requirements: ['kind' => 'sandbox|ini'])]
    public function write(string $id, string $kind, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $config = $server->getFtpConfig();

        if ($config === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'config.noTransfer'], Response::HTTP_CONFLICT);
        }

        $payload = $request->toArray();
        $changes = $payload['changes'] ?? null;

        if (!is_array($changes) || $changes === []) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'config.nothingToWrite',
            ], Response::HTTP_BAD_REQUEST);
        }

        $clean = [];

        foreach ($changes as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                return new JsonResponse([
                    'status' => 'failed',
                    'error' => 'config.invalidChange',
                ], Response::HTTP_BAD_REQUEST);
            }

            $clean[$key] = $value;
        }

        $found = $this->locator->locate($config);
        $names = $kind === 'sandbox' ? $found['sandbox'] : $found['ini'];

        if ($names === []) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'config.fileNotFound',
                'searched' => $found['searched'],
            ], Response::HTTP_NOT_FOUND);
        }

        $path = rtrim((string) $found['directory'], '/').'/'.$names[0];
        $which = ConfigKind::from($kind);

        try {
            $result = $this->writer->apply($config, $path, $which, $clean);
        } catch (ConfigWriteRefused $refused) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $refused->messageKey(),
                'keys' => $refused->keys(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (StorageException $exception) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $exception->messageKey(),
                'detail' => $exception->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        if (!$result['verified']) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => $result['error'],
                'mismatched' => $result['mismatched'],
                'restored' => $result['restored'],
                'backup' => $result['backup'],
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Written already; whether the game has it yet is a separate
        // question, and one the operator must not have to guess at.
        $applied = $this->reloader->apply(
            $server,
            $which,
            array_intersect_key($clean, array_flip($result['written'])),
        );

        return new JsonResponse([
            'status' => 'written',
            'path' => $path,
            'written' => $result['written'],
            'backup' => $result['backup'],
            'apply' => $applied->value,
            'restartNeeded' => $applied->needsRestart(),
            'applyMessage' => $applied->messageKey(),
        ]);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }
}
