<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\AppSetting;
use App\Settings\SettingsProvider;
use League\OAuth2\Client\Provider\Google;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the Google provider from credentials stored at runtime.
 *
 * The OAuth client bundle resolves its configuration when the container
 * is compiled, which cannot see values an administrator enters later, so
 * the provider is constructed here instead.
 */
final class GoogleClientFactory
{
    private ?Google $provider = null;

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly string $publicUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== null && $this->clientSecret() !== null;
    }

    public function create(): Google
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        if ($clientId === null || $clientSecret === null) {
            throw new GoogleNotConfigured();
        }

        return $this->provider ??= new Google([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $this->redirectUri(),
        ]);
    }

    /**
     * Built from the configured public URL, not the incoming request: a dev
     * server on another port would produce a URI Google rejects.
     */
    public function redirectUri(): string
    {
        return rtrim($this->publicUrl, '/').$this->urls->generate('api_connect_google_check');
    }

    private function clientId(): ?string
    {
        return $this->settings->get(AppSetting::GOOGLE_CLIENT_ID);
    }

    private function clientSecret(): ?string
    {
        return $this->settings->get(AppSetting::GOOGLE_CLIENT_SECRET);
    }
}
