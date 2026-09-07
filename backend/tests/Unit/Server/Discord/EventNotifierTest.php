<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Discord;

use App\Entity\DiscordConfig;
use App\Entity\DiscordNotification;
use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Server\Discord\DiscordClientInterface;
use App\Server\Discord\DiscordMessage;
use App\Server\Discord\EventNotifier;
use App\Server\Discord\MessageTemplate;
use App\Server\Discord\NotificationSettings;
use App\Server\Events\PanelEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Announcing an event, and the many ways of not announcing one.
 *
 * Silence is the default and every step of the way out of it is the
 * operator's decision, so the tests that matter here are the ones where
 * nothing is sent.
 */
final class EventNotifierTest extends TestCase
{
    public function testSendsTheConfiguredMessageToTheConfiguredChannel(): void
    {
        $server = $this->server();
        $setting = $this->setting($server, enabled: true, channel: '123456789012345678');
        $setting->setTemplate('{player} was kicked from {server}');

        $sent = $this->announce($server, $setting, $this->kickOf('bob'));

        self::assertCount(1, $sent);
        self::assertSame('123456789012345678', $sent[0]['channel']);
        self::assertSame('bob was kicked from Test', $sent[0]['content']);
    }

    /** No row at all: never configured, so never announced. */
    public function testSendsNothingWhenTheEventWasNeverConfigured(): void
    {
        self::assertSame([], $this->announce($this->server(), null, $this->kickOf('bob')));
    }

    /** The switch is off. */
    public function testSendsNothingWhenTheEventIsSwitchedOff(): void
    {
        $server = $this->server();
        $setting = $this->setting($server, enabled: false, channel: '123456789012345678');

        self::assertSame([], $this->announce($server, $setting, $this->kickOf('bob')));
    }

    /**
     * Switched on with nowhere to send it. Treating that as "on" would
     * show a switch that does nothing.
     */
    public function testSendsNothingWhenNoChannelWasChosen(): void
    {
        $server = $this->server();
        $setting = $this->setting($server, enabled: true, channel: null);

        self::assertSame([], $this->announce($server, $setting, $this->kickOf('bob')));
    }

    /**
     * The Discord link was removed after the notification was set up.
     * A leftover row must not keep sending into a guild the operator
     * has disconnected.
     */
    public function testSendsNothingWhenTheServerIsNoLongerLinked(): void
    {
        // Deliberately no DiscordConfig: this is a server that was
        // linked once and is not any more.
        $unlinked = new GameServer('Test');
        $this->serverId = $unlinked->getId()->toRfc4122();

        $setting = $this->setting($unlinked, enabled: true, channel: '123456789012345678');

        self::assertSame([], $this->announce($unlinked, $setting, $this->kickOf('bob')));
    }

    /** With no wording of its own, the panel's default is used. */
    public function testFallsBackToTheDefaultWording(): void
    {
        $server = $this->server();
        $setting = $this->setting($server, enabled: true, channel: '123456789012345678');

        $sent = $this->announce($server, $setting, $this->kickOf('bob'));

        self::assertCount(1, $sent);
        self::assertStringContainsString('bob', $sent[0]['content']);
    }

    /** A template rendering to nothing is not a message worth sending. */
    public function testSendsNothingWhenTheWordingRendersEmpty(): void
    {
        $server = $this->server();
        $setting = $this->setting($server, enabled: true, channel: '123456789012345678');
        $setting->setTemplate('{reason}');

        $event = new PanelEvent(
            'moderation.kick',
            $server->getId()->toRfc4122(),
            'Test',
            'bob',
            null,
            ['reason' => null],
            new \DateTimeImmutable(),
        );

        self::assertSame([], $this->announce($server, $setting, $event));
    }

    /** A player's name cannot smuggle formatting into the message. */
    public function testAPlayersNameIsEscaped(): void
    {
        $server = $this->server();
        $setting = $this->setting($server, enabled: true, channel: '123456789012345678');
        $setting->setTemplate('**{player}** left');

        $sent = $this->announce($server, $setting, $this->kickOf('**everyone**'));

        self::assertSame('**\*\*everyone\*\*** left', $sent[0]['content']);
    }

    private function kickOf(string $player): PanelEvent
    {
        return new PanelEvent(
            'moderation.kick',
            $this->serverId,
            'Test',
            $player,
            'admin',
            ['player' => $player, 'server' => 'Test', 'admin' => 'admin', 'reason' => ''],
            new \DateTimeImmutable(),
        );
    }

    private string $serverId = '';

    private function server(): GameServer
    {
        $server = new GameServer('Test');
        new DiscordConfig($server, '111111111111111111');

        $this->serverId = $server->getId()->toRfc4122();

        return $server;
    }

    private function setting(GameServer $server, bool $enabled, ?string $channel): DiscordNotification
    {
        $setting = new DiscordNotification($server, 'moderation.kick');
        $setting->setEnabled($enabled);
        $setting->setChannelId($channel);

        return $setting;
    }

    /**
     * @return list<array{channel: string, content: string}>
     */
    private function announce(
        GameServer $server,
        ?DiscordNotification $setting,
        PanelEvent $event,
    ): array {
        $sent = [];

        $notifications = new class($setting) implements NotificationSettings {
            public function __construct(private readonly ?DiscordNotification $setting)
            {
            }

            public function forEvent(string $serverId, string $eventType): ?DiscordNotification
            {
                return $this->setting;
            }
        };

        $servers = $this->createStub(GameServerRepository::class);
        $servers->method('find')->willReturn($server);

        $discord = new class($sent) implements DiscordClientInterface {
            /** @param list<array{channel: string, content: string}> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function sendMessage(string $channelId, DiscordMessage $message): void
            {
                $this->sent[] = ['channel' => $channelId, 'content' => $message->content];
            }

            public function channels(string $guildId): array
            {
                return [];
            }

            public function roles(string $guildId): array
            {
                return [];
            }

            public function registerCommands(string $applicationId, string $guildId, array $commands): void
            {
            }

            public function self(): array
            {
                return ['id' => '1', 'username' => 'test'];
            }
        };

        (new EventNotifier($notifications, $servers, $discord, new MessageTemplate(), new NullLogger()))
            ->announce($event);

        return $sent;
    }
}
