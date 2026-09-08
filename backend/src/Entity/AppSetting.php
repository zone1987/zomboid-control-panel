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

    /**
     * Where to call when a newer release appears, and the token for it.
     *
     * The panel asks GitHub hourly whether a newer version exists, and
     * it runs on the operator's own machine -- so the call to redeploy
     * comes from an address they already trust. A deployment platform's
     * own "watch the repository" switch cannot do this: the panel ships
     * as an image, and a release changes no file it can see.
     */
    public const DEPLOY_WEBHOOK_URL = 'deploy.webhook_url';

    /** Sent as a bearer token; a deploy hook is as good as a login. */
    public const DEPLOY_WEBHOOK_TOKEN = 'deploy.webhook_token';

    /** Off unless the operator says otherwise: this restarts their panel. */
    public const DEPLOY_ON_RELEASE = 'deploy.on_release';


    /**
     * The Discord bot's token, panel-wide rather than per server.
     *
     * One application serves every server the panel knows: a token per
     * server would mean a bot per server, and Discord counts those
     * against the guild's app limit for no gain.
     */
    public const DISCORD_BOT_TOKEN = 'discord.bot_token';

    /** The application id, needed to register slash commands. */
    public const DISCORD_APPLICATION_ID = 'discord.application_id';

    /**
     * The application's public key, for verifying interactions.
     *
     * Not a secret — it verifies rather than signs, and Discord shows it
     * on the application page — so it is deliberately not in SECRET_KEYS
     * and stays readable in the interface.
     */
    public const DISCORD_PUBLIC_KEY = 'discord.public_key';


    /** Values that must never be returned to the frontend in plaintext. */
    public const SECRET_KEYS = [
        self::STEAM_API_KEY,
        self::GOOGLE_CLIENT_SECRET,
        self::MAILER_DSN,
        self::MAIL_PASSWORD,
        self::DISCORD_BOT_TOKEN,
        self::DEPLOY_WEBHOOK_TOKEN,
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
