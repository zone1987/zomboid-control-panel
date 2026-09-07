<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\ModerationAction;

/**
 * Which events can be announced, and what they say by default.
 *
 * Two families, and the difference is the point. **Server events** are
 * about the server and are the ones most operators want in a public
 * channel. **Admin actions** are about what a person did, and putting
 * "X gave themselves 500 rounds" in front of the community is a
 * different act — so all eighteen are off until switched on, and each
 * has its own channel.
 *
 * The reference panel announced no admin action at all. This announces
 * any of them, individually, deliberately.
 */
final class NotifiableEvents
{
    /** Events that are not about a person. */
    public const SERVER_EVENTS = [
        'bridge.quiet',
        'bridge.back',
        'server.unreachable',
        'server.reachable',
        'panel.updated',
        'bridge.updated',
    ];

    /**
     * Default wording per event type.
     *
     * German because the panel's operators are, and every one of them
     * is editable — a default nobody can change is a default that is
     * wrong for somebody.
     *
     * @var array<string, string>
     */
    public const DEFAULT_TEMPLATES = [
        // Server
        'bridge.quiet' => '⚠️ **{server}**: Die Bridge meldet sich nicht mehr.',
        'bridge.back' => '✅ **{server}**: Die Bridge antwortet wieder.',
        'server.unreachable' => '⚠️ **{server}** ist nicht erreichbar.',
        'server.reachable' => '✅ **{server}** ist wieder erreichbar.',
        'panel.updated' => 'ℹ️ Das Dashboard wurde aktualisiert.',
        'bridge.updated' => 'ℹ️ **{server}**: Die Bridge wurde auf {input.version} aktualisiert.',

        // Who comes and goes: the two most-wanted, and the only
        // moderation types that are not about somebody intervening.
        'moderation.'.ModerationAction::JOIN => '➡️ **{player}** ist **{server}** beigetreten.',
        'moderation.'.ModerationAction::LEAVE => '⬅️ **{player}** hat **{server}** verlassen.',

        // Admin actions
        'moderation.'.ModerationAction::KICK => '👢 **{admin}** hat **{player}** vom Server geworfen. {reason}',
        'moderation.'.ModerationAction::BAN => '🔨 **{admin}** hat **{player}** gesperrt. {reason}',
        'moderation.'.ModerationAction::UNBAN => '🕊️ **{admin}** hat die Sperre von **{player}** aufgehoben.',
        'moderation.'.ModerationAction::ACCESS_LEVEL => '🛡️ **{admin}** hat die Zugriffsstufe von **{player}** geändert.',
        'moderation.'.ModerationAction::CONSOLE => '⌨️ **{admin}** hat einen Konsolenbefehl ausgeführt.',
        'moderation.'.ModerationAction::BROADCAST => '📢 **{admin}** hat eine Nachricht an alle gesendet.',
        'moderation.'.ModerationAction::ITEMS => '🎁 **{admin}** hat **{player}** Items gegeben.',
        'moderation.'.ModerationAction::TELEPORT => '🧭 **{admin}** hat **{player}** teleportiert.',
        'moderation.'.ModerationAction::EVENT => '🌩️ **{admin}** hat ein Ereignis ausgelöst: {player}.',
        'moderation.'.ModerationAction::ABILITY => '✨ **{admin}** hat eine Fähigkeit von **{player}** geändert.',
        'moderation.'.ModerationAction::EXPERIENCE => '📈 **{admin}** hat **{player}** Erfahrung gegeben.',
        'moderation.'.ModerationAction::HEAL => '🩹 **{admin}** hat **{player}** geheilt.',
        'moderation.'.ModerationAction::STATISTIC => '📊 **{admin}** hat einen Wert von **{player}** gesetzt.',
        'moderation.'.ModerationAction::TRAIT => '🧬 **{admin}** hat eine Eigenschaft von **{player}** geändert.',
        'moderation.'.ModerationAction::SKILL => '🎓 **{admin}** hat eine Fertigkeit von **{player}** geändert.',
    ];

    /**
     * Every announceable type, server events first.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::DEFAULT_TEMPLATES);
    }

    /** Whether this type is about a person rather than about the server. */
    public static function isAdminAction(string $type): bool
    {
        return str_starts_with($type, 'moderation.')
            && !in_array($type, [
                'moderation.'.ModerationAction::JOIN,
                'moderation.'.ModerationAction::LEAVE,
            ], true);
    }

    public static function defaultTemplate(string $type): ?string
    {
        return self::DEFAULT_TEMPLATES[$type] ?? null;
    }

    /**
     * The tokens an event of this type carries.
     *
     * Used to tell the operator, at save time, that `{plyer}` is a typo
     * — rather than letting them find out from a channel weeks later.
     *
     * @return list<string>
     */
    public static function tokensFor(string $type): array
    {
        $common = ['server'];

        if (!str_starts_with($type, 'moderation.')) {
            return [...$common, 'input.version'];
        }

        return [...$common, 'player', 'admin', 'reason', 'detail', 'action'];
    }
}
