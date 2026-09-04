<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'ftp_config')]
class FtpConfig
{
    public const PROTOCOL_FTP = 'ftp';
    public const PROTOCOL_SFTP = 'sftp';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: GameServer::class, inversedBy: 'ftpConfig')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameServer $server;

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: [self::PROTOCOL_FTP, self::PROTOCOL_SFTP])]
    private string $protocol = self::PROTOCOL_SFTP;

    #[ORM\Column(length: 191)]
    #[Assert\NotBlank]
    private string $host;

    #[ORM\Column(type: 'integer')]
    #[Assert\Range(min: 1, max: 65535)]
    private int $port = 22;

    #[ORM\Column(length: 191)]
    #[Assert\NotBlank]
    private string $username;

    #[ORM\Column(type: 'encrypted_string', nullable: true)]
    private ?string $password = null;

    /** Private key in PEM form; an alternative to password auth for SFTP. */
    #[ORM\Column(type: 'encrypted_string', nullable: true)]
    private ?string $privateKey = null;

    #[ORM\Column(length: 500, options: ['default' => '/'])]
    private string $basePath = '/';

    /** Absolute path of the server's media/lua/server directory. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $luaServerPath = null;

    /** Absolute path of the server's Logs directory, used for log tailing. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $logPath = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastVerifiedAt = null;

    public function __construct(GameServer $server, string $host, string $username)
    {
        $this->id = Uuid::v7();
        $this->server = $server;
        $this->host = $host;
        $this->username = $username;
        $server->setFtpConfig($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getServer(): GameServer
    {
        return $this->server;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): void
    {
        $this->protocol = $protocol;
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

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    public function getPrivateKey(): ?string
    {
        return $this->privateKey;
    }

    public function setPrivateKey(?string $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function setBasePath(string $basePath): void
    {
        $this->basePath = $basePath;
    }

    public function getLuaServerPath(): ?string
    {
        return $this->luaServerPath;
    }

    public function setLuaServerPath(?string $luaServerPath): void
    {
        $this->luaServerPath = $luaServerPath;
    }

    public function getLogPath(): ?string
    {
        return $this->logPath;
    }

    public function setLogPath(?string $logPath): void
    {
        $this->logPath = $logPath;
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
