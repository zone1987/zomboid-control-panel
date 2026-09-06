<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\LiftExpiredBans;
use App\Message\PurgeStalePlayers;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('main')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->add(
            // A minute is close enough for ban durations measured in hours,
            // and cheap: it does nothing at all when no ban is due.
            RecurringMessage::every('1 minute', new LiftExpiredBans()),
        )->add(
            // Nothing happens while retention is off, which is the default.
            RecurringMessage::every('1 day', new PurgeStalePlayers()),
        );
    }
}
