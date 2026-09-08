<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where Steam sends the reader back to.
 *
 * Built from APP_PUBLIC_URL rather than the request: behind a proxy the
 * request arrives over plain HTTP, so an absolute URL generated from it
 * carries `http://` and an unencrypted return address -- which Steam
 * then shows to the reader as the site asking them to sign in.
 *
 * OpenID compares `return_to` on the request against the one presented
 * at verification, so all three call sites must agree. That is why this
 * is one class rather than three identical lines.
 */
final readonly class SteamReturnUrl
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private string $publicUrl,
    ) {
    }

    public function forLogin(): string
    {
        return $this->of('api_connect_steam_check');
    }

    public function forLinking(): string
    {
        return $this->of('api_connect_steam_link');
    }

    private function of(string $route): string
    {
        $base = rtrim(trim($this->publicUrl), '/');

        // Without a configured public address the request is all there
        // is; the absolute URL is then wrong behind a proxy, but a
        // relative one would not be a return address at all.
        if ($base === '') {
            return $this->urls->generate($route, referenceType: UrlGeneratorInterface::ABSOLUTE_URL);
        }

        // A public address without a scheme would otherwise become
        // "example.com/api/..." -- not a URL at all.
        if (preg_match('#^https?://#i', $base) !== 1) {
            $base = 'https://'.$base;
        }

        return $base.$this->urls->generate($route);
    }
}
