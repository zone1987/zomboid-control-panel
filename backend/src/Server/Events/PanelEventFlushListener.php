<?php

declare(strict_types=1);

namespace App\Server\Events;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;

/**
 * Releases collected events once Doctrine has actually committed.
 *
 * `postFlush` rather than `onFlush`: at `onFlush` the transaction can
 * still fail, and announcing a ban that was rolled back is worse than
 * announcing it late.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final readonly class PanelEventFlushListener
{
    public function __construct(private PanelEventDispatcher $dispatcher)
    {
    }

    public function postFlush(): void
    {
        $this->dispatcher->release();
    }
}
