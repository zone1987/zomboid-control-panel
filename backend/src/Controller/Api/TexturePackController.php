<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\Permission\Permission;
use App\Server\Map\Textures\ChunkedUpload;
use App\Server\Map\Textures\TexturePackStore;
use App\Server\Map\Textures\UploadRefused;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The texture packs the isometric renderer draws from.
 *
 * Uploaded in pieces because the largest is 306 MB and the container
 * accepts a 16 MB request.
 */
#[Route('/api/map/textures')]
#[IsGranted(Permission::EditSettings->value)]
final class TexturePackController extends AbstractController
{
    public function __construct(
        private readonly TexturePackStore $store,
        private readonly ChunkedUpload $upload,
    ) {
    }

    #[Route('', name: 'api_textures_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $present = $this->store->present();

        return new JsonResponse([
            'required' => array_map(
                fn (string $name): array => [
                    'name' => $name,
                    'present' => isset($present[$name]),
                    'bytes' => $present[$name] ?? 0,
                    // So an interrupted upload can pick up where it stopped.
                    'received' => $this->upload->received($name),
                ],
                TexturePackStore::REQUIRED,
            ),
            'complete' => $this->store->isComplete(),
            'totalBytes' => $this->store->totalBytes(),
            'chunkBytes' => ChunkedUpload::CHUNK_BYTES,
        ]);
    }

    #[Route('/chunk', name: 'api_textures_chunk', methods: ['POST'])]
    public function chunk(Request $request): JsonResponse
    {
        $name = (string) $request->request->get('name');
        $offset = $request->request->getInt('offset');
        $file = $request->files->get('chunk');

        if ($file === null) {
            return $this->refuse('validation.required');
        }

        if (!\in_array($name, TexturePackStore::REQUIRED, true)) {
            return $this->refuse('map.unknownPack');
        }

        $bytes = @file_get_contents($file->getPathname());

        if ($bytes === false) {
            return $this->refuse('map.unreadable');
        }

        try {
            $received = $this->upload->append($name, $offset, $bytes);
        } catch (UploadRefused $refused) {
            return $this->refuse($refused->messageKey());
        }

        return new JsonResponse(['status' => 'ok', 'received' => $received]);
    }

    #[Route('/finish', name: 'api_textures_finish', methods: ['POST'])]
    public function finish(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $name = (string) ($payload['name'] ?? '');
        $size = (int) ($payload['bytes'] ?? 0);

        if (!\in_array($name, TexturePackStore::REQUIRED, true)) {
            return $this->refuse('map.unknownPack');
        }

        try {
            $stored = $this->upload->finish($name, $size);
        } catch (UploadRefused $refused) {
            return $this->refuse($refused->messageKey());
        }

        return new JsonResponse([
            'status' => 'ok',
            'name' => $name,
            'bytes' => $stored,
            'complete' => $this->store->isComplete(),
        ]);
    }

    #[Route('/{name}', name: 'api_textures_delete', methods: ['DELETE'], requirements: ['name' => '[A-Za-z0-9_.]+'])]
    public function delete(string $name): JsonResponse
    {
        if (!\in_array($name, TexturePackStore::REQUIRED, true)) {
            return $this->refuse('map.unknownPack');
        }

        $this->store->remove($name);
        $this->upload->discard($name);

        return $this->status();
    }

    private function refuse(string $key): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'error' => $key],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
