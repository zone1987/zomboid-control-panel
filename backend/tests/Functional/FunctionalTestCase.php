<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A functional test that leaves the database as it found it.
 *
 * Every functional test used to empty the tables it cared about in its
 * own setUp. That cleans up before each test but never after the last
 * one, so whichever class ran last left its rows behind and the next
 * run failed on a duplicate email -- twenty to thirty-five failures,
 * different ones each time, depending on the order.
 *
 * Clearing in tearDown as well fixes it once here rather than in
 * seventeen copies, and a new test inherits it.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    /**
     * Dependents first: a game server holds moderation actions, a user
     * holds credentials and identities, and Postgres enforces both.
     */
    private const TABLES = [
        'App\Entity\ModerationAction',
        'App\Entity\PlayerSnapshot',
        'App\Entity\Invitation',
        'App\Entity\PasswordResetToken',
        'App\Entity\WebauthnCredential',
        'App\Entity\OAuthIdentity',
        'App\Entity\GameServer',
        'App\Entity\User',
        'App\Entity\Role',
        'App\Entity\AppSetting',
    ];

    /**
     * Deliberately not clearing in setUp.
     *
     * Touching the container here would boot the kernel before the test
     * calls createClient(), which Symfony refuses. Clearing afterwards
     * is enough: it is the *last* test leaving rows behind that broke
     * the next run, and each test now leaves none.
     */
    protected function tearDown(): void
    {
        $this->clearDatabase();
        parent::tearDown();
    }

    /**
     * Emptied through the entity manager rather than TRUNCATE, because
     * the test suite runs against the same Postgres as development and
     * a schema-level command would need privileges the app lacks.
     */
    protected function clearDatabase(): void
    {
        // The kernel may not be booted in tearDown if setUp itself threw.
        if (!static::getContainer()->has(EntityManagerInterface::class)) {
            return;
        }

        $manager = static::getContainer()->get(EntityManagerInterface::class);

        if (!$manager instanceof EntityManagerInterface || !$manager->isOpen()) {
            return;
        }

        foreach (self::TABLES as $entity) {
            $manager->createQuery(sprintf('DELETE FROM %s', $entity))->execute();
        }

        $manager->clear();
    }
}
