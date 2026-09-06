<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeStalePlayers;
use App\Server\Players\StalePlayerPurger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeStalePlayersHandler
{
    public function __construct(private StalePlayerPurger $purger)
    {
    }

    public function __invoke(PurgeStalePlayers $message): void
    {
        $this->purger->run();
    }
}
