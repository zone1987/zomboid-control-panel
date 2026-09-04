<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthIdentityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OAuthIdentityRepository::class)]
#[ORM\Table(name: 'oauth_identity')]
#[ORM\UniqueConstraint(name: 'uniq_provider_user', columns: ['provider', 'provider_user_id'])]
class OAuthIdentity
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_STEAM = 'steam';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'oauthIdentities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $provider;

    /** Google's "sub" claim, or the SteamID64 for Steam. */
    #[ORM\Column(length: 191)]
    private string $providerUserId;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $providerLabel = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $linkedAt;

    public function __construct(User $user, string $provider, string $providerUserId, ?string $providerLabel = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
        $this->providerLabel = $providerLabel;
        $this->linkedAt = new \DateTimeImmutable();
        $user->addOauthIdentity($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }

    public function getProviderLabel(): ?string
    {
        return $this->providerLabel;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }
}
