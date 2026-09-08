<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\AppSettingRepository;

/**
 * Two panels sharing a database notice the same release in the same
 * minute. Both would deploy it, and the operator would get two restarts
 * for one version -- so exactly one caller may win.
 */
final class DeployClaimTest extends FunctionalTestCase
{
    private AppSettingRepository $settings;

    protected function setUp(): void
    {
        self::createClient();

        $this->settings = self::getContainer()->get(AppSettingRepository::class);
        $this->settings->release('deploy.requested.9.9.9');
    }

    protected function tearDown(): void
    {
        $this->settings->release('deploy.requested.9.9.9');

        parent::tearDown();
    }

    public function testOnlyTheFirstCallerGetsTheJob(): void
    {
        self::assertTrue($this->settings->claim('deploy.requested.9.9.9'));
        self::assertFalse($this->settings->claim('deploy.requested.9.9.9'));
        self::assertFalse($this->settings->claim('deploy.requested.9.9.9'));
    }

    /** A different release is a different job, and must not be blocked. */
    public function testTheNextReleaseCanBeClaimed(): void
    {
        self::assertTrue($this->settings->claim('deploy.requested.9.9.9'));

        try {
            self::assertTrue($this->settings->claim('deploy.requested.9.9.10'));
        } finally {
            $this->settings->release('deploy.requested.9.9.10');
        }
    }

    public function testReleasingLetsItBeClaimedAgain(): void
    {
        self::assertTrue($this->settings->claim('deploy.requested.9.9.9'));

        $this->settings->release('deploy.requested.9.9.9');

        self::assertTrue($this->settings->claim('deploy.requested.9.9.9'));
    }
}
