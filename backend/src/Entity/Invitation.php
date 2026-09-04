<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    /** Hash of the token; the plaintext only ever reaches the invitee by mail. */
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /** @param list<string> $roles */
    public function __construct(
        string $email,
        string $tokenHash,
        array $roles,
        ?User $invitedBy,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->tokenHash = $tokenHash;
        $this->roles = \array_values($roles);
        $this->invitedBy = $invitedBy;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function isPending(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->acceptedAt === null && $this->expiresAt > $now;
    }

    public function accept(): void
    {
        $this->acceptedAt = new \DateTimeImmutable();
    }
}
