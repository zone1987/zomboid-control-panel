<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\Permission\Permission;
use App\Server\Items\Icons\IconExtractor;
use App\Server\Items\Icons\IconStore;
use App\Server\Items\Icons\MalformedPack;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/icons')]
#[IsGranted('ROLE_USER')]
final class IconController extends AbstractController
{
    /** The packs that hold item icons; the rest are world tiles. */
    public const WANTED_PACKS = ['UI.pack', 'UI2.pack', 'ApComUI.pack'];

    public function __construct(
        private readonly IconStore $store,
        private readonly IconExtractor $extractor,
    ) {
    }

    /** How many icons are held, so the interface knows whether to show them. */
    #[Route('', name: 'api_icons_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $count = $this->store->count();

        return new JsonResponse([
            'count' => $count,
            'available' => $count > 0,
            'wanted' => self::WANTED_PACKS,
        ]);
    }

    /**
     * Takes texture packs uploaded through the interface.
     *
     * A hosted game server ships no texture packs, so an operator who
     * does not have the game on the same machine as the panel has no
     * other way to get item artwork in.
     */
    #[Route('/upload', name: 'api_icons_upload', methods: ['POST'])]
    #[IsGranted(Permission::EditServers->value)]
    public function upload(Request $request): JsonResponse
    {
        $files = $request->files->all()['packs'] ?? [];
        $files = \is_array($files) ? $files : [$files];

        if ($files === []) {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['packs' => 'validation.required'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($request->request->getBoolean('clear')) {
            $this->store->clear();
        }

        $results = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $results[] = $this->absorb($file);
        }

        return new JsonResponse([
            'status' => 'done',
            'results' => $results,
            'count' => $this->store->count(),
        ]);
    }

    /** @return array<string, mixed> */
    private function absorb(UploadedFile $file): array
    {
        $name = $file->getClientOriginalName();

        if (!$file->isValid()) {
            return ['name' => $name, 'failed' => true, 'error' => 'icons.uploadFailed'];
        }

        $contents = @file_get_contents($file->getPathname());

        if ($contents === false) {
            return ['name' => $name, 'failed' => true, 'error' => 'icons.unreadable'];
        }

        try {
            $result = $this->extractor->extract($contents, $name);
        } catch (MalformedPack $exception) {
            // A world-tile pack parses differently and holds no item
            // icons; saying so beats a bare failure.
            return [
                'name' => $name,
                'failed' => true,
                'error' => 'icons.notAnIconPack',
                'detail' => $exception->getMessage(),
            ];
        }

        return [
            'name' => $name,
            'failed' => false,
            'extracted' => $result['extracted'],
            'pages' => $result['pages'],
            'skipped' => $result['skipped'],
        ];
    }

    #[Route('/{name}.png', name: 'api_icons_show', methods: ['GET'], requirements: ['name' => '[A-Za-z0-9_.-]{1,150}'])]
    public function show(string $name, Request $request): Response
    {
        $png = $this->store->get($name);

        if ($png === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($png, Response::HTTP_OK, ['Content-Type' => 'image/png']);

        // An icon never changes under the same name; only re-extraction
        // replaces it, and that changes what the name refers to anyway.
        $response->setEtag(md5($png));
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->isNotModified($request);

        return $response;
    }
}
