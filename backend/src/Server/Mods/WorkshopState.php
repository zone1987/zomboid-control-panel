<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * How a workshop lookup ended.
 *
 * Separate cases rather than a nullable result: "no key configured" is
 * something the operator can fix in a minute, "rate limited" resolves
 * itself, and "not found" means the mod is gone from the workshop. A
 * single null would hide which of those happened.
 */
enum WorkshopState: string
{
    case Ok = 'ok';
    case NoKey = 'noKey';
    case RateLimited = 'rateLimited';
    case Unreachable = 'unreachable';
    case NotFound = 'notFound';

    /** Whether a short cache is right: a failure must not outlive its cause. */
    public function isFailure(): bool
    {
        return $this !== self::Ok;
    }
}
