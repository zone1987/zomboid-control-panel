<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Server\Items\Icons\IconStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/icons')]
#[IsGranted('ROLE_USER')]
final class IconController extends AbstractController
{
    public function __construct(private readonly IconStore $store)
    {
    }

    /** How many icons are held, so the interface knows whether to show them. */
    #[Route('', name: 'api_icons_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $count = $this->store->count();

        return new JsonResponse(['count' => $count, 'available' => $count > 0]);
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
