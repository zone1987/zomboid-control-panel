<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerNoteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What the staff know about a player that the game does not.
 *
 * The one genuinely new piece of storage the dossier needs. Everything
 * else it shows is read from the server or from the moderation log; this
 * is the operators' own memory — "warned twice about the safehouse", "new
 * and asking good questions" — and it is exactly the thing a handover
 * loses when it lives in somebody's head.
 *
 * One row per player per server, updated in place. A note is not a log:
 * the log already records what was *done*, and a second history of what
 * was *thought* would be a second thing to read and keep in step.
 */
#[ORM\Entity(repositoryClass: PlayerNoteRepository::class)]
#[ORM\Table(name: 'player_note')]
#[ORM\UniqueConstraint(name: 'uniq_note_server_username', columns: ['server_id', 'username'])]
class PlayerNote
{
    /** Long enough for a paragraph, short enough not to become a wiki. */
    public const MAX_LENGTH = 1000;

    /** Ten tags of 24 characters, which is the shape the reference uses. */
    public const MAX_TAGS = 10;
    public const MAX_TAG_LENGTH = 24;

    /**
     * The tags the interface suggests.
     *
     * Suggestions, not a closed set: an operator's own vocabulary is
     * their business, and a fixed list would be wrong on somebody's
     * server by the second week.
     */
    public const SUGGESTED = [
        'trusted',
        'suspicious',
        'new',
        'vip',
        'builder',
        'griefer',
        'afk',
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GameServer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameServer $server;

    #[ORM\Column(length: 100)]
    private string $username;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $tags = [];

    /**
     * Who wrote it last.
     *
     * Nullable and set to null when the account is deleted: a note
     * outlives whoever typed it, and losing the note because somebody
     * left would defeat its purpose.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(GameServer $server, string $username)
    {
        $this->id = Uuid::v7();
        $this->server = $server;
        $this->username = $username;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getServer(): GameServer
    {
        return $this->server;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Replaces the note and its tags together.
     *
     * One setter rather than two, because the interface saves both at
     * once and a half-saved pair would be a state nobody asked for.
     *
     * @param list<string> $tags
     */
    public function revise(?string $note, array $tags, ?User $by): void
    {
        $trimmed = $note === null ? null : trim($note);

        $this->note = $trimmed === '' ? null : $trimmed;
        $this->tags = self::clean($tags);
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Whether there is anything left worth keeping a row for. */
    public function isEmpty(): bool
    {
        return $this->note === null && $this->tags === [];
    }

    /**
     * Tags, tidied: trimmed, lower-cased, deduplicated and capped.
     *
     * Lower-cased so "VIP" and "vip" are one tag rather than two that
     * sort apart, and capped so a paste cannot turn a dossier into a
     * wall of chips.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    public static function clean(array $tags): array
    {
        $cleaned = [];

        foreach ($tags as $tag) {
            $tidy = mb_strtolower(trim($tag));

            // A tag is one word-ish label; anything with a comma was
            // meant to be several and is somebody's typo.
            $tidy = trim(preg_replace('/[,\r\n\t]+/', ' ', $tidy) ?? '');
            $tidy = mb_substr($tidy, 0, self::MAX_TAG_LENGTH);

            if ($tidy !== '' && !\in_array($tidy, $cleaned, true)) {
                $cleaned[] = $tidy;
            }
        }

        return \array_slice($cleaned, 0, self::MAX_TAGS);
    }
}
