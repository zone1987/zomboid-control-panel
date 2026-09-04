<?php

declare(strict_types=1);

namespace App\Security\Http;

use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Answers requests made while the second factor is still outstanding.
 */
final readonly class TwoFactorRequiredHandler implements AuthenticationRequiredHandlerInterface
{
    public function onAuthenticationRequired(Request $request, TokenInterface $token): Response
    {
        return new JsonResponse([
            'status' => 'two_factor_required',
            'twoFactorComplete' => false,
            'error' => 'access_denied',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
