<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Settings\SupportedLanguages;
use PHPUnit\Framework\TestCase;

/**
 * The languages the panel speaks, read from the interface's own files.
 *
 * Written after production answered `unsupportedLanguage` for German:
 * the configured directory was `%kernel.project_dir%/../frontend/src`,
 * which the Docker image does not carry, so glob found nothing and
 * every language but English was refused while FTP worked perfectly.
 */
final class SupportedLanguagesTest extends TestCase
{
    private const SHIPPED = ['de', 'en', 'es', 'fr', 'it', 'pl', 'ru'];

    /**
     * The one that matters: a directory that is not there must not
     * silently narrow the panel to English.
     */
    public function testRefusesToPretendOnlyEnglishExistsWhenTheDirectoryIsMissing(): void
    {
        $languages = new SupportedLanguages(__DIR__.'/there-is-no-such-directory');

        self::assertTrue($languages->supports('de'), 'German must survive a missing locale directory');
        self::assertTrue($languages->supports('pl'));
        self::assertContains('de', $languages->all());
    }

    /** The seven the project ships, whichever way they are found. */
    public function testSpeaksEveryShippedLanguage(): void
    {
        $languages = new SupportedLanguages(__DIR__.'/there-is-no-such-directory');

        foreach (self::SHIPPED as $code) {
            self::assertTrue($languages->supports($code), $code.' is shipped but not supported');
        }
    }

    public function testReadsWhatTheDirectoryHolds(): void
    {
        $directory = sys_get_temp_dir().'/locales-'.bin2hex(random_bytes(6));
        mkdir($directory);

        foreach (['de', 'en', 'pt_br'] as $code) {
            file_put_contents($directory.'/'.$code.'.json', '{}');
        }

        file_put_contents($directory.'/readme.txt', 'ignored');

        $languages = new SupportedLanguages($directory);

        self::assertContains('pt_br', $languages->all(), 'a language present as a file must be read');

        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);
    }

    /** The real directory, so the fallback and the files cannot disagree. */
    public function testTheShippedFilesAndTheFallbackNameTheSameLanguages(): void
    {
        $directory = __DIR__.'/../../../../frontend/src/i18n/locales';

        if (!is_dir($directory)) {
            self::markTestSkipped('the frontend sources are not beside the backend here');
        }

        $fromFiles = (new SupportedLanguages($directory))->all();

        self::assertSame(
            self::SHIPPED,
            $fromFiles,
            'the locale files and the compiled fallback have drifted apart',
        );
    }

    public function testIsCaseAndSpaceInsensitive(): void
    {
        $languages = new SupportedLanguages(__DIR__.'/there-is-no-such-directory');

        self::assertTrue($languages->supports(' DE '));
        self::assertFalse($languages->supports('../../etc'));
    }
}
