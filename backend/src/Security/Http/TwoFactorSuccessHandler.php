<?php

declare(strict_types=1);

namespace App\Security\Http;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class TwoFactorSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
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
