<?php

declare(strict_types=1);

namespace App\Security\Webauthn;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webauthn\Bundle\Security\Handler\FailureHandler;

final readonly class AuthenticationFailureHandler implements FailureHandler
{
    public function onFailure(Request $request, ?\Throwable $exception = null): Response
    {
        return new JsonResponse([
            'status' => 'failed',
            'error' => 'passkey.failed',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
