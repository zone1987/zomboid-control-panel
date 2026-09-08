<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Security\OAuth\IdentityAlreadyLinked;
use App\Security\OAuth\GoogleClientFactory;
use App\Security\OAuth\IdentityLinker;
use App\Security\OAuth\SteamProfileFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use xPaw\Steam\SteamOpenID;

#[Route('/api/connect')]
final class ConnectController extends AbstractController
{
    public function __construct(
        private readonly IdentityLinker $linker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/google', name: 'api_connect_google', methods: ['GET'])]
    public function google(GoogleClientFactory $clients): RedirectResponse
    {
        if (!$clients->isConfigured()) {
            return new RedirectResponse('/app/login?error=auth.google.notConfigured');
        }

        $provider = $clients->create();

        return new RedirectResponse(
            $provider->getAuthorizationUrl(['scope' => ['openid', 'profile', 'email']]),
        );
    }

    /**
     * Handled by GoogleAuthenticator; this only names the route.
     */
    #[Route('/google/check', name: 'api_connect_google_check', methods: ['GET'])]
    public function googleCheck(): never
    {
        throw new \LogicException('This is intercepted by GoogleAuthenticator.');
    }

    #[Route('/steam', name: 'api_connect_steam', methods: ['GET'])]
    public function steam(UrlGeneratorInterface $urls): RedirectResponse
    {
        $returnUrl = $urls->generate(
            'api_connect_steam_check',
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return new RedirectResponse((new SteamOpenID($returnUrl))->GetAuthUrl());
    }

    /**
     * Handled by SteamAuthenticator; this only names the route.
     */
    #[Route('/steam/check', name: 'api_connect_steam_check', methods: ['GET'])]
    public function steamCheck(): never
    {
        throw new \LogicException('This is intercepted by SteamAuthenticator.');
    }

    /**
     * Links Steam to the account already signed in. Unlike the login
     * route this runs behind the firewall, so the identity attaches to a
     * known user instead of having to resolve one.
     */
    #[Route('/steam/link', name: 'api_connect_steam_link', methods: ['GET'])]
    public function linkSteam(
        Request $request,
        UrlGeneratorInterface $urls,
        SteamProfileFetcher $profiles,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        $returnUrl = $urls->generate(
            'api_connect_steam_link',
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $openId = new SteamOpenID($returnUrl, $request->query->all());

        if (!$openId->ShouldValidate()) {
            return new RedirectResponse($openId->GetAuthUrl());
        }

        try {
            $steamId = $openId->Validate();
        } catch (\Throwable) {
            return new RedirectResponse('/app/profile?error=auth.steam.invalidResponse');
        }

        try {
            $this->linker->link(
                $user,
                OAuthIdentity::PROVIDER_STEAM,
                $steamId,
                $profiles->fetchPersonaName($steamId),
            );
        } catch (IdentityAlreadyLinked) {
            return new RedirectResponse('/app/profile?error=auth.identityTaken');
        }

        return new RedirectResponse('/app/profile?linked=steam');
    }

    /**
     * Linking Google to the signed-in account.
     *
     * Separate from `/google`, which is a *sign-in*: that route is
     * handled by GoogleAuthenticator, which authenticates whoever comes
     * back and lands them on `/app/`. Reusing it to link made a
     * successful link look like nothing had happened -- the operator was
     * simply returned to the dashboard.
     */
    #[Route('/google/link', name: 'api_connect_google_link', methods: ['GET'])]
    public function linkGoogle(
        Request $request,
        GoogleClientFactory $clients,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        if (!$clients->isConfigured()) {
            return new RedirectResponse('/app/profile?error=auth.google.notConfigured');
        }

        $provider = $clients->create();
        $code = $request->query->get('code');

        if (!\is_string($code) || $code === '') {
            return new RedirectResponse($provider->getAuthorizationUrl([
                'scope' => ['openid', 'profile', 'email'],
                'redirect_uri' => $this->googleLinkUri(),
            ]));
        }

        try {
            $token = $provider->getAccessToken('authorization_code', [
                'code' => $code,
                'redirect_uri' => $this->googleLinkUri(),
            ]);

            /** @var \League\OAuth2\Client\Provider\GoogleUser $googleUser */
            $googleUser = $provider->getResourceOwner($token);
        } catch (\Throwable) {
            return new RedirectResponse('/app/profile?error=auth.google.failed');
        }

        try {
            $this->linker->link(
                $user,
                OAuthIdentity::PROVIDER_GOOGLE,
                $googleUser->getId(),
                $googleUser->getName(),
            );
        } catch (IdentityAlreadyLinked) {
            return new RedirectResponse('/app/profile?error=auth.identityTaken');
        }

        return new RedirectResponse('/app/profile?linked=google');
    }

    /** Google checks this against the value used to start the flow. */
    private function googleLinkUri(): string
    {
        return $this->urls->generate(
            'api_connect_google_link',
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    #[Route('/{provider}', name: 'api_connect_unlink', methods: ['DELETE'])]
    public function unlink(string $provider, #[CurrentUser] User $user): JsonResponse
    {
        if (!\in_array($provider, [OAuthIdentity::PROVIDER_GOOGLE, OAuthIdentity::PROVIDER_STEAM], true)) {
            return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->linker->canUnlink($user, $provider)) {
            return new JsonResponse([
                'status' => 'failed',
                'error' => 'auth.lastSignInMethod',
            ], Response::HTTP_CONFLICT);
        }

        if (!$this->linker->unlink($user, $provider)) {
            return new JsonResponse(['status' => 'failed', 'error' => 'errors.notFound'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['status' => 'unlinked']);
    }

    #[Route('', name: 'api_connect_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $items = $user->getOauthIdentities()
            ->map(static fn (OAuthIdentity $i): array => [
                'provider' => $i->getProvider(),
                'label' => $i->getProviderLabel(),
                'linkedAt' => $i->getLinkedAt()->format(\DateTimeInterface::ATOM),
            ])
            ->getValues();

        return new JsonResponse(['items' => $items]);
    }
}
