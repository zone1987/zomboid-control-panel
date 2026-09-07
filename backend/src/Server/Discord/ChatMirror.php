<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\DiscordConfig;
use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Chat\ChatLine;
use App\Server\Logs\LogFileFinder;
use App\Server\Logs\LogTailer;
use App\Server\Storage\StorageException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors public game chat into Discord.
 *
 * **An allow-list, and the reason is in the code rather than in a
 * document**: faction, safehouse, radio, admin and whisper chat are
 * private in the game and must stay private in Discord. A channel this
 * build does not recognise counts as private too — the safe direction
 * for something nobody has classified is silence.
 *
 * Polled rather than streamed, like everything else here: the chat log
 * is read over FTP, and a long-lived connection would hold an FPM
 * worker (`docker/apache-vhost.conf:5`). The position is remembered so
 * a line is sent once, and a first run sends nothing at all — starting
 * up should not replay an hour of chat into a channel.
 */
final readonly class ChatMirror
{
    /** A run sends at most this many, so a backlog cannot flood a channel. */
    private const MAX_PER_RUN = 20;

    public function __construct(
        private GameServerRepository $servers,
        private LogFileFinder $finder,
        private LogTailer $tailer,
        private DiscordClientInterface $discord,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /** @return int how many lines were sent */
    public function run(): int
    {
        $sent = 0;

        foreach ($this->servers->findAll() as $server) {
            $config = $server->getDiscordConfig();

            if ($config === null || !$config->mirrorsGameChat()) {
                continue;
            }

            try {
                $sent += $this->mirror($server, $config);
            } catch (StorageException|DiscordException $exception) {
                // One server being unreachable must not stop the others.
                $this->logger->warning('could not mirror chat', [
                    'server' => $server->getId()->toRfc4122(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * @throws StorageException
     * @throws DiscordException
     */
    private function mirror(GameServer $server, DiscordConfig $config): int
    {
        $ftp = $server->getFtpConfig();

        if ($ftp === null) {
            return 0;
        }

        $chat = $this->finder->findAll($ftp)['chat'] ?? null;

        if ($chat === null) {
            return 0;
        }

        $marker = $this->cache->getItem('discord.chat.'.$server->getId()->toRfc4122());

        /** @var array{file: string, offset: int}|null $seen */
        $seen = $marker->isHit() ? $marker->get() : null;

        // A different file means the server restarted and the old offset
        // means nothing -- the same reading the chat page uses.
        $from = $seen !== null && $seen['file'] === $chat['name'] ? $seen['offset'] : null;

        $result = $this->tailer->read($ftp, $chat['path'], $from);

        $marker->set(['file' => $chat['name'], 'offset' => $result['offset']]);
        $this->cache->save($marker);

        // First run for this server: remember where the log is now and
        // send nothing. Starting up should not replay an hour of chat
        // into a channel.
        if ($from === null) {
            return 0;
        }

        $lines = array_map(ChatLine::parse(...), $result['lines']);
        $sent = 0;

        foreach (array_slice($lines, -self::MAX_PER_RUN) as $line) {
            if (!$this->mayLeave($line, $config)) {
                continue;
            }

            $this->discord->sendMessage(
                (string) $config->getChatChannelId(),
                new DiscordMessage(sprintf(
                    '**%s:** %s',
                    MessageTemplate::escape($line->author ?? '?'),
                    MessageTemplate::escape($line->text),
                )),
            );

            ++$sent;
        }

        return $sent;
    }

    /**
     * Whether one line may be mirrored, given the chosen scope.
     *
     * The scope only ever *narrows*: `isPublic()` is the floor, and no
     * setting can widen it to a private channel. That is why the check
     * asks the line first and the setting second.
     */
    private function mayLeave(ChatLine $line, DiscordConfig $config): bool
    {
        if ($line->kind !== ChatLine::KIND_MESSAGE || !$line->isPublic()) {
            return false;
        }

        // A message the relay itself put into the game, coming back out.
        if ($line->channel === ChatLine::CHANNEL_DISCORD) {
            return false;
        }

        return match ($config->getChatScope()) {
            // The narrower scopes are the same set today, because the
            // log does not distinguish saying from shouting. Kept apart
            // so the distinction can be made without a migration once
            // it can be read.
            DiscordConfig::SCOPE_GENERAL_ONLY,
            DiscordConfig::SCOPE_NO_SHOUTING,
            DiscordConfig::SCOPE_ALL_PUBLIC => true,
            default => false,
        };
    }

}
