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
    /**
     * The languages shipped when this was built.
     *
     * The directory below is the source of truth where it exists, but
     * the Docker image carries the compiled interface without its
     * sources -- and an unreadable directory must not narrow the panel
     * to English while reporting nothing.
     * `SupportedLanguagesTest` fails if the two disagree.
     *
     * @var list<string>
     */
    private const SHIPPED = ['de', 'en', 'es', 'fr', 'it', 'pl', 'ru'];

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

        // A missing or unreadable directory is not an installation that
        // speaks one language; it is one whose files cannot be read.
        $found = array_values(array_unique([...$found, ...self::SHIPPED]));

        sort($found);

        return $this->codes = $found;
    }

    public function supports(string $language): bool
    {
        return \in_array(strtolower(trim($language)), $this->all(), true);
    }
}
