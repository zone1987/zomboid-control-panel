<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Security\Permission\Permission;
use App\Server\Mods\CoverStore;
use App\Server\Mods\GameBuild;
use App\Server\Mods\ModManager;
use App\Server\Mods\ModPresenter;
use App\Server\Mods\WorkshopSource;
use App\Server\Storage\StorageException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Browsing the Steam Workshop and keeping a server's mod list. */
#[Route('/api/servers/{id}/mods')]
#[IsGranted(Permission::ManageMods->value)]
final class ModController extends AbstractController
{
    public function __construct(
        private readonly GameServerRepository $servers,
        private readonly ModManager $mods,
        private readonly WorkshopSource $workshop,
        private readonly CoverStore $covers,
    ) {
    }

    /**
     * A mod's cover, served by the panel rather than by Steam.
     *
     * Steam has no resized variant — its store's resize parameters
     * answer with an error page on that host — so a grid of forty tiles
     * would pull originals measured at up to 738 KB each. Fetching once
     * and shrinking also keeps `img-src 'self'` intact and every
     * viewer's address away from Valve.
     */
    #[Route(
        '/{workshopId}/cover',
        name: 'api_mods_cover',
        methods: ['GET'],
        requirements: ['workshopId' => '\d+'],
    )]
    public function cover(string $id, string $workshopId): Response
    {
        if (!$this->servers->find($id) instanceof GameServer) {
            return $this->notFound();
        }

        $path = $this->covers->pathFor($workshopId);

        if ($path === null) {
            return $this->notFound();
        }

        if (!$this->covers->has($workshopId)) {
            $item = $this->workshop->itemsById([$workshopId])->first();

            if ($item === null || !$this->covers->fetch($item)) {
                // 404 rather than a placeholder image: the interface
                // already draws one, and a body here would be cached as
                // though it were the cover.
                return $this->notFound();
            }
        }

        $response = new Response(
            (string) file_get_contents($path),
            Response::HTTP_OK,
            ['Content-Type' => 'image/webp'],
        );

        // A workshop id addresses one picture forever; a mod's cover
        // changing is rare enough to be worth a stale week.
        $response->setPublic();
        $response->headers->set('Cache-Control', 'public, max-age=604800');

        return $response;
    }

    /** What the server's own file lists, described where the workshop can. */
    #[Route('/installed', name: 'api_mods_installed', methods: ['GET'])]
    public function installed(string $id): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        try {
            return new JsonResponse($this->mods->installed($server));
        } catch (StorageException) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'mods.transferFailed'],
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }

    /**
     * Searches the workshop.
     *
     * Answers 200 with `state: noKey` rather than an error status: a
     * missing key is a setting to fill in, not a failed request, and
     * the interface says so beside the search box.
     */
    #[Route('/search', name: 'api_mods_search', methods: ['GET'])]
    public function search(string $id, Request $request): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $tags = array_values(array_filter($request->query->all('tags'), \is_string(...)));
        $build = GameBuild::of($server->getGameBuild());

        // Filtered at Steam rather than afterwards: asking for a page of
        // thirty and then dropping the wrong build would leave gaps that
        // grow as the operator pages, and the count would be a lie.
        $buildTag = $build->tag();

        if ($buildTag !== null && !\in_array($buildTag, $tags, true)) {
            $tags[] = $buildTag;
        }

        $result = $this->workshop->search(
            term: (string) $request->query->get('q', ''),
            tags: $tags,
            sort: (string) $request->query->get('sort', 'trend'),
            page: $request->query->getInt('page', 1),
            perPage: $request->query->getInt('perPage', 30),
        );

        return new JsonResponse([
            'state' => $result->state->value,
            'hasKey' => $this->workshop->hasKey(),
            'total' => $result->total,
            // Said out loud so the screen can explain why it is showing
            // everything: an unknown build filters nothing.
            'gameBuild' => $server->getGameBuild(),
            'buildFilter' => $buildTag,
            'items' => array_map(
                fn ($item): array => ModPresenter::present(
                    $item->workshopId,
                    $item,
                    $build,
                    $this->coverBase($id),
                ),
                $result->items,
            ),
        ]);
    }

    /** One mod in full, including the dependencies Steam knows of. */
    #[Route('/{workshopId}', name: 'api_mods_detail', methods: ['GET'], requirements: ['workshopId' => '\d+'])]
    public function detail(string $id, string $workshopId): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        $build = GameBuild::of($server->getGameBuild());

        $result = $this->workshop->details($workshopId);
        $item = $result->first();

        if ($item === null) {
            return new JsonResponse([
                'state' => $result->state->value,
                'hasKey' => $this->workshop->hasKey(),
                'item' => null,
            ], $result->state->value === 'notFound' ? Response::HTTP_NOT_FOUND : Response::HTTP_OK);
        }

        $dependencies = $item->dependencies === []
            ? []
            : $this->workshop->itemsById($item->dependencies)->items;

        return new JsonResponse([
            'state' => $result->state->value,
            'hasKey' => $this->workshop->hasKey(),
            'gameBuild' => $server->getGameBuild(),
            'item' => ModPresenter::present($item->workshopId, $item, $build, $this->coverBase($id)),
            'dependencies' => array_map(
                fn ($dependency): array => ModPresenter::present(
                    $dependency->workshopId,
                    $dependency,
                    $build,
                    $this->coverBase($id),
                ),
                $dependencies,
            ),
        ]);
    }

    #[Route('/installed', name: 'api_mods_add', methods: ['POST'])]
    public function add(string $id, Request $request): JsonResponse
    {
        return $this->change($id, (string) ($request->toArray()['workshopId'] ?? ''), true);
    }

    #[Route(
        '/installed/{workshopId}',
        name: 'api_mods_remove',
        methods: ['DELETE'],
        requirements: ['workshopId' => '\d+'],
    )]
    public function remove(string $id, string $workshopId): JsonResponse
    {
        return $this->change($id, $workshopId, false);
    }

    private function change(string $id, string $workshopId, bool $add): JsonResponse
    {
        $server = $this->servers->find($id);

        if (!$server instanceof GameServer) {
            return $this->notFound();
        }

        if (preg_match('/^\d{1,20}$/', $workshopId) !== 1) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'mods.invalidId'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $outcome = $this->mods->change($server, $workshopId, $add);
        } catch (StorageException) {
            return new JsonResponse(
                ['status' => 'failed', 'error' => 'mods.transferFailed'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        // A write that could not be read back is a failure even though
        // nothing threw: the file may hold something other than asked.
        $status = match ($outcome['status']) {
            'written', 'alreadyInstalled', 'notInstalled' => Response::HTTP_OK,
            'keysMissing' => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_BAD_GATEWAY,
        };

        return new JsonResponse($outcome, $status);
    }

    /** Where this server's covers are served from. */
    private function coverBase(string $serverId): string
    {
        return '/api/servers/'.$serverId.'/mods';
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'failed', 'error' => 'errors.notFound'],
            Response::HTTP_NOT_FOUND,
        );
    }
}
