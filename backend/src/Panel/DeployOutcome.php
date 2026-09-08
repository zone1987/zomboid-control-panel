<?php

declare(strict_types=1);

namespace App\Panel;

/**
 * What came back from the deploy hook.
 *
 * Four states rather than a boolean, because they need four different
 * things from the operator: nothing to do, fix the address, fix the
 * token, or wait. Collapsing "refused" into "failed" would leave them
 * guessing which.
 */
final readonly class DeployOutcome
{
    private function __construct(
        public string $state,
        public ?int $status,
        public ?string $detail,
        /** Coolify names the deployment it queued; the interface follows that one. */
        public ?string $deploymentUuid = null,
    ) {
    }

    public static function queued(int $status, string $detail, ?string $deploymentUuid = null): self
    {
        return new self('queued', $status, $detail === '' ? null : $detail, $deploymentUuid);
    }

    /** Reached and answered, but the answer was no. */
    public static function refused(int $status, string $detail): self
    {
        return new self('refused', $status, $detail === '' ? null : $detail);
    }

    /** Never answered: wrong address, no network, or the platform is down. */
    public static function unreachable(string $detail): self
    {
        return new self('unreachable', null, $detail);
    }

    public static function notConfigured(): self
    {
        return new self('notConfigured', null, null);
    }

    public function succeeded(): bool
    {
        return $this->state === 'queued';
    }

    /**
     * The message key the interface shows, so the reason is stated in
     * the reader's own language rather than in the platform's.
     */
    public function messageKey(): string
    {
        return match ($this->state) {
            'queued' => 'settings.deploy.queued',
            'refused' => 'settings.deploy.refused',
            'unreachable' => 'settings.deploy.unreachable',
            default => 'settings.deploy.notConfigured',
        };
    }

    /**
     * What to do about it, named rather than left to be worked out.
     *
     * An HTTP code is a fact about the protocol, not an instruction. 403
     * against a Coolify instance means one of two settings, and which
     * one is knowable: the platform names the missing permission when a
     * token is short of one, and says nothing when the address list is
     * what refused. Working that out took an hour by hand; it should
     * take the operator none.
     */
    public function adviceKey(): ?string
    {
        if ($this->state === 'queued') {
            return null;
        }

        if ($this->state === 'unreachable') {
            return 'settings.deploy.adviceUnreachable';
        }

        if ($this->state === 'notConfigured') {
            return 'settings.deploy.adviceNotConfigured';
        }

        $detail = strtolower($this->detail ?? '');

        return match (true) {
            // Sent as GET by an older recipe; current Coolify wants POST.
            $this->status === 405 => 'settings.deploy.adviceMethod',
            // The token was not recognised at all.
            $this->status === 401 => 'settings.deploy.adviceToken',
            // Recognised but not allowed. Coolify names the missing
            // permission when that is the cause, and does not when the
            // address list is.
            $this->status === 403 && str_contains($detail, 'permission')
                => 'settings.deploy.advicePermission',
            $this->status === 403 => 'settings.deploy.adviceAllowlist',
            $this->status === 404 => 'settings.deploy.adviceUuid',
            $this->status === 429 => 'settings.deploy.adviceRateLimit',
            $this->status !== null && $this->status >= 500 => 'settings.deploy.advicePlatform',
            default => 'settings.deploy.adviceGeneric',
        };
    }

    /**
     * @return array{
     *     state: string,
     *     status: int|null,
     *     detail: string|null,
     *     message: string,
     *     advice: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'status' => $this->status,
            'detail' => $this->detail,
            'message' => $this->messageKey(),
            'advice' => $this->adviceKey(),
            'deploymentUuid' => $this->deploymentUuid,
        ];
    }
}
