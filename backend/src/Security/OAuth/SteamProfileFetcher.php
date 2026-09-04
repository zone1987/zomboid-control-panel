<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\AppSetting;
use App\Entity\OAuthIdentity;
use App\Entity\User;
use App\Repository\OAuthIdentityRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Settings\SettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The OpenID response carries no profile data, only the SteamID. The
 * display name comes from the Web API, which needs a key; without one
 * the account simply keeps its numeric label.
 */
final class SteamProfileFetcher
{
    private const ENDPOINT = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/';

    public function __construct(
        private HttpClientInterface $http,
        private OAuthIdentityRepository $identities,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private SettingsProvider $settings,
    ) {
    }

    public function fetchPersonaName(string $steamId): ?string
    {
        $apiKey = $this->settings->get(AppSetting::STEAM_API_KEY);

        if ($apiKey === null) {
            return null;
        }

        try {
            $response = $this->http->request('GET', self::ENDPOINT, [
                'query' => ['key' => $apiKey, 'steamids' => $steamId],
                'timeout' => 5,
            ]);

            $players = $response->toArray(false)['response']['players'] ?? [];
        } catch (\Throwable $exception) {
            $this->logger->info('Steam profile lookup failed.', ['exception' => $exception]);

            return null;
        }

        $name = $players[0]['personaname'] ?? null;

        return \is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Keeps the stored label current, but never at the cost of the login:
     * a failed lookup leaves the previous value in place.
     */
    public function refreshLabel(User $user, string $steamId): void
    {
        $name = $this->fetchPersonaName($steamId);

        if ($name === null) {
            return;
        }

        $identity = $this->identities->findOneBy([
            'user' => $user,
            'provider' => OAuthIdentity::PROVIDER_STEAM,
        ]);

        if ($identity instanceof OAuthIdentity && $identity->getProviderLabel() !== $name) {
            $identity->setProviderLabel($name);
            $this->entityManager->flush();
        }
    }
}
