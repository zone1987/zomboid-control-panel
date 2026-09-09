<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * The one thing the workshop client needs from the panel's settings.
 *
 * A narrow interface rather than the whole SettingsProvider, which is
 * final and would make this untestable: a client that takes a single
 * method cannot later reach for the mail password (rule 10i).
 */
interface SteamCredentials
{
    /** Null when no key is stored, which is a state, not a failure. */
    public function steamApiKey(): ?string;
}
