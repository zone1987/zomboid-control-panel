<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\TrustPath;

/**
 * One passkey on one device. A user may register any number of these;
 * the WebAuthn user handle stays stable across all of them.
 */
#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'publicKeyCredentialId', column: new ORM\Column(type: 'base64', unique: true)),
    new ORM\AttributeOverride(name: 'type', column: new ORM\Column(length: 100)),
    new ORM\AttributeOverride(name: 'transports', column: new ORM\Column(type: 'json')),
    new ORM\AttributeOverride(name: 'attestationType', column: new ORM\Column(length: 100)),
    new ORM\AttributeOverride(name: 'trustPath', column: new ORM\Column(type: 'trust_path')),
    new ORM\AttributeOverride(name: 'aaguid', column: new ORM\Column(type: 'aaguid')),
    new ORM\AttributeOverride(name: 'credentialPublicKey', column: new ORM\Column(type: 'base64')),
    new ORM\AttributeOverride(name: 'userHandle', column: new ORM\Column(length: 191)),
    new ORM\AttributeOverride(name: 'counter', column: new ORM\Column(type: 'integer')),
    new ORM\AttributeOverride(name: 'otherUI', column: new ORM\Column(type: 'json', nullable: true)),
    new ORM\AttributeOverride(name: 'backupEligible', column: new ORM\Column(type: 'boolean', nullable: true)),
    new ORM\AttributeOverride(name: 'backupStatus', column: new ORM\Column(type: 'boolean', nullable: true)),
    new ORM\AttributeOverride(name: 'uvInitialized', column: new ORM\Column(type: 'boolean', nullable: true)),
])]
class WebauthnCredential extends CredentialRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'webauthnCredentials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Device label chosen by the user, e.g. "MacBook Touch ID". */
    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * @param string[]                 $transports
     * @param array<string, mixed>|null $otherUI
     */
    public function __construct(
        User $user,
        string $name,
        string $publicKeyCredentialId,
        string $type,
        array $transports,
        string $attestationType,
        TrustPath $trustPath,
        Uuid $aaguid,
        string $credentialPublicKey,
        string $userHandle,
        int $counter,
        ?array $otherUI = null,
        ?bool $backupEligible = null,
        ?bool $backupStatus = null,
        ?bool $uvInitialized = null,
    ) {
        parent::__construct(
            $publicKeyCredentialId,
            $type,
            $transports,
            $attestationType,
            $trustPath,
            $aaguid,
            $credentialPublicKey,
            $userHandle,
            $counter,
            $otherUI,
            $backupEligible,
            $backupStatus,
            $uvInitialized,
        );

        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function fromRecord(CredentialRecord $record, User $user, string $name): self
    {
        return new self(
            $user,
            $name,
            $record->publicKeyCredentialId,
            $record->type,
            $record->transports,
            $record->attestationType,
            $record->trustPath,
            $record->aaguid,
            $record->credentialPublicKey,
            $record->userHandle,
            $record->counter,
            $record->otherUI,
            $record->backupEligible,
            $record->backupStatus,
            $record->uvInitialized,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function recordUse(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
