<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testSetsTheHeadersEveryResponseNeeds(): void
    {
        $response = $this->respondTo(Request::create('https://panel.test/api/health'));

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame(
            'strict-origin-when-cross-origin',
            $response->headers->get('Referrer-Policy'),
        );
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
        self::assertNotNull($response->headers->get('Permissions-Policy'));
    }

    /**
     * The policy has to allow what the panel actually loads: the map's
     * tiles from another origin, and the vehicle renderer's blob output.
     */
    public function testThePolicyAllowsTheMapTilesAndTheRenderer(): void
    {
        $policy = (string) $this->respondTo(
            Request::create('https://panel.test/'),
        )->headers->get('Content-Security-Policy');

        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString('https://tiles.projectzomboidmap.com', $policy);
        self::assertStringContainsString('blob:', $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
    }

    /** Sent over plain HTTP it is ignored, and it would pin a dev host. */
    public function testSendsStrictTransportSecurityOnlyOverHttps(): void
    {
        self::assertNotNull(
            $this->respondTo(Request::create('https://panel.test/'))
                ->headers->get('Strict-Transport-Security'),
        );

        self::assertNull(
            $this->respondTo(Request::create('http://panel.test/'))
                ->headers->get('Strict-Transport-Security'),
        );
    }

    public function testCanBeTurnedOffForAnInstallationWithoutTls(): void
    {
        $response = $this->respondTo(Request::create('https://panel.test/'), https: false);

        self::assertNull($response->headers->get('Strict-Transport-Security'));
        // The rest still applies: only the pin depends on TLS.
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** A sub-request must not restate what the main response already carries. */
    public function testLeavesSubRequestsAlone(): void
    {
        $subscriber = new SecurityHeadersSubscriber();
        $response = new Response();

        $subscriber->onResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('https://panel.test/'),
            HttpKernelInterface::SUB_REQUEST,
            $response,
        ));

        self::assertNull($response->headers->get('Content-Security-Policy'));
    }

    private function respondTo(Request $request, bool $https = true): Response
    {
        $response = new Response();

        (new SecurityHeadersSubscriber($https))->onResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        return $response;
    }
}
