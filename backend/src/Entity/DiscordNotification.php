<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Whether one kind of event is announced, where, and in what words.
 *
 * A row per server and event type, so the operator can send restart
 * warnings to a public channel and admin actions to a private one — or
 * not at all.
 *
 * **Everything is off until switched on.** "X gave themselves 500 rounds
 * of ammunition" in a public channel is a different act from announcing
 * a restart, and the difference must be a decision rather than a
 * default. The reference panel announced no admin action at all; this
 * announces any of them, individually, on purpose.
 */
#[ORM\Entity]
#[ORM\Table(name: 'discord_notification')]
#[ORM\UniqueConstraint(name: 'discord_notification_unique', columns: ['server_id', 'event_type'])]
class DiscordNotification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GameServer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameServer $server;

    /** The `PanelEvent::type`, e.g. `moderation.ban` or `bridge.quiet`. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $eventType;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = false;

    /** Null while nobody has chosen one, which also means nothing is sent. */
    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Regex('/^\d{15,25}$/', message: 'validation.invalid')]
    private ?string $channelId = null;

    /**
     * The message, with `{token}` placeholders.
     *
     * Null means the panel's own default wording for that event, so a
     * new event type says something sensible without the operator
     * writing 35 templates first.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 1500)]
    private ?string $template = null;

    public function __construct(GameServer $server, string $eventType)
    {
        $this->id = Uuid::v7();
        $this->server = $server;
        $this->eventType = $eventType;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getServer(): GameServer
    {
        return $this->server;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * Whether this event would actually be sent.
     *
     * Enabled without a channel sends nothing, and treating that as "on"
     * would show the operator a switch that does nothing.
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->channelId !== null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getChannelId(): ?string
    {
        return $this->channelId;
    }

    public function setChannelId(?string $channelId): void
    {
        $this->channelId = $channelId === '' ? null : $channelId;
    }

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    public function setTemplate(?string $template): void
    {
        $this->template = $template === '' ? null : $template;
    }
}
