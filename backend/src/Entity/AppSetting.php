<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Application-wide credentials edited through the interface. A stored
 * value takes precedence over the matching environment variable, so a
 * container can ship with defaults that an administrator can override
 * without a redeploy.
 */
#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_setting')]
class AppSetting
{
    public const STEAM_API_KEY = 'steam.api_key';
    public const GOOGLE_CLIENT_ID = 'google.client_id';
    public const GOOGLE_CLIENT_SECRET = 'google.client_secret';
    public const MAILER_DSN = 'mailer.dsn';
    public const MAIL_HOST = 'mailer.host';
    public const MAIL_PORT = 'mailer.port';
    public const MAIL_USERNAME = 'mailer.username';
    public const MAIL_PASSWORD = 'mailer.password';
    public const MAIL_ENCRYPTION = 'mailer.encryption';
    public const MAIL_FROM_ADDRESS = 'mailer.from_address';
    public const MAIL_FROM_NAME = 'mailer.from_name';

    /** Days after which an unseen player snapshot may go; 0 or unset keeps everything. */
    public const PLAYER_RETENTION_DAYS = 'players.retention_days';


    /** Values that must never be returned to the frontend in plaintext. */
    public const SECRET_KEYS = [
        self::STEAM_API_KEY,
        self::GOOGLE_CLIENT_SECRET,
        self::MAILER_DSN,
        self::MAIL_PASSWORD,
    ];

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: 'encrypted_string', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, ?string $value = null)
    {
        $this->name = $name;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): void
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isSecret(): bool
    {
        return \in_array($this->name, self::SECRET_KEYS, true);
    }
}
