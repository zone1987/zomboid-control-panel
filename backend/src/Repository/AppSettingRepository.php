<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    /**
     * Claims a one-off job for exactly one caller.
     *
     * Two panels sharing a database both notice the same release in the
     * same minute, and both would deploy it. An INSERT on a primary key
     * settles that without a lock service: the database allows one, and
     * the loser gets a constraint violation rather than a second
     * deployment.
     *
     * The marker is a plain row rather than an AppSetting value, because
     * that column is encrypted and so cannot be compared in SQL.
     */
    public function claim(string $job): bool
    {
        $connection = $this->getEntityManager()->getConnection();

        try {
            $affected = $connection->executeStatement(
                'INSERT INTO app_setting (name, value, updated_at) VALUES (:name, NULL, :now)
                 ON CONFLICT (name) DO NOTHING',
                ['name' => mb_substr($job, 0, 64), 'now' => new \DateTimeImmutable()],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
        } catch (\Throwable) {
            // A database that cannot record the claim must not be read as
            // "nobody has it": deploying twice is worse than not at all.
            return false;
        }

        return $affected === 1;
    }

    /** Lets the job be claimed again, once its subject has changed. */
    public function release(string $job): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM app_setting WHERE name = :name',
            ['name' => mb_substr($job, 0, 64)],
        );
    }

    /** @return array<string, string> */
    public function findAllAsMap(): array
    {
        $map = [];

        foreach ($this->findAll() as $setting) {
            $value = $setting->getValue();

            if ($value !== null && $value !== '') {
                $map[$setting->getName()] = $value;
            }
        }

        return $map;
    }
}
