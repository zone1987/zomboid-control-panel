<?php

declare(strict_types=1);

namespace App\Server\Events;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hands each event to whoever is listening, after it is committed.
 *
 * **Nothing is sent before the flush.** `ModerationRecorder::add()`
 * persists without flushing — the batch callers flush once at the end —
 * so a notifier reading a queued action would announce something that
 * may still be rolled back. Events are therefore collected here and
 * released by `PanelEventFlushListener` once Doctrine has committed.
 *
 * Delivery goes through Messenger rather than being done inline: a
 * Discord outage must not fail the kick that triggered the message, and
 * a message worth sending is worth retrying.
 */
final class PanelEventDispatcher implements ResetInterface
{
    /** @var list<PanelEvent> */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Queues an event for release after the current flush. */
    public function collect(PanelEvent $event): void
    {
        $this->pending[] = $event;
    }

    /**
     * Sends an event that depends on nothing being written.
     *
     * For the ones that are not about a persisted row at all — the
     * bridge going quiet, a server becoming unreachable.
     */
    public function dispatch(PanelEvent $event): void
    {
        $this->send($event);
    }

    /** Called once Doctrine has committed. */
    public function release(): void
    {
        $events = $this->pending;
        $this->pending = [];

        foreach ($events as $event) {
            $this->send($event);
        }
    }

    /** A rolled-back transaction must not leave events waiting. */
    public function discard(): void
    {
        $this->pending = [];
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    /** @return list<PanelEvent> what is waiting, for a test to inspect */
    public function pending(): array
    {
        return $this->pending;
    }

    private function send(PanelEvent $event): void
    {
        try {
            $this->bus->dispatch(new DeliverPanelEvent($event));
        } catch (\Throwable $exception) {
            // The action itself already happened and is recorded; losing
            // its announcement must not turn that into a failure.
            $this->logger->error('could not queue a panel event', [
                'type' => $event->type,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
