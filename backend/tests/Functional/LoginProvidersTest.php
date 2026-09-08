<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSetting;
use App\Entity\OAuthIdentity;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The login page offers only providers that can actually sign somebody in.
 *
 * A provider button matches an account by its provider id, so one nobody
 * has linked can only ever answer "no such account" -- the one failure
 * the login page has no way to explain. Linking stays available under
 * Account, so this narrows what is offered before a login and never what
 * an operator can set up.
 */
final class LoginProvidersTest extends FunctionalTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clearDatabase();
    }

    public function testOffersNothingOnAFreshInstallation(): void
    {
        $providers = $this->fetch();

        self::assertFalse($providers['google']);
        self::assertFalse($providers['steam']);
    }

    /**
     * Steam signs in over OpenID and needs no API key -- that key only
     * reads names and avatars afterwards. So a linked account is the
     * whole condition.
     */
    public function testOffersSteamOnceAnAccountIsLinked(): void
    {
        $this->linkIdentity('steam', '76561198000000000');

        self::assertTrue($this->fetch()['steam']);
    }

    /**
     * Google is the other way round: without credentials the redirect
     * cannot even be built, so a linked account alone is not enough.
     */
    public function testDoesNotOfferGoogleWithoutCredentials(): void
    {
        $this->linkIdentity('google', 'google-user-1');

        self::assertFalse($this->fetch()['google'], 'a link without credentials leads nowhere');
    }

    public function testDoesNotOfferGoogleWithCredentialsButNoLink(): void
    {
        $this->configureGoogle();

        self::assertFalse($this->fetch()['google'], 'nobody could sign in with it yet');
    }

    public function testOffersGoogleWithBoth(): void
    {
        $this->configureGoogle();
        $this->linkIdentity('google', 'google-user-1');

        self::assertTrue($this->fetch()['google']);
    }

    /** The endpoint answers before anybody is signed in; that is the point. */
    public function testIsReachableWithoutSigningIn(): void
    {
        $this->client->request('GET', '/api/login/providers');

        self::assertResponseIsSuccessful();
    }

    private function configureGoogle(): void
    {
        foreach ([AppSetting::GOOGLE_CLIENT_ID => 'an-id', AppSetting::GOOGLE_CLIENT_SECRET => 'a-secret'] as $name => $value) {
            $this->em->persist(new AppSetting($name, $value));
        }

        $this->em->flush();
    }

    private function linkIdentity(string $provider, string $providerUserId): void
    {
        $user = new User(sprintf('%s@example.com', $provider), 'Test User');
        $user->setRoles([User::ROLE_USER]);
        $user->setPassword('irrelevant-here');

        $this->em->persist($user);
        $this->em->persist(new OAuthIdentity($user, $provider, $providerUserId));
        $this->em->flush();
    }

    /** @return array{google: bool, steam: bool} */
    private function fetch(): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING | E_NOTICE);

        try {
            $this->client->request('GET', '/api/login/providers');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
