<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * One interaction from Discord, read into something usable.
 *
 * Discord's payload nests the subcommand as an option of the command
 * and its arguments as options of that, which is correct for its wire
 * format and unwieldy everywhere else. Flattened once, here, rather
 * than in every command handler.
 */
final readonly class Interaction
{
    /**
     * @param array<string, scalar> $options   the subcommand's arguments
     * @param list<string>          $roleIds   the member's roles in this guild
     * @param string                $permissions Discord's own computed
     *     permission bitfield for this member in this channel, as a
     *     decimal string — it exceeds 32 bits, so it is not an int
     * @param string|null           $focused   which option autocomplete is asking about
     */
    public function __construct(
        public int $type,
        public string $command,
        public string $subcommand,
        public array $options,
        public string $guildId,
        public string $userId,
        public string $userName,
        public array $roleIds,
        public string $permissions = '0',
        public ?string $focused = null,
    ) {
    }

    /**
     * Discord's "Manage Server" permission, and the bits that imply it.
     *
     * Somebody who administers the guild must not need a role assigned
     * in the panel to use it: on a fresh guild there are no roles at
     * all, so requiring one locks the owner out of their own bot — which
     * is exactly what happened.
     *
     * The bitfield is a decimal string because it is wider than 32 bits.
     * `gmp`/`bcmath` are not required by this project, so the test is
     * done on the string with `sprintf('%b')` — PHP's int is 64-bit on
     * every platform this runs on, and the flags checked here are well
     * inside that.
     */
    private const ADMINISTRATOR = 1 << 3;
    private const MANAGE_GUILD = 1 << 5;

    /** Whether Discord itself considers this member an administrator. */
    public function administersGuild(): bool
    {
        if (!ctype_digit($this->permissions) || $this->permissions === '') {
            return false;
        }

        $bits = (int) $this->permissions;

        return ($bits & self::ADMINISTRATOR) !== 0 || ($bits & self::MANAGE_GUILD) !== 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $data = $payload['data'] ?? null;

        if (!is_array($data) || !is_string($data['name'] ?? null)) {
            return null;
        }

        $subcommand = '';
        $options = [];
        $focused = null;

        foreach ($data['options'] ?? [] as $option) {
            if (!is_array($option)) {
                continue;
            }

            // Type 1 is a subcommand; its own options are the arguments.
            if ((int) ($option['type'] ?? 0) === CommandCatalogue::TYPE_SUBCOMMAND) {
                $subcommand = (string) ($option['name'] ?? '');

                foreach ($option['options'] ?? [] as $argument) {
                    if (!is_array($argument) || !is_string($argument['name'] ?? null)) {
                        continue;
                    }

                    $value = $argument['value'] ?? null;

                    if (is_scalar($value)) {
                        $options[$argument['name']] = $value;
                    }

                    if (($argument['focused'] ?? false) === true) {
                        $focused = $argument['name'];
                    }
                }
            }
        }

        $member = $payload['member'] ?? [];
        $user = is_array($member) ? ($member['user'] ?? []) : [];

        return new self(
            (int) ($payload['type'] ?? 0),
            $data['name'],
            $subcommand,
            $options,
            (string) ($payload['guild_id'] ?? ''),
            is_array($user) ? (string) ($user['id'] ?? '') : '',
            is_array($user) ? (string) ($user['global_name'] ?? $user['username'] ?? '') : '',
            self::rolesOf($member),
            is_array($member) ? (string) ($member['permissions'] ?? '0') : '0',
            $focused,
        );
    }

    /** `command.subcommand`, as the capability table keys them. */
    public function name(): string
    {
        return $this->command.'.'.$this->subcommand;
    }

    public function string(string $name, string $fallback = ''): string
    {
        return isset($this->options[$name]) ? (string) $this->options[$name] : $fallback;
    }

    public function integer(string $name, ?int $fallback = null): ?int
    {
        return isset($this->options[$name]) ? (int) $this->options[$name] : $fallback;
    }

    public function boolean(string $name, bool $fallback = false): bool
    {
        return isset($this->options[$name]) ? (bool) $this->options[$name] : $fallback;
    }

    /**
     * @param mixed $member
     *
     * @return list<string>
     */
    private static function rolesOf(mixed $member): array
    {
        if (!is_array($member) || !is_array($member['roles'] ?? null)) {
            return [];
        }

        $roles = [];

        foreach ($member['roles'] as $role) {
            if (is_string($role)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }
}
