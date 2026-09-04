<?php

declare(strict_types=1);

namespace App\Settings;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads application credentials, preferring a value stored through the
 * interface over the environment variable it falls back to.
 */
final class SettingsProvider
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    /** @param array<string, string> $envDefaults */
    public function __construct(
        private readonly AppSettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly array $envDefaults,
    ) {
    }

    public function get(string $name): ?string
    {
        $this->cache ??= $this->settings->findAllAsMap();

        $stored = $this->cache[$name] ?? null;

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $fallback = $this->envDefaults[$name] ?? null;

        return $fallback !== null && $fallback !== '' ? $fallback : null;
    }

    public function isConfigured(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * True when the value comes from the environment rather than the
     * database, so the interface can say the field is locked by deployment.
     */
    public function isFromEnvironment(string $name): bool
    {
        $this->cache ??= $this->settings->findAllAsMap();

        $stored = $this->cache[$name] ?? null;

        return ($stored === null || $stored === '') && ($this->envDefaults[$name] ?? '') !== '';
    }

    public function set(string $name, ?string $value): void
    {
        $setting = $this->settings->find($name) ?? new AppSetting($name);
        $setting->setValue($value === '' ? null : $value);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        $this->cache = null;
    }
}
