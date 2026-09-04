<?php

declare(strict_types=1);

namespace App\Security\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Keeps unauthenticated API calls as JSON 401s instead of redirects,
 * so the SPA can route to the login screen itself.
 */
final readonly class ApiEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse([
            'status' => 'unauthenticated',
            'error' => 'auth.required',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
