<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\AppSetting;
use App\Settings\SettingsProvider;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\AbstractProvider;
use Symfony\Component\DependencyInjection\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The OAuth client bundle resolves its credentials from configuration at
 * container build time. Credentials edited through the interface are only
 * known at runtime, so they are pushed onto the provider before use.
 */
final readonly class GoogleClientConfigurator
{
    public function __construct(
        private ClientRegistry $clients,
        private SettingsProvider $settings,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (!\in_array($route, ['api_connect_google', 'api_connect_google_check'], true)) {
            return;
        }

        $clientId = $this->settings->get(AppSetting::GOOGLE_CLIENT_ID);
        $clientSecret = $this->settings->get(AppSetting::GOOGLE_CLIENT_SECRET);

        if ($clientId === null || $clientSecret === null) {
            return;
        }

        $provider = $this->clients->getClient('google')->getOAuth2Provider();

        $this->overwrite($provider, 'clientId', $clientId);
        $this->overwrite($provider, 'clientSecret', $clientSecret);
    }

    private function overwrite(AbstractProvider $provider, string $property, string $value): void
    {
        $reflection = new \ReflectionProperty(AbstractProvider::class, $property);
        $reflection->setValue($provider, $value);
    }
}
