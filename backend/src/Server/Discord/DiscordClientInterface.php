<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * What the panel needs from Discord's REST API.
 *
 * An interface rather than a concrete client so a test can record what
 * would have been sent — `RconClientInterface` exists for the same
 * reason, and the moderation endpoints would be untestable without it.
 */
interface DiscordClientInterface
{
    /**
     * Posts a message into one channel.
     *
     * @throws DiscordException when Discord refuses or cannot be reached
     */
    public function sendMessage(string $channelId, DiscordMessage $message): void;

    /**
     * The text channels of one guild, for the operator to choose from.
     *
     * Listing them is why the bot token is worth more than a webhook: a
     * webhook is bound to one channel and has to be pasted in by hand.
     *
     * @return list<array{id: string, name: string, type: int, parent: string|null}>
     *
     * @throws DiscordException
     */
    public function channels(string $guildId): array;

    /**
     * The guild's roles, for the operator to pick from.
     *
     * Names and colours, so a permission is granted by choosing
     * "Moderator" rather than by pasting a nineteen-digit id — the same
     * reason the channels are listed.
     *
     * @return list<array{id: string, name: string, colour: int, position: int, managed: bool}>
     *
     * @throws DiscordException
     */
    public function roles(string $guildId): array;

    /**
     * Replaces the guild's slash commands with the ones given.
     *
     * Guild-scoped rather than global: a guild registration is live
     * within seconds, while a global one takes up to an hour to
     * propagate — which makes every correction an hour long.
     *
     * @param list<array<string, mixed>> $commands
     *
     * @throws DiscordException
     */
    public function registerCommands(string $applicationId, string $guildId, array $commands): void;

    /**
     * Whether the token works, and who it belongs to.
     *
     * @return array{id: string, username: string}
     *
     * @throws DiscordException
     */
    public function self(): array;
}
