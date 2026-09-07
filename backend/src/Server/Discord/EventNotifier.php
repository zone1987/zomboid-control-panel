<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Repository\GameServerRepository;
use App\Server\Events\PanelEvent;
use Psr\Log\LoggerInterface;

/**
 * Announces one event in Discord, if the operator asked for it.
 *
 * Every decision is the operator's and every default is silence: an
 * event with no row is not announced, a row without a channel is not
 * announced, and a row switched off is not announced. Only an explicit
 * yes produces a message.
 */
final readonly class EventNotifier
{
    public function __construct(
        private NotificationSettings $notifications,
        private GameServerRepository $servers,
        private DiscordClientInterface $discord,
        private MessageTemplate $templates,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DiscordException so Messenger can retry a transient failure
     */
    public function announce(PanelEvent $event): void
    {
        $setting = $this->notifications->forEvent($event->serverId, $event->type);

        if ($setting === null || !$setting->isActive()) {
            return;
        }

        $server = $this->servers->find($event->serverId);

        if ($server?->getDiscordConfig() === null) {
            // Configured for a server whose Discord link has since been
            // removed: not an error, just nothing to do.
            return;
        }

        $template = $setting->getTemplate()
            ?? NotifiableEvents::defaultTemplate($event->type);

        if ($template === null || trim($template) === '') {
            $this->logger->warning('a discord notification has no wording at all', [
                'server' => $event->serverId,
                'type' => $event->type,
            ]);

            return;
        }

        $content = $this->templates->render($template, $event->tokens);

        if (trim($content) === '') {
            return;
        }

        $this->discord->sendMessage((string) $setting->getChannelId(), new DiscordMessage($content));
    }
}
