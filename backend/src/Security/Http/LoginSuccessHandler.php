<?php

declare(strict_types=1);

namespace App\Security\Http;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        // A TwoFactorToken means the password was accepted but the login is
        // only half done; the session carries that state until /api/2fa.
        if ($token instanceof TwoFactorTokenInterface) {
            return new JsonResponse([
                'status' => 'two_factor_required',
                'twoFactorComplete' => false,
                'availableProviders' => $token->getTwoFactorProviders(),
            ], Response::HTTP_OK);
        }

        $user = $token->getUser();

        if ($user instanceof User) {
            $user->recordLogin();
            $this->entityManager->flush();
        }

        return new JsonResponse([
            'status' => 'authenticated',
            'twoFactorComplete' => true,
            'user' => $user instanceof User ? UserPayload::from($user) : null,
        ]);
    }
}
