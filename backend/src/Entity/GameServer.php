<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameServerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GameServerRepository::class)]
#[ORM\Table(name: 'game_server')]
class GameServer
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\OneToOne(targetEntity: FtpConfig::class, mappedBy: 'server', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?FtpConfig $ftpConfig = null;

    #[ORM\OneToOne(targetEntity: RconConfig::class, mappedBy: 'server', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?RconConfig $rconConfig = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, ?string $description = null)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->description = $description;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getFtpConfig(): ?FtpConfig
    {
        return $this->ftpConfig;
    }

    public function setFtpConfig(?FtpConfig $ftpConfig): void
    {
        $this->ftpConfig = $ftpConfig;
    }

    public function getRconConfig(): ?RconConfig
    {
        return $this->rconConfig;
    }

    public function setRconConfig(?RconConfig $rconConfig): void
    {
        $this->rconConfig = $rconConfig;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
