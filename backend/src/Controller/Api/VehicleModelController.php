<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\Permission\Permission;
use App\Server\Items\Icons\ChunkedUpload;
use App\Server\Items\Icons\UploadRefused;
use App\Server\Vehicles\Models\ModelStore;
use App\Server\Vehicles\Models\VehicleCatalogue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The vehicle artwork the map draws with.
 *
 * A dedicated server ships no models, so they come from a game
 * installation by upload -- the same arrangement as the item icons.
 */
#[Route('/api/vehicle-models')]
#[IsGranted('ROLE_USER')]
final class VehicleModelController extends AbstractController
{
    /** Big enough for the largest model, small enough to refuse nonsense. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Wheels ship only in the game's own text mesh format, not as FBX,
     * so .txt is a model file here as much as .fbx is.
     */
    private const ALLOWED = ['fbx', 'png', 'txt'];

    public function __construct(private readonly ModelStore $models)
    {
    }

    #[Route('', name: 'api_vehicle_models_status', methods: ['GET'])]
    #[IsGranted(Permission::ViewVehicles->value)]
    public function status(): JsonResponse
    {
        $inventory = $this->models->inventory();

        return new JsonResponse([
            'models' => \count($inventory['models']),
            'textures' => \count($inventory['textures']),
            'bytes' => $inventory['bytes'],
            'available' => $inventory['models'] !== [],
            'names' => $inventory['models'],
        ]);
    }

    /**
     * Takes a batch rather than one file.
     *
     * A game installation holds 146 models and 399 textures; one
     * request each is several minutes of round trips. Each file is
     * judged on its own, so a single bad one does not cost the batch.
     */
    /**
     * What a vehicle is drawn from, so the map knows which files to
     * fetch before it fetches them.
     *
     * Absent files are reported rather than hidden: a vehicle whose
     * model was never uploaded falls back to a plain marker, and the
     * map can say so instead of drawing nothing.
     */
    #[Route('/catalogue', name: 'api_vehicle_models_catalogue', methods: ['GET'])]
    #[IsGranted(Permission::ViewVehicles->value)]
    public function catalogue(): JsonResponse
    {
        $entries = [];

        foreach (VehicleCatalogue::scripts() as $script) {
            $entry = VehicleCatalogue::find($script);

            if ($entry === null) {
                continue;
            }

            // The FBX export, not the game's own text mesh of the same
            // name. The text mesh has twice the vertices, which looked
            // like it carried the doors and bonnet the FBX leaves out --
            // but it has no roof panel either (12 vertices at its top
            // edge), so it is a different construction, not a fuller
            // one, and rendering it showed the cabin from inside.
            $model = $entry['model'];

            if (!$this->models->has($model)) {
                continue;
            }

            $entries[$script] = [
                'model' => $model,
                'texture' => $entry['texture'] !== null && $this->models->has($entry['texture'])
                    ? $entry['texture']
                    : null,
                'mask' => $entry['mask'] !== null && $this->models->has($entry['mask'])
                    ? $entry['mask']
                    : null,
                'scale' => $entry['scale'],
                'length' => $entry['length'],
                'width' => $entry['width'],
                'wheelMesh' => $this->models->has($entry['wheelMesh']) ? $entry['wheelMesh'] : null,
                'wheelTexture' => $this->models->has($entry['wheelTexture'])
                    ? $entry['wheelTexture']
                    : null,
                'wheels' => $entry['wheels'],
            ];
        }

        return new JsonResponse(['drawable' => $entries]);
    }

    #[Route('/upload', name: 'api_vehicle_models_upload', methods: ['POST'])]
    #[IsGranted(Permission::EditServers->value)]
    public function upload(Request $request): JsonResponse
    {
        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter(
            $request->files->all()['files'] ?? [$request->files->get('file')],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

        if ($files === []) {
            return $this->refuse('vehicleModels.noFile');
        }

        $results = [];
        $stored = 0;

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $error = $this->store($file, $name);

            if ($error === null) {
                ++$stored;
                $results[] = ['name' => $name, 'failed' => false];

                continue;
            }

            $results[] = ['name' => $name, 'failed' => true, 'error' => $error];
        }

        return new JsonResponse(['status' => 'done', 'stored' => $stored, 'results' => $results]);
    }

    /**
     * Takes one piece of a model file.
     *
     * The base game's largest model is under a megabyte, so this is not
     * needed today -- but a mod is free to ship something far larger,
     * and PHP refuses an oversized request at startup, before any code
     * runs, answering HTML rather than a reason.
     */
    #[Route('/chunk', name: 'api_vehicle_models_chunk', methods: ['POST'])]
    #[IsGranted(Permission::EditServers->value)]
    public function chunk(Request $request, ChunkedUpload $upload): JsonResponse
    {
        $name = ChunkedUpload::safeFileName((string) $request->request->get('name'), ...self::ALLOWED);
        $file = $request->files->get('chunk');

        if ($name === null || !$file instanceof UploadedFile) {
            return $this->refuse('vehicleModels.badName');
        }

        $bytes = @file_get_contents($file->getPathname());

        if ($bytes === false) {
            return $this->refuse('vehicleModels.unreadable');
        }

        try {
            $received = $upload->appendAny($name, $request->request->getInt('offset'), $bytes, false);
        } catch (UploadRefused $refused) {
            return $this->refuse($refused->messageKey());
        }

        return new JsonResponse(['status' => 'ok', 'received' => $received]);
    }

    /** Keeps a model that has fully arrived. */
    #[Route('/finish', name: 'api_vehicle_models_finish', methods: ['POST'])]
    #[IsGranted(Permission::EditServers->value)]
    public function finishUpload(Request $request, ChunkedUpload $upload): JsonResponse
    {
        $payload = $request->toArray();
        $name = ChunkedUpload::safeFileName((string) ($payload['name'] ?? ''), ...self::ALLOWED);

        if ($name === null) {
            return $this->refuse('vehicleModels.badName');
        }

        try {
            $contents = $upload->takeAny($name, (int) ($payload['bytes'] ?? 0));
        } catch (UploadRefused $refused) {
            return $this->refuse($refused->messageKey());
        }

        try {
            $this->models->write($name, $contents);
        } catch (\InvalidArgumentException) {
            return $this->refuse('vehicleModels.badName');
        }

        return new JsonResponse(['status' => 'ok', 'name' => $name]);
    }

    /** The reason it was refused, or null when it was kept. */
    private function store(UploadedFile $file, string $name): ?string
    {
        if (!\in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), self::ALLOWED, true)) {
            return 'vehicleModels.wrongKind';
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return 'vehicleModels.tooLarge';
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            return 'vehicleModels.unreadable';
        }

        try {
            $this->models->write($name, $contents);
        } catch (\InvalidArgumentException) {
            return 'vehicleModels.badName';
        }

        return null;
    }

    /**
     * Serves one file to the map.
     *
     * Cached hard: a model never changes under the same name, and the
     * map asks for it once per vehicle type.
     */
    #[Route(
        '/{name}',
        name: 'api_vehicle_models_show',
        methods: ['GET'],
        requirements: ['name' => '[A-Za-z0-9_-]{1,140}\\.[A-Za-z0-9]{1,6}'],
    )]
    #[IsGranted(Permission::ViewVehicles->value)]
    public function show(string $name, Request $request): Response
    {
        // Hashed from the bytes already in hand; the store offers no
        // digest, and a second read per request would cost more than it
        // saves.
        $contents = $this->models->read($name);

        if ($contents === null) {
            return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
        }

        $response = new Response($contents, Response::HTTP_OK, [
            'Content-Type' => str_ends_with(strtolower($name), '.png')
                ? 'image/png'
                : 'application/octet-stream',
        ]);

        $response->setEtag(md5($contents));
        // Private, not public: served behind an authenticated session, so
        // a shared cache must not keep a copy for the next account.
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');
        $response->isNotModified($request);

        return $response;
    }

    #[Route(
        '/{name}',
        name: 'api_vehicle_models_delete',
        methods: ['DELETE'],
        requirements: ['name' => '[A-Za-z0-9_-]{1,140}\\.[A-Za-z0-9]{1,6}'],
    )]
    #[IsGranted(Permission::EditServers->value)]
    public function delete(string $name): JsonResponse
    {
        return $this->models->delete($name)
            ? new JsonResponse(['status' => 'deleted'])
            : new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
    }

    private function refuse(string $key): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'error' => $key],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
