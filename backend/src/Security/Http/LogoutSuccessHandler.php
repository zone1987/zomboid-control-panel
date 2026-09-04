<?php

declare(strict_types=1);

namespace App\Security\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Answers the logout with JSON; the default redirect leaves an SPA
 * waiting for a navigation that never comes.
 */
final readonly class LogoutSuccessHandler
{
    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(['status' => 'signed_out']));
    }
}
