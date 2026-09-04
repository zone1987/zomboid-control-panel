<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'user.email.already_used')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_SERVER_ADMIN = 'ROLE_SERVER_ADMIN';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $displayName;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /** Null while the account is passwordless (invited but not yet activated). */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 5, options: ['default' => 'de'])]
    private string $locale = 'de';

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: 'encrypted_string', nullable: true)]
    private ?string $totpSecret = null;

    /** @var list<string> Hashed backup codes; never stored in plaintext. */
    #[ORM\Column(type: 'json')]
    private array $backupCodes = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** @var Collection<int, WebauthnCredential> */
    #[ORM\OneToMany(targetEntity: WebauthnCredential::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $webauthnCredentials;

    /** @var Collection<int, OAuthIdentity> */
    #[ORM\OneToMany(targetEntity: OAuthIdentity::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $oauthIdentities;

    public function __construct(string $email, string $displayName)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
        $this->webauthnCredentials = new ArrayCollection();
        $this->oauthIdentities = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return \array_values(\array_unique([...$this->roles, self::ROLE_USER]));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = \array_values(\array_unique($roles));
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, WebauthnCredential> */
    public function getWebauthnCredentials(): Collection
    {
        return $this->webauthnCredentials;
    }

    /** @return Collection<int, OAuthIdentity> */
    public function getOauthIdentities(): Collection
    {
        return $this->oauthIdentities;
    }

    public function addOauthIdentity(OAuthIdentity $identity): void
    {
        if (!$this->oauthIdentities->contains($identity)) {
            $this->oauthIdentities->add($identity);
        }
    }

    public function eraseCredentials(): void
    {
    }

    // --- Two-factor authentication -------------------------------------

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpSecret !== null;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if ($this->totpSecret === null) {
            return null;
        }

        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): void
    {
        $this->totpSecret = $totpSecret;

        if ($totpSecret === null) {
            $this->backupCodes = [];
        }
    }

    /** @param list<string> $hashedCodes */
    public function setBackupCodes(array $hashedCodes): void
    {
        $this->backupCodes = \array_values($hashedCodes);
    }

    public function getBackupCodeCount(): int
    {
        return \count($this->backupCodes);
    }

    public function isBackupCode(string $code): bool
    {
        foreach ($this->backupCodes as $hash) {
            if (\password_verify($code, $hash)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $this->backupCodes = \array_values(\array_filter(
            $this->backupCodes,
            static fn (string $hash): bool => !\password_verify($code, $hash),
        ));
    }
}
