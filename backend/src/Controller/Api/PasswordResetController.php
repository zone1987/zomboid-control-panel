<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\PasswordReset\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/password-reset')]
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $reset,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_password_reset_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        $email = $request->toArray()['email'] ?? null;

        if (\is_string($email)) {
            $this->reset->request($email);
        }

        // The same answer either way: telling the caller whether an address
        // exists would make this an account-enumeration endpoint.
        return new JsonResponse(['status' => 'sent']);
    }

    #[Route('/{token}', name: 'api_password_reset_inspect', methods: ['GET'])]
    public function inspect(string $token): JsonResponse
    {
        return new JsonResponse(
            ['valid' => $this->reset->findUsableToken($token) !== null],
            $this->reset->findUsableToken($token) === null ? Response::HTTP_NOT_FOUND : Response::HTTP_OK,
        );
    }

    #[Route('/{token}', name: 'api_password_reset_complete', methods: ['POST'])]
    public function complete(string $token, Request $request): JsonResponse
    {
        $record = $this->reset->findUsableToken($token);

        if ($record === null) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'passwordReset.linkInvalid',
            ], Response::HTTP_NOT_FOUND);
        }

        $password = $request->toArray()['password'] ?? null;

        $violations = $this->validator->validate($password, [
            new Assert\NotBlank(),
            new Assert\Length(min: 12, max: 4096),
        ]);

        if ($violations->count() > 0) {
            return new JsonResponse([
                'status' => 'failed',
                'errors' => ['password' => 'validation.passwordTooShort'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->reset->reset($record, (string) $password);

        return new JsonResponse(['status' => 'reset']);
    }
}
