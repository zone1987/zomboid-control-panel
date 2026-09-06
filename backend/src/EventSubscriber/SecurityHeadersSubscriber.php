<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets the security headers on every response.
 *
 * Here rather than in the web server: the panel is deployed behind
 * whatever Docker or Coolify provides, so a header configured in one
 * installation's vhost would be absent in the next.
 */
final readonly class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /**
     * The map policy needs its tiles, and the vehicle renderer needs
     * WebGL from a blob worker; everything else stays on our own origin.
     */
    private const POLICY = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
        // Vite injects styles inline, and Tailwind's generated CSS uses
        // style attributes.
        "style-src 'self' 'unsafe-inline'",
        "script-src 'self'",
        "worker-src 'self' blob:",
        // Tiles come from projectzomboidmap.com; blob: is the renderer's
        // own output, data: the fallback icons.
        'img-src '.self::IMAGE_SOURCES,
        'connect-src '.self::CONNECT_SOURCES,
        "font-src 'self' data:",
        'upgrade-insecure-requests',
    ];

    private const IMAGE_SOURCES = "'self' data: blob: https://tiles.projectzomboidmap.com";

    private const CONNECT_SOURCES = "'self' https://tiles.projectzomboidmap.com";

    public function __construct(private bool $https = true)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        // ddev sets this in development; production has no such helper.
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Content-Security-Policy', implode('; ', self::POLICY));

        // Only over a secure connection: sent over plain HTTP it would be
        // ignored, and in local development it would pin the dev host.
        if ($this->https && $event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
