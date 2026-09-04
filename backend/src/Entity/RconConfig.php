<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'rcon_config')]
class RconConfig
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: GameServer::class, inversedBy: 'rconConfig')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameServer $server;

    #[ORM\Column(length: 191)]
    #[Assert\NotBlank]
    private string $host;

    #[ORM\Column(type: 'integer')]
    #[Assert\Range(min: 1, max: 65535)]
    private int $port = 27015;

    #[ORM\Column(type: 'encrypted_string')]
    private string $password;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastVerifiedAt = null;

    public function __construct(GameServer $server, string $host, string $password)
    {
        $this->id = Uuid::v7();
        $this->server = $server;
        $this->host = $host;
        $this->password = $password;
        $server->setRconConfig($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getServer(): GameServer
    {
        return $this->server;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getLastVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->lastVerifiedAt;
    }

    public function recordSuccessfulVerification(): void
    {
        $this->lastVerifiedAt = new \DateTimeImmutable();
    }
}
