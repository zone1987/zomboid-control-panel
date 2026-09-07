<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Which Discord roles may run one subcommand.
 *
 * A row per server and subcommand, holding role ids. **An absent row
 * means nobody**, not everybody: a command whose rights were never set
 * must not be open, or adding a command to the catalogue would silently
 * hand it to every member of the guild.
 *
 * This is the guild's half of the check. The panel's half —
 * `CommandCapabilities`, the permission the same act costs in the
 * interface — is checked as well, always, so a role here cannot buy
 * something the panel would refuse.
 */
#[ORM\Entity]
#[ORM\Table(name: 'discord_command_right')]
#[ORM\UniqueConstraint(name: 'discord_command_right_unique', columns: ['server_id', 'command'])]
class DiscordCommandRight
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GameServer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameServer $server;

    /** `command.subcommand`, as `CommandCapabilities` keys them. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    private string $command;

    /**
     * Discord role ids allowed to run it.
     *
     * Several, because the reference's three fixed tiers cannot express
     * a guild with four roles — and a community that has separate
     * moderators and event hosts is normal.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roleIds = [];

    public function __construct(GameServer $server, string $command)
    {
        $this->id = Uuid::v7();
        $this->server = $server;
        $this->command = $command;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getServer(): GameServer
    {
        return $this->server;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    /** @return list<string> */
    public function getRoleIds(): array
    {
        return $this->roleIds;
    }

    /** @param list<string> $roleIds */
    public function setRoleIds(array $roleIds): void
    {
        $clean = [];

        foreach ($roleIds as $roleId) {
            if (preg_match('/^\d{15,25}$/', $roleId) === 1) {
                $clean[] = $roleId;
            }
        }

        $this->roleIds = array_values(array_unique($clean));
    }

    /**
     * Whether somebody holding these roles may run this command.
     *
     * @param list<string> $held the member's role ids, from the interaction
     */
    public function allows(array $held): bool
    {
        if ($this->roleIds === []) {
            return false;
        }

        return array_intersect($this->roleIds, $held) !== [];
    }
}
