<?php

declare(strict_types=1);

namespace App\Panel;

/**
 * What a read-only probe of the hosting platform found.
 *
 * Separate from {@see DeployOutcome} because a probe answers a
 * different question: not "did the deployment start" but "would it be
 * able to". Each state names one thing to change.
 */
final readonly class DeployProbeVerdict
{
    public const READY = 'ready';
    public const NO_READ_PERMISSION = 'noReadPermission';
    public const TOKEN_REJECTED = 'tokenRejected';
    public const NOT_FOUND = 'notFound';
    public const UNREACHABLE = 'unreachable';
    public const NOT_CONFIGURED = 'notConfigured';
    public const NO_UUID = 'noUuid';
    public const REFUSED = 'refused';

    private function __construct(
        public string $state,
        public ?int $status,
        public ?string $detail,
        public ?string $applicationName,
        /** The platform's own word for whether it is running. */
        public ?string $applicationState = null,
    ) {
    }

    public static function ready(?string $applicationName, ?string $applicationState = null): self
    {
        return new self(self::READY, 200, null, $applicationName, $applicationState);
    }

    /**
     * The token works but may not read.
     *
     * Deploying can still succeed: Coolify's `deploy` and `read` are
     * separate permissions, so this is a limit of the probe rather
     * than a fault in the configuration.
     */
    public static function noReadPermission(int $status, ?string $detail): self
    {
        return new self(self::NO_READ_PERMISSION, $status, $detail, null);
    }

    public static function tokenRejected(int $status, ?string $detail): self
    {
        return new self(self::TOKEN_REJECTED, $status, $detail, null);
    }

    /** Reached and understood, but no application carries that uuid. */
    public static function notFound(int $status, ?string $detail): self
    {
        return new self(self::NOT_FOUND, $status, $detail, null);
    }

    public static function unreachable(string $detail): self
    {
        return new self(self::UNREACHABLE, null, $detail, null);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, null, null, null);
    }

    /** The webhook carries no uuid, so there is nothing to look up. */
    public static function noUuid(): self
    {
        return new self(self::NO_UUID, null, null, null);
    }

    public static function refused(int $status, ?string $detail): self
    {
        return new self(self::REFUSED, $status, $detail, null);
    }

    /** Whether a deployment could be expected to work. */
    public function looksReady(): bool
    {
        return $this->state === self::READY || $this->state === self::NO_READ_PERMISSION;
    }

    public function messageKey(): string
    {
        return 'settings.deploy.probe.'.$this->state;
    }

    /** @return array{state: string, status: int|null, detail: string|null, applicationName: string|null, applicationState: string|null, messageKey: string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'status' => $this->status,
            'detail' => $this->detail,
            'applicationName' => $this->applicationName,
            'applicationState' => $this->applicationState,
            'messageKey' => $this->messageKey(),
        ];
    }
}
