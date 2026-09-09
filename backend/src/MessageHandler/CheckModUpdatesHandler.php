<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckModUpdates;
use App\Repository\GameServerRepository;
use App\Server\Mods\ModUpdateWatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckModUpdatesHandler
{
    public function __construct(
        private GameServerRepository $servers,
        private ModUpdateWatcher $watcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckModUpdates $message): void
    {
        foreach ($this->servers->findAll() as $server) {
            // Nothing to read without transfer credentials, and a server
            // nobody finished setting up has no mods to be out of date.
            if ($server->getFtpConfig() === null) {
                continue;
            }

            try {
                $this->watcher->check($server);
            } catch (\Throwable $exception) {
                // One server must not stop the rest being checked.
                $this->logger->warning('could not check mod updates', [
                    'server' => $server->getId()->toRfc4122(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
