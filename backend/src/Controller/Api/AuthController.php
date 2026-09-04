<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Security\Http\UserPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthController extends AbstractController
{
    /**
     * Handled entirely by the json_login authenticator; this exists so the
     * route has a name the firewall can point check_path at.
     */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('This is intercepted by the json_login authenticator.');
    }

    /** Handled by the two_factor listener. */
    #[Route('/api/2fa', name: 'api_2fa_check', methods: ['POST'])]
    public function twoFactorCheck(): never
    {
        throw new \LogicException('This is intercepted by the two-factor listener.');
    }

    /** Handled by the logout listener. */
    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This is intercepted by the logout listener.');
    }

    /**
     * The signed-in user's own profile. Unlike /api/session this is behind
     * the firewall, so it answers 401 when the session has expired.
     */
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(UserPayload::from($user));
    }

    /**
     * Tells the SPA who is signed in, without ever returning a 401 — the
     * frontend needs a plain answer to decide which screen to render.
     */
    #[Route('/api/session', name: 'api_session', methods: ['GET'])]
    public function session(#[CurrentUser] ?User $user): JsonResponse
    {
        return new JsonResponse([
            'authenticated' => $user !== null,
            'user' => $user !== null ? UserPayload::from($user) : null,
        ]);
    }
}
