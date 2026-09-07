<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\Permission\Permission;
use App\Server\Items\Icons\IconStore;
use App\Server\Players\Character\CharacterDefinitions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the game's own character sheet knows: professions and traits.
 *
 * The table comes from the installation's generated script files rather
 * than from anything typed here, so a name, a cost or an XP boost cannot
 * drift from the game. The artwork is the game's too, extracted from the
 * operator's own copy and kept out of git.
 */
#[Route('/api/character')]
#[IsGranted(Permission::ViewPlayers->value)]
final class CharacterSheetController extends AbstractController
{
    public function __construct(private readonly IconStore $characterStore)
    {
    }

    /**
     * Every profession and trait, with its cost, boosts and icon.
     *
     * `iconsAvailable` is the honest part: without the extracted
     * artwork the interface has to fall back to initials, and it should
     * know that rather than showing 122 broken images.
     */
    #[Route('', name: 'api_character_definitions', methods: ['GET'])]
    public function definitions(): JsonResponse
    {
        $held = $this->characterStore->count();

        return new JsonResponse([
            'professions' => CharacterDefinitions::PROFESSIONS,
            'traits' => CharacterDefinitions::TRAITS,
            'iconsAvailable' => $held > 0,
            'iconCount' => $held,
        ]);
    }

    #[Route(
        '/icons/{name}.png',
        name: 'api_character_icon',
        methods: ['GET'],
        requirements: ['name' => '[A-Za-z0-9_.-]{1,150}'],
    )]
    public function icon(string $name, Request $request): Response
    {
        $png = $this->characterStore->get($name);

        if ($png === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($png, Response::HTTP_OK, ['Content-Type' => 'image/png']);

        // The picture never changes under the same name; only a
        // re-extraction replaces it.
        $response->setEtag(md5($png));
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->isNotModified($request);

        return $response;
    }
}
