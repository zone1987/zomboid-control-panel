<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How one game server is wired to one Discord guild.
 *
 * Per server rather than panel-wide, because two servers may belong to
 * two communities — while the bot **token** stays panel-wide, since one
 * application can serve every guild it is invited to.
 *
 * The chat relay is off unless a channel is chosen *and* a scope picked:
 * mirroring faction, safehouse or whisper chat into Discord is a
 * data-protection fault, not a cosmetic one, so it cannot happen by
 * leaving a field at its default.
 */
#[ORM\Entity]
#[ORM\Table(name: 'discord_config')]
class DiscordConfig
{
    /**
     * Which game chat may be mirrored, from least to most.
     *
     * An allow-list rather than a block-list: a chat channel the game
     * adds later is excluded until somebody decides it should not be,
     * which is the safe direction.
     */
    public const SCOPE_GENERAL_ONLY = 'general';
    public const SCOPE_NO_SHOUTING = 'noShouting';
    public const SCOPE_ALL_PUBLIC = 'allPublic';

    public const SCOPES = [self::SCOPE_GENERAL_ONLY, self::SCOPE_NO_SHOUTING, self::SCOPE_ALL_PUBLIC];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: GameServer::class, inversedBy: 'discordConfig')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameServer $server;

    /** The guild whose channels may be chosen and whose commands are registered. */
    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^\d{15,25}$/', message: 'validation.invalid')]
    private string $guildId;

    /** Where game chat is mirrored to; null means the relay is off. */
    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Regex('/^\d{15,25}$/', message: 'validation.invalid')]
    private ?string $chatChannelId = null;

    /** Which game chat may leave the game. Narrowest by default. */
    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: self::SCOPES)]
    private string $chatScope = self::SCOPE_GENERAL_ONLY;

    /**
     * Whether Discord messages are sent into the game.
     *
     * Separate from `chatChannelId`, because mirroring outward and
     * accepting inward are two decisions: a community may want to read
     * the game's chat without letting Discord talk back.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $relayIntoGame = false;

    /** Whether slash commands are answered for this server at all. */
    #[ORM\Column(type: 'boolean')]
    private bool $commandsEnabled = true;

    public function __construct(GameServer $server, string $guildId)
    {
        $this->id = Uuid::v7();
        $this->server = $server;
        $this->guildId = $guildId;
        $server->setDiscordConfig($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getServer(): GameServer
    {
        return $this->server;
    }

    public function getGuildId(): string
    {
        return $this->guildId;
    }

    public function setGuildId(string $guildId): void
    {
        $this->guildId = $guildId;
    }

    public function getChatChannelId(): ?string
    {
        return $this->chatChannelId;
    }

    public function setChatChannelId(?string $chatChannelId): void
    {
        $this->chatChannelId = $chatChannelId === '' ? null : $chatChannelId;
    }

    public function getChatScope(): string
    {
        return $this->chatScope;
    }

    public function setChatScope(string $chatScope): void
    {
        $this->chatScope = $chatScope;
    }

    /** Outward mirroring needs a channel; the scope alone does nothing. */
    public function mirrorsGameChat(): bool
    {
        return $this->chatChannelId !== null;
    }

    public function relaysIntoGame(): bool
    {
        return $this->relayIntoGame && $this->chatChannelId !== null;
    }

    public function setRelayIntoGame(bool $relayIntoGame): void
    {
        $this->relayIntoGame = $relayIntoGame;
    }

    public function areCommandsEnabled(): bool
    {
        return $this->commandsEnabled;
    }

    public function setCommandsEnabled(bool $commandsEnabled): void
    {
        $this->commandsEnabled = $commandsEnabled;
    }
}
