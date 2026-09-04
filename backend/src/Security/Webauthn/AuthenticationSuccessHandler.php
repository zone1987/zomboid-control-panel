<?php

declare(strict_types=1);

namespace App\Security\Webauthn;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use App\Security\Http\UserPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Webauthn\Bundle\Security\Authentication\Token\WebauthnToken;

/**
 * Runs after a passkey login on the firewall.
 *
 * This implements Symfony's handler interface, not the bundle's own
 * SuccessHandler: the firewall and the registration controllers expect
 * different contracts.
 */
final readonly class AuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private WebauthnCredentialRepository $credentials,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();

        if ($user instanceof User) {
            $user->recordLogin();
        }

        if ($token instanceof WebauthnToken) {
            $credential = $this->credentials->findOneBy([
                'publicKeyCredentialId' => $token->getPublicKeyCredentialDescriptor()->id,
            ]);

            if ($credential instanceof WebauthnCredential) {
                $credential->recordUse();
            }
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'authenticated',
            'twoFactorComplete' => true,
            'user' => $user instanceof User ? UserPayload::from($user) : null,
        ]);
    }
}
