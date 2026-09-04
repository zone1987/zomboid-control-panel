<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The most recent state the bridge reported for one player on one server.
 *
 * One row per player, updated in place: the panel shows who is online now,
 * not a history, and keeping every tick would grow without bound.
 */
#[ORM\Entity(repositoryClass: PlayerSnapshotRepository::class)]
#[ORM\Table(name: 'player_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_server_username', columns: ['server_id', 'username'])]
#[ORM\Index(name: 'idx_server_online', columns: ['server_id', 'online'])]
class PlayerSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GameServer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameServer $server;

    #[ORM\Column(length: 100)]
    private string $username;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $steamId = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $online = true;

    #[ORM\Column(type: 'float')]
    private float $x = 0.0;

    #[ORM\Column(type: 'float')]
    private float $y = 0.0;

    #[ORM\Column(type: 'float')]
    private float $z = 0.0;

    #[ORM\Column(type: 'float')]
    private float $health = 1.0;

    #[ORM\Column(options: ['default' => false])]
    private bool $infected = false;

    #[ORM\Column(type: 'float', options: ['default' => 0])]
    private float $infectionLevel = 0.0;

    #[ORM\Column(type: 'float', options: ['default' => 0])]
    private float $hoursSurvived = 0.0;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $accessLevel = null;

    /** @var array<string, int> */
    #[ORM\Column(type: 'json')]
    private array $skills = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $traits = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    public function __construct(GameServer $server, string $username)
    {
        $this->id = Uuid::v7();
        $this->server = $server;
        $this->username = $username;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->firstSeenAt;
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

    public function getSteamId(): ?string
    {
        return $this->steamId;
    }

    public function isOnline(): bool
    {
        return $this->online;
    }

    public function markOffline(): void
    {
        $this->online = false;
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getZ(): float
    {
        return $this->z;
    }

    public function getHealth(): float
    {
        return $this->health;
    }

    public function isInfected(): bool
    {
        return $this->infected;
    }

    public function getInfectionLevel(): float
    {
        return $this->infectionLevel;
    }

    public function getHoursSurvived(): float
    {
        return $this->hoursSurvived;
    }

    public function getAccessLevel(): ?string
    {
        return $this->accessLevel;
    }

    /** @return array<string, int> */
    public function getSkills(): array
    {
        return $this->skills;
    }

    /** @return list<string> */
    public function getTraits(): array
    {
        return $this->traits;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    /**
     * @param array<string, int> $skills
     * @param list<string>       $traits
     */
    public function update(
        ?string $steamId,
        float $x,
        float $y,
        float $z,
        float $health,
        bool $infected,
        float $infectionLevel,
        float $hoursSurvived,
        ?string $accessLevel,
        array $skills,
        array $traits,
        \DateTimeImmutable $seenAt,
        bool $online = true,
    ): void {
        $this->steamId = $steamId;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->health = $health;
        $this->infected = $infected;
        $this->infectionLevel = $infectionLevel;
        $this->hoursSurvived = $hoursSurvived;
        $this->accessLevel = $accessLevel;
        $this->skills = $skills;
        $this->traits = $traits;
        $this->lastSeenAt = $seenAt;
        $this->online = $online;
    }
}
