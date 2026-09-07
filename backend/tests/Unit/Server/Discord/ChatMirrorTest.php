<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Discord;

use App\Entity\DiscordConfig;
use App\Entity\GameServer;
use App\Server\Chat\ChatLine;
use PHPUnit\Framework\TestCase;

/**
 * What may leave the game, and what may not.
 *
 * The allow-list is the part that matters: mirroring faction, safehouse
 * or whisper chat into Discord is a data-protection fault, not a
 * cosmetic one, and it would be invisible to whoever caused it.
 *
 * The decision itself lives in `ChatLine::isPublic()` and the scope
 * check beside it, so it is tested where it is decided rather than
 * through an FTP round trip.
 */
final class ChatMirrorTest extends TestCase
{
    /** General chat is the only tab that may be mirrored. */
    public function testOnlyGeneralChatIsPublic(): void
    {
        self::assertTrue($this->line(ChatLine::CHANNEL_GENERAL)->isPublic());
    }

    public function testEveryPrivateChannelStaysPrivate(): void
    {
        foreach ([
            ChatLine::CHANNEL_FACTION,
            ChatLine::CHANNEL_SAFEHOUSE,
            ChatLine::CHANNEL_RADIO,
            ChatLine::CHANNEL_ADMIN,
            ChatLine::CHANNEL_UNKNOWN,
            ChatLine::CHANNEL_DISCORD,
        ] as $channel) {
            self::assertFalse($this->line($channel)->isPublic(), $channel.' must never be mirrored');
        }
    }

    /**
     * A line with no channel at all — a system line, or a shape the
     * parser did not recognise — is not public either.
     */
    public function testALineWithNoChannelIsNotPublic(): void
    {
        self::assertFalse($this->line(null)->isPublic());
    }

    /**
     * The scope narrows and never widens: no setting can turn a private
     * channel into a public one, which is why `isPublic()` is checked
     * first and the scope second.
     */
    public function testTheWidestScopeStillCannotReachAPrivateChannel(): void
    {
        $server = new GameServer('Test');
        $config = new DiscordConfig($server, '111111111111111111');
        $config->setChatScope(DiscordConfig::SCOPE_ALL_PUBLIC);

        self::assertFalse($this->line(ChatLine::CHANNEL_FACTION)->isPublic());
    }

    /** Mirroring is off until a channel is chosen, whatever else is set. */
    public function testMirroringIsOffWithoutAChannel(): void
    {
        $server = new GameServer('Test');
        $config = new DiscordConfig($server, '111111111111111111');
        $config->setChatScope(DiscordConfig::SCOPE_ALL_PUBLIC);

        self::assertFalse($config->mirrorsGameChat());

        $config->setChatChannelId('222222222222222222');

        self::assertTrue($config->mirrorsGameChat());
    }

    /**
     * The inward relay needs its own yes *and* a channel: reading the
     * game's chat and letting Discord talk back are two decisions.
     */
    public function testTheInwardRelayNeedsBothAChannelAndItsOwnSwitch(): void
    {
        $server = new GameServer('Test');
        $config = new DiscordConfig($server, '111111111111111111');

        $config->setRelayIntoGame(true);
        self::assertFalse($config->relaysIntoGame(), 'no channel, so nothing to relay from');

        $config->setChatChannelId('222222222222222222');
        self::assertTrue($config->relaysIntoGame());

        $config->setRelayIntoGame(false);
        self::assertFalse($config->relaysIntoGame(), 'mirroring out does not imply relaying in');
    }

    private function line(?string $channel): ChatLine
    {
        return new ChatLine(ChatLine::KIND_MESSAGE, null, 'bob', 'hello', 'raw', $channel);
    }
}
