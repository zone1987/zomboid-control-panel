<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to Discord's REST API with the bot token.
 *
 * A bot token rather than a webhook, for one decisive reason: a webhook
 * is bound to a single channel and has to be pasted in by hand, while a
 * token can post anywhere the bot can see **and list the channels for
 * the operator to pick from**. Per-event channels are the feature that
 * needs.
 *
 * This is the single outbound boundary, which is where every message is
 * checked against the panel's own secrets — `/server status` relays what
 * the game answered, and Zomboid prints `showoptions` in full to anybody
 * who asks.
 */
final readonly class RestDiscordClient implements DiscordClientInterface
{
    private const BASE = 'https://discord.com/api/v10';

    /** Discord's own guidance; a slow API must not hold a worker. */
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpClientInterface $http,
        private AppSettingRepository $settings,
        private SecretRedaction $redaction,
        private LoggerInterface $logger,
    ) {
    }

    public function sendMessage(string $channelId, DiscordMessage $message): void
    {
        $payload = $message->toPayload();

        // Scrubbed here rather than at the caller: there is one way out,
        // and a check somewhere else is a check somebody can forget.
        $payload['content'] = $this->redaction->scrub((string) $payload['content']);

        $this->request('POST', '/channels/'.$channelId.'/messages', $payload);
    }

    public function channels(string $guildId): array
    {
        $found = [];

        foreach ($this->request('GET', '/guilds/'.$guildId.'/channels') as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $type = (int) ($channel['type'] ?? -1);

            // 0 is a text channel and 5 an announcement channel; the
            // rest (voice, categories, forums, threads) cannot take a
            // plain message and offering them would be a dead end.
            if (!in_array($type, [0, 5], true)) {
                continue;
            }

            $found[] = [
                'id' => (string) ($channel['id'] ?? ''),
                'name' => (string) ($channel['name'] ?? ''),
                'type' => $type,
                'parent' => isset($channel['parent_id']) && is_string($channel['parent_id'])
                    ? $channel['parent_id']
                    : null,
            ];
        }

        return $found;
    }

    public function roles(string $guildId): array
    {
        $found = [];

        foreach ($this->request('GET', '/guilds/'.$guildId.'/roles') as $role) {
            if (!is_array($role)) {
                continue;
            }

            $name = (string) ($role['name'] ?? '');

            // @everyone is every member by definition, so offering it as
            // a permission would mean "anybody", which the operator can
            // express more honestly by saying so.
            if ($name === '@everyone') {
                continue;
            }

            $found[] = [
                'id' => (string) ($role['id'] ?? ''),
                'name' => $name,
                'colour' => (int) ($role['color'] ?? 0),
                'position' => (int) ($role['position'] ?? 0),
                // A role Discord manages for an integration cannot be
                // assigned by hand, so granting a command to it would
                // usually be a mistake. Shown, but marked.
                'managed' => ($role['managed'] ?? false) === true,
            ];
        }

        // Discord returns them unordered; its own lists run highest
        // first, which is the order an operator recognises.
        usort($found, static fn (array $a, array $b): int => $b['position'] <=> $a['position']);

        return $found;
    }

    public function registerCommands(string $applicationId, string $guildId, array $commands): void
    {
        // PUT replaces the whole set, so a command removed from the
        // catalogue disappears from Discord too. POSTing them one by one
        // would leave deleted commands behind for ever.
        $this->request(
            'PUT',
            '/applications/'.$applicationId.'/guilds/'.$guildId.'/commands',
            $commands,
        );
    }

    public function guildCommands(string $applicationId, string $guildId): array
    {
        $found = [];

        foreach ($this->request('GET', '/applications/'.$applicationId.'/guilds/'.$guildId.'/commands') as $command) {
            if (is_array($command)) {
                $found[] = $command;
            }
        }

        return $found;
    }

    public function self(): array
    {
        $me = $this->request('GET', '/users/@me');

        return [
            'id' => (string) ($me['id'] ?? ''),
            'username' => (string) ($me['username'] ?? ''),
        ];
    }

    /**
     * @param array<array-key, mixed>|null $body
     *
     * @return array<array-key, mixed>
     *
     * @throws DiscordException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $token = $this->settings->find(AppSetting::DISCORD_BOT_TOKEN)?->getValue();

        if (!is_string($token) || trim($token) === '') {
            throw new DiscordException('discord.noToken');
        }

        $options = [
            'headers' => [
                // Discord asks for a User-Agent naming the project, and
                // rate-limits harder without one.
                'Authorization' => 'Bot '.trim($token),
                'User-Agent' => 'ZomboidControl (https://github.com/zone1987/zomboid-control-panel)',
            ],
            'timeout' => self::TIMEOUT_SECONDS,
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($method, self::BASE.$path, $options);
            $status = $response->getStatusCode();

            if ($status === 429) {
                // Retrying inside the request would hold a worker; the
                // messenger retry is the right place for it.
                throw new DiscordException('discord.rateLimited', '', 429);
            }

            if ($status === 401 || $status === 403) {
                throw new DiscordException('discord.unauthorised', '', $status);
            }

            if ($status >= 400) {
                $this->logger->warning('discord refused a request', [
                    'path' => $path,
                    'status' => $status,
                    'body' => mb_substr($response->getContent(false), 0, 500),
                ]);

                throw new DiscordException('discord.refused', 'HTTP '.$status, $status);
            }

            // 204 for a successful command registration and for some
            // deletes: no content is not an error.
            if ($status === 204) {
                return [];
            }

            $decoded = $response->toArray(false);
        } catch (DiscordException $known) {
            throw $known;
        } catch (ExceptionInterface|\JsonException $exception) {
            throw new DiscordException('discord.unreachable', $exception->getMessage());
        }

        return $decoded;
    }
}
