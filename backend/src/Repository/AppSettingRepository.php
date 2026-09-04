<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
