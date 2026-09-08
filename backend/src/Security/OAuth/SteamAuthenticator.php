<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use xPaw\Steam\SteamOpenID;

/**
 * Steam is an OpenID 2.0 provider, not OAuth2, so this cannot go through
 * the OAuth client bundle.
 */
final class SteamAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly IdentityLinker $linker,
        private readonly EntityManagerInterface $entityManager,
        private readonly SteamProfileFetcher $profiles,
        private readonly SteamReturnUrl $returnUrls,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'api_connect_steam_check';
    }

    public function authenticate(Request $request): Passport
    {
        $openId = new SteamOpenID($this->returnUrl(), $request->query->all());

        if (!$openId->ShouldValidate()) {
            throw new CustomUserMessageAuthenticationException('auth.steam.incompleteResponse');
        }

        try {
            // Posts the parameters back to Steam with mode=check_authentication
            // and refuses anything Steam does not confirm. Trusting the URL
            // parameters without this round trip is an account takeover.
            $steamId = $openId->Validate();
        } catch (\InvalidArgumentException) {
            throw new CustomUserMessageAuthenticationException('auth.steam.invalidResponse');
        } catch (\Throwable $exception) {
            throw new CustomUserMessageAuthenticationException($this->classifyFailure($exception));
        }

        return new SelfValidatingPassport(
            new UserBadge($steamId, fn (string $id): User => $this->resolveUser($id)),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        if ($user instanceof User) {
            $user->recordLogin();
            $this->entityManager->flush();
        }

        return new RedirectResponse('/app/');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $reason = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'auth.steam.failed';

        return new RedirectResponse('/app/login?error='.urlencode($reason));
    }

    private function resolveUser(string $steamId): User
    {
        $user = $this->linker->findUser(OAuthIdentity::PROVIDER_STEAM, $steamId);

        if (!$user instanceof User) {
            // Access is by invitation; an unrecognised Steam account cannot
            // create one. It has to be linked from an existing session first.
            throw new CustomUserMessageAuthenticationException('auth.steam.notLinked');
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAuthenticationException('auth.accountInactive');
        }

        $this->profiles->refreshLabel($user, $steamId);

        return $user;
    }

    /**
     * Steam answering "not valid" is a rejected sign-in, not an outage;
     * telling the user to try again later would be wrong.
     */
    private function classifyFailure(\Throwable $exception): string
    {
        return str_contains($exception->getMessage(), 'could be down')
            || str_contains($exception->getMessage(), 'rate limits')
            ? 'auth.steam.unreachable'
            : 'auth.steam.rejected';
    }

    private function returnUrl(): string
    {
        return $this->returnUrls->forLogin();
    }
}
