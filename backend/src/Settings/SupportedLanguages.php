<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * The languages this panel speaks, taken from the translation files
 * that ship with the interface.
 *
 * Derived rather than listed: adding fr.json to the frontend is then
 * the whole of adding French, with nothing to keep in step here.
 */
final class SupportedLanguages
{
    /** @var list<string>|null */
    private ?array $codes = null;

    public function __construct(private readonly string $localeDirectory)
    {
    }

    /** @return list<string> lower-case codes such as "de", "pt_br" */
    public function all(): array
    {
        if ($this->codes !== null) {
            return $this->codes;
        }

        $found = [];

        foreach (glob($this->localeDirectory.'/*.json') ?: [] as $path) {
            $code = strtolower(basename($path, '.json'));

            if (preg_match('/^[a-z]{2}(_[a-z]{2})?$/', $code) === 1) {
                $found[] = $code;
            }
        }

        sort($found);

        // English is the fallback the interface itself falls back to, so
        // it is always in the set even if the file were missing.
        if (!\in_array('en', $found, true)) {
            $found[] = 'en';
        }

        return $this->codes = $found;
    }

    public function supports(string $language): bool
    {
        return \in_array(strtolower(trim($language)), $this->all(), true);
    }
}
