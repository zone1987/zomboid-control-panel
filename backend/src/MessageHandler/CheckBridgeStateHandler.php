<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckBridgeState;
use App\Repository\GameServerRepository;
use App\Server\Events\BridgeWatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckBridgeStateHandler
{
    public function __construct(
        private GameServerRepository $servers,
        private BridgeWatcher $watcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckBridgeState $message): void
    {
        foreach ($this->servers->findAll() as $server) {
            // No transfer credentials means nothing to read, and a
            // server nobody has finished setting up is not "down".
            if ($server->getFtpConfig() === null) {
                continue;
            }

            try {
                $this->watcher->check($server);
            } catch (\Throwable $exception) {
                // One server must not stop the rest being checked.
                $this->logger->warning('could not check a bridge', [
                    'server' => $server->getId()->toRfc4122(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
