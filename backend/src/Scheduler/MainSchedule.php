<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\CheckBridgeState;
use App\Message\DeployNewRelease;
use App\Message\LiftExpiredBans;
use App\Message\MirrorChatToDiscord;
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
        )->add(
            // Chat is read over FTP, so this is a poll rather than a
            // stream. Twenty seconds keeps a conversation readable
            // without a connection per line; it does nothing at all for
            // a server with no Discord channel chosen.
            RecurringMessage::every('20 seconds', new MirrorChatToDiscord()),
        )->add(
            // Longer than the bridge's own 120-second staleness window,
            // so a single slow write cannot look like an outage. Only a
            // change is announced, never the state.
            RecurringMessage::every('60 seconds', new CheckBridgeState()),
        )->add(
            // Fires at the shortest interval the interface offers; the
            // handler decides whether the operator's chosen one has
            // elapsed, so changing it needs no restart. Does nothing at
            // all unless they switched deployment on.
            RecurringMessage::every('5 minutes', new DeployNewRelease()),
        );
    }
}
