<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly IdentityLinker $linker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'api_connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clients->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($client, $accessToken): User {
                $googleUser = $client->fetchUserFromToken($accessToken);
                \assert($googleUser instanceof GoogleUser);

                return $this->resolveUser($googleUser);
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        if ($user instanceof User) {
            $user->recordLogin();
            $this->entityManager->flush();
        }

        return new RedirectResponse('/app');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $reason = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'auth.google.failed';

        return new RedirectResponse('/app/login?error='.urlencode($reason));
    }

    private function resolveUser(GoogleUser $googleUser): User
    {
        $user = $this->linker->findUser(OAuthIdentity::PROVIDER_GOOGLE, $googleUser->getId());

        if (!$user instanceof User) {
            // A Google account nobody linked cannot create an account here;
            // access is by invitation. Matching a verified address to an
            // existing account is what lets an invitee finish sign-up.
            $email = $googleUser->getEmail();

            $user = \is_string($email) ? $this->linker->findInvitedByEmail($email) : null;

            if (!$user instanceof User) {
                throw new CustomUserMessageAuthenticationException('auth.google.notLinked');
            }

            $this->linker->link(
                $user,
                OAuthIdentity::PROVIDER_GOOGLE,
                $googleUser->getId(),
                $googleUser->getName(),
            );
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAuthenticationException('auth.accountInactive');
        }

        return $user;
    }
}
