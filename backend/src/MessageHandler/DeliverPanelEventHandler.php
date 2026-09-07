<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Server\Discord\DiscordException;
use App\Server\Discord\EventNotifier;
use App\Server\Events\DeliverPanelEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Hands one event to everybody who announces things.
 *
 * Discord today; the notification bell joins here rather than growing a
 * second stream. A subscriber that throws is retried by Messenger — a
 * rate limit or a brief outage is exactly what retrying is for — while
 * one that fails permanently must not take the others down with it.
 */
#[AsMessageHandler]
final readonly class DeliverPanelEventHandler
{
    public function __construct(
        private EventNotifier $discord,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeliverPanelEvent $message): void
    {
        try {
            $this->discord->announce($message->event);
        } catch (DiscordException $exception) {
            // Rate limits and outages are worth retrying; a refusal
            // because the token is wrong is not, but telling them apart
            // reliably is guesswork, so everything is retried and the
            // log says what happened.
            $this->logger->warning('could not announce an event in discord', [
                'type' => $message->event->type,
                'error' => $exception->messageKey(),
            ]);

            throw $exception;
        }
    }
}
