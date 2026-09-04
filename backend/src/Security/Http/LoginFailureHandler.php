<?php

declare(strict_types=1);

namespace App\Security\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Wrong password and unknown account share one message so the
        // endpoint cannot be used to enumerate registered addresses.
        $key = $exception instanceof CustomUserMessageAccountStatusException
            ? 'auth.accountInactive'
            : 'auth.invalidCredentials';

        return new JsonResponse([
            'status' => 'failed',
            'error' => $key,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
