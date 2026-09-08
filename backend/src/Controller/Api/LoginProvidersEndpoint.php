<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AppSetting;
use App\Repository\OAuthIdentityRepository;
use App\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Which sign-in providers the login page should offer.
 *
 * A button for a provider nobody has linked leads nowhere: the account
 * is matched by its provider id, so an unlinked provider can only ever
 * answer "no such account". Offering it invites the one failure the
 * login page cannot explain.
 *
 * Linking happens under Account, where the buttons are always available
 * -- so this narrows what is shown before a login, never what an
 * operator can set up.
 */
final class LoginProvidersEndpoint extends AbstractController
{
    public function __construct(
        private readonly OAuthIdentityRepository $identities,
        private readonly SettingsProvider $settings,
    ) {
    }

    #[Route('/api/login/providers', name: 'api_login_providers', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse([
            // Google needs credentials as well: without them the redirect
            // cannot even be built.
            'google' => $this->settings->isConfigured(AppSetting::GOOGLE_CLIENT_ID)
                && $this->settings->isConfigured(AppSetting::GOOGLE_CLIENT_SECRET)
                && $this->hasLink('google'),

            // Steam signs in over OpenID and needs no key. The Steam API
            // key is only for reading names and avatars afterwards, so it
            // is deliberately not part of this.
            'steam' => $this->hasLink('steam'),
        ]);
    }

    private function hasLink(string $provider): bool
    {
        return $this->identities->count(['provider' => $provider]) > 0;
    }
}
