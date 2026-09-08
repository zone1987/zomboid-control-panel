<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\OAuth;

use App\Security\OAuth\SteamReturnUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where Steam sends the reader back to.
 *
 * Production showed `openid.return_to=http://zomboid.andreas-gerhardt.com/…`
 * while the site serves HTTPS: the URL was generated from the request,
 * and behind a proxy the request arrives over plain HTTP. CLAUDE.md 10j
 * says build it from APP_PUBLIC_URL, and Google and Discord already do.
 */
final class SteamReturnUrlTest extends TestCase
{
    public function testBuildsTheAddressFromTheConfiguredPublicUrl(): void
    {
        self::assertSame(
            'https://zomboid.example.com/api/connect/steam/check',
            $this->urlFor('https://zomboid.example.com')->forLogin(),
        );
    }

    /** The one that was broken: never http when the panel is https. */
    public function testNeverHandsSteamAnUnencryptedReturnAddress(): void
    {
        $returned = $this->urlFor('https://zomboid.example.com')->forLogin();

        self::assertStringStartsWith('https://', $returned);
        self::assertStringNotContainsString('http://', $returned);
    }

    public function testDoesNotAskTheRequestForTheHost(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())
            ->method('generate')
            ->with('api_connect_steam_check', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/api/connect/steam/check');

        $returned = (new SteamReturnUrl($urls, 'https://zomboid.example.com'))->forLogin();

        self::assertSame('https://zomboid.example.com/api/connect/steam/check', $returned);
    }

    public function testTrimsATrailingSlashRatherThanDoublingIt(): void
    {
        self::assertSame(
            'https://zomboid.example.com/api/connect/steam/check',
            $this->urlFor('https://zomboid.example.com/')->forLogin(),
        );
    }

    /** A public address entered without a scheme is not a URL. */
    public function testAssumesHttpsWhenTheSchemeIsMissing(): void
    {
        self::assertSame(
            'https://zomboid.example.com/api/connect/steam/check',
            $this->urlFor('zomboid.example.com')->forLogin(),
        );
    }

    public function testKeepsAnExplicitHttpForALocalSetup(): void
    {
        self::assertSame(
            'http://localhost:8080/api/connect/steam/check',
            $this->urlFor('http://localhost:8080')->forLogin(),
        );
    }

    /** Linking and logging in are different routes and must stay so. */
    public function testLinkingUsesItsOwnRoute(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route): string => '/api/connect/'.str_replace('api_connect_', '', $route),
        );

        $builder = new SteamReturnUrl($urls, 'https://zomboid.example.com');

        self::assertNotSame($builder->forLogin(), $builder->forLinking());
        self::assertStringContainsString('steam_link', $builder->forLinking());
    }

    /** With nothing configured the request is all there is. */
    public function testFallsBackToTheRequestWhenNoPublicUrlIsSet(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())
            ->method('generate')
            ->with('api_connect_steam_check', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('http://container.internal/api/connect/steam/check');

        self::assertSame(
            'http://container.internal/api/connect/steam/check',
            (new SteamReturnUrl($urls, ''))->forLogin(),
        );
    }

    private function urlFor(string $publicUrl): SteamReturnUrl
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/api/connect/steam/check');

        return new SteamReturnUrl($urls, $publicUrl);
    }
}
