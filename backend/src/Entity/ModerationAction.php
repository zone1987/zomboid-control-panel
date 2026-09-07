<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ModerationActionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A record of what was done to whom, and why.
 *
 * Zomboid offers no way to list bans over RCON — only to set and lift
 * them — so an operator would otherwise have no way to find someone they
 * banned last week. This is also the audit trail for a shared panel.
 */
#[ORM\Entity(repositoryClass: ModerationActionRepository::class)]
#[ORM\Table(name: 'moderation_action')]
#[ORM\Index(name: 'idx_server_username', columns: ['server_id', 'username'])]
class ModerationAction
{
    public const KICK = 'kick';
    public const BAN = 'ban';
    public const UNBAN = 'unban';
    public const ACCESS_LEVEL = 'access_level';

    /** A command typed into the console rather than driven by a button. */
    public const CONSOLE = 'console';

    /** A message announced to everyone on the server. */
    public const BROADCAST = 'broadcast';

    /** Items placed into a player's inventory. */
    public const ITEMS = 'items';

    /** A player moved somewhere else. */
    public const TELEPORT = 'teleport';

    /** Something done to the world from the event console. */
    public const EVENT = 'event';

    /** A player came online. Nobody in the panel did this, so performedBy is null. */
    public const JOIN = 'join';

    /** A player went offline. Nobody in the panel did this, so performedBy is null. */
    public const LEAVE = 'leave';

    /** An ability set from the dossier: god mode, invisibility, noclip. */
    public const ABILITY = 'ability';

    /** Experience granted in one skill. */
    public const EXPERIENCE = 'experience';

    /** A player healed through the bridge. */
    public const HEAL = 'heal';

    /** One of the character statistics set through the bridge. */
    public const STATISTIC = 'statistic';

    /** A character trait added or removed through the bridge. */
    public const TRAIT = 'trait';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GameServer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameServer $server;

    #[ORM\Column(length: 20)]
    private string $action;

    #[ORM\Column(length: 100)]
    private string $username;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    /** What the game server answered; free text, kept verbatim. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reply = null;

    /**
     * What was asked for, as the interface put it.
     *
     * The history could say "rain" but never "rain at 70": the inputs
     * were dropped on the way in, so the log recorded which action ran
     * and not what it ran with. Null on every row written before this
     * column existed, and on actions that take nothing.
     *
     * @var array<string, scalar>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $inputs = null;

    /**
     * Whether the server refused it.
     *
     * An attempt is worth recording either way — "who tried" is a
     * question a log has to answer — but a list that cannot tell a
     * refusal from a success reads as though everything worked. Null
     * means unknown, which is what every row written before this column
     * existed truthfully is.
     */
    #[ORM\Column(nullable: true)]
    private ?bool $failed = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $performedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $performedAt;

    /**
     * When a temporary ban should be lifted. Zomboid has no notion of a
     * timed ban — banuser takes no duration — so the panel bans
     * permanently and lifts it again itself.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $liftedAt = null;

    public function __construct(
        GameServer $server,
        string $action,
        string $username,
        ?User $performedBy,
        ?string $reason = null,
        ?string $reply = null,
        ?\DateTimeImmutable $expiresAt = null,
        /** @var array<string, scalar>|null */
        ?array $inputs = null,
        ?bool $failed = null,
    ) {
        $this->expiresAt = $expiresAt;
        $this->id = Uuid::v7();
        $this->server = $server;
        $this->action = $action;
        $this->username = $username;
        $this->performedBy = $performedBy;
        $this->inputs = $inputs === [] ? null : $inputs;
        $this->failed = $failed;
        $this->reason = $reason;
        $this->reply = $reply;
        $this->performedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getServer(): GameServer
    {
        return $this->server;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getReply(): ?string
    {
        return $this->reply;
    }

    /**
     * What was asked for, or null where nothing was or it predates the
     * column.
     *
     * @return array<string, scalar>|null
     */
    public function getInputs(): ?array
    {
        return $this->inputs;
    }

    /** True when refused, false when accepted, null when unknown. */
    public function hasFailed(): ?bool
    {
        return $this->failed;
    }

    public function getPerformedBy(): ?User
    {
        return $this->performedBy;
    }

    public function getPerformedAt(): \DateTimeImmutable
    {
        return $this->performedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isTemporary(): bool
    {
        return $this->expiresAt !== null;
    }

    public function hasExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt !== null && $this->liftedAt === null && $this->expiresAt <= $now;
    }

    public function markLifted(): void
    {
        $this->liftedAt = new \DateTimeImmutable();
    }

    public function getLiftedAt(): ?\DateTimeImmutable
    {
        return $this->liftedAt;
    }
}
