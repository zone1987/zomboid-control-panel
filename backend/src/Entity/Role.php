<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoleRepository;
use App\Security\Permission\Permission;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named set of permissions.
 *
 * Three roles are built in and cannot be deleted, so an installation
 * cannot lock itself out of its own administration.
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'app_role')]
#[UniqueEntity(fields: ['name'], message: 'role.name.already_used')]
class Role
{
    public const BUILT_IN_ADMIN = 'administrator';
    public const BUILT_IN_SERVER_ADMIN = 'server-administrator';
    public const BUILT_IN_MEMBER = 'member';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 60, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    #[Assert\Regex('/^[a-z0-9][a-z0-9-]*$/', message: 'role.name.invalid')]
    private string $name;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $label;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $permissions = [];

    /** A built-in role may be edited but never removed. */
    #[ORM\Column(options: ['default' => false])]
    private bool $builtIn = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param list<Permission> $permissions */
    public function __construct(string $name, string $label, array $permissions = [], bool $builtIn = false)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->label = $label;
        $this->builtIn = $builtIn;
        $this->createdAt = new \DateTimeImmutable();
        $this->setPermissions($permissions);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * A built-in role keeps its identifier: code refers to those by name,
     * and renaming one would break the reference.
     */
    public function setName(string $name): void
    {
        if (!$this->builtIn) {
            $this->name = $name;
        }
    }

    /** @return list<Permission> */
    public function getPermissions(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?Permission => Permission::tryFrom($value),
            $this->permissions,
        )));
    }

    /** @return list<string> */
    public function getPermissionNames(): array
    {
        return $this->permissions;
    }

    /** @param list<Permission> $permissions */
    public function setPermissions(array $permissions): void
    {
        $names = array_map(static fn (Permission $p): string => $p->value, $permissions);

        sort($names);

        $this->permissions = array_values(array_unique($names));
    }

    public function grants(Permission $permission): bool
    {
        return \in_array($permission->value, $this->permissions, true);
    }

    public function isBuiltIn(): bool
    {
        return $this->builtIn;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
