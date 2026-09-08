<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel;

use App\Panel\DeployOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An HTTP code is a fact about the protocol, not an instruction.
 *
 * Working out that a Coolify 403 meant the address list rather than the
 * token took an hour of probing by hand. The operator should not repeat
 * it, so the outcome names the setting to change.
 */
final class DeployOutcomeTest extends TestCase
{
    #[DataProvider('refusals')]
    public function testNamesTheSettingToChange(int $status, string $body, string $expected): void
    {
        self::assertSame($expected, DeployOutcome::refused($status, $body)->adviceKey());
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function refusals(): iterable
    {
        // The two 403s are the pair worth telling apart: Coolify names
        // the missing permission when the token is short of one, and
        // says nothing when the address list is what refused.
        yield 'a bare 403 is the address list' => [
            403,
            '{"success":true,"message":"You are not allowed to access the API."}',
            'settings.deploy.adviceAllowlist',
        ];

        yield 'a 403 naming a permission is the token' => [
            403,
            '{"message":"Missing permission: deploy"}',
            'settings.deploy.advicePermission',
        ];

        yield 'a 401 is an unknown token' => [
            401,
            '{"message":"Unauthenticated."}',
            'settings.deploy.adviceToken',
        ];

        // Ours to fix, not the operator's: the request was the wrong shape.
        yield 'a 405 is our own fault' => [
            405,
            '{"message":"This endpoint has changed to a POST request."}',
            'settings.deploy.adviceMethod',
        ];

        yield 'a 404 is the wrong uuid' => [404, '', 'settings.deploy.adviceUuid'];
        yield 'a 429 is the rate limit' => [429, '', 'settings.deploy.adviceRateLimit'];
        yield 'a 500 is the platform itself' => [500, '', 'settings.deploy.advicePlatform'];
        yield 'a 503 too' => [503, '', 'settings.deploy.advicePlatform'];
        yield 'anything else says so plainly' => [418, '', 'settings.deploy.adviceGeneric'];
    }

    public function testHasNoAdviceWhenItWorked(): void
    {
        self::assertNull(DeployOutcome::queued(200, '{"deployments":[]}')->adviceKey());
    }

    public function testAdvisesOnTheAddressWhenNothingAnswered(): void
    {
        self::assertSame(
            'settings.deploy.adviceUnreachable',
            DeployOutcome::unreachable('could not resolve host')->adviceKey(),
        );
    }

    public function testAsksForTheAddressWhenThereIsNone(): void
    {
        self::assertSame(
            'settings.deploy.adviceNotConfigured',
            DeployOutcome::notConfigured()->adviceKey(),
        );
    }

    /**
     * Queued is the only success, and it means the platform accepted the
     * request -- not that the container is up. Treating it as "deployed"
     * would tell the operator something nobody has checked.
     */
    public function testOnlyAQueuedRequestCountsAsSuccess(): void
    {
        self::assertTrue(DeployOutcome::queued(202, '')->succeeded());
        self::assertFalse(DeployOutcome::refused(403, '')->succeeded());
        self::assertFalse(DeployOutcome::unreachable('timeout')->succeeded());
        self::assertFalse(DeployOutcome::notConfigured()->succeeded());
    }

    public function testCarriesTheStatusAndTheBodyForQuoting(): void
    {
        $payload = DeployOutcome::refused(403, 'You are not allowed to access the API.')->toArray();

        self::assertSame('refused', $payload['state']);
        self::assertSame(403, $payload['status']);
        self::assertSame('You are not allowed to access the API.', $payload['detail']);
        self::assertSame('settings.deploy.refused', $payload['message']);
        self::assertSame('settings.deploy.adviceAllowlist', $payload['advice']);
    }
}
