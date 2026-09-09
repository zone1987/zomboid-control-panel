<?php

declare(strict_types=1);

namespace App\Server\Mods;

use App\Entity\AppSetting;
use App\Settings\SettingsProvider;

final readonly class SettingsSteamCredentials implements SteamCredentials
{
    public function __construct(private SettingsProvider $settings)
    {
    }

    public function steamApiKey(): ?string
    {
        $key = $this->settings->get(AppSetting::STEAM_API_KEY);

        return \is_string($key) && $key !== '' ? $key : null;
    }
}
