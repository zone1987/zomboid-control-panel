<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Items;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Items\ItemTranslations;
use App\Server\Translation\TranslationVerdict;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use App\Tests\Support\Cache\RecordingCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Five ways of having no translation used to look identical -- an empty
 * array -- so an operator saw English item names with nothing said.
 */
final class ItemTranslationsTest extends TestCase
{
    public function testReadsTheNamesTheGameShipsForThatLanguage(): void
    {
        $verdict = $this->reading(json_encode(
            ['Base.PipeBomb' => 'Rohrbombe'],
            \JSON_THROW_ON_ERROR,
        ))->verdictFor($this->server(), 'de');

        self::assertSame(TranslationVerdict::TRANSLATED, $verdict->state);
        self::assertSame('DE', $verdict->language);
        self::assertSame('Rohrbombe', $verdict->names['Base.PipeBomb']);
        self::assertSame('media/lua/shared/Translate/DE/ItemName.json', $verdict->path);
        self::assertFalse($verdict->needsAttention());
    }

    /**
     * The likeliest cause on a rented server: the transfer credentials
     * open on the savegame directory, so the game's own media/ is
     * somewhere else entirely.
     */
    public function testCallsItAMissingPathWhenTheTranslateDirectoryIsNotThere(): void
    {
        $verdict = $this->failing('storage.operationFailed', false)
            ->verdictFor($this->server(), 'de');

        self::assertSame(TranslationVerdict::PATH_MISSING, $verdict->state);
        self::assertTrue($verdict->needsAttention());
        self::assertSame('media/lua/shared/Translate/DE/ItemName.json', $verdict->path);
    }

    /** The installation is there; this language simply is not shipped. */
    public function testCallsItAnAbsentLanguageWhenTheDirectoryIsThere(): void
    {
        $verdict = $this->failing('storage.operationFailed', true)
            ->verdictFor($this->server(), 'pl');

        self::assertSame(TranslationVerdict::NO_SUCH_LANGUAGE, $verdict->state);
        self::assertFalse($verdict->needsAttention(), 'a language the game does not ship is not a fault');
    }

    public function testCallsItUnreachableWhenTheCredentialsAreRefused(): void
    {
        $verdict = $this->failing('storage.authenticationFailed', true)
            ->verdictFor($this->server(), 'de');

        self::assertSame(TranslationVerdict::UNREACHABLE, $verdict->state);
        self::assertTrue($verdict->needsAttention());
    }

    public function testCallsItUnreachableWhenTheHostDoesNotAnswer(): void
    {
        self::assertSame(
            TranslationVerdict::UNREACHABLE,
            $this->failing('storage.unreachable', true)->verdictFor($this->server(), 'de')->state,
        );
    }

    /**
     * A refused login must not be reported as a missing path just
     * because the second question could not be asked either.
     */
    public function testDoesNotAskTheDirectoryWhenTheLoginItselfFailed(): void
    {
        $files = $this->createMock(FileBrowserInterface::class);
        $files->method('readTail')->willThrowException(
            new StorageException('storage.authenticationFailed', 'password refused'),
        );
        $files->expects(self::never())->method('directoryExists');

        $translations = new ItemTranslations($files, new ArrayAdapter());

        self::assertSame(
            TranslationVerdict::UNREACHABLE,
            $translations->verdictFor($this->server(), 'de')->state,
        );
    }

    public function testKeepsTheFailingVerdictWhenTheDirectoryCheckAlsoFails(): void
    {
        $files = $this->createStub(FileBrowserInterface::class);
        $files->method('readTail')->willThrowException(
            new StorageException('storage.operationFailed', 'not found'),
        );
        $files->method('directoryExists')->willThrowException(
            new StorageException('storage.operationFailed', 'not found either'),
        );

        $translations = new ItemTranslations($files, new ArrayAdapter());

        self::assertSame(
            TranslationVerdict::PATH_MISSING,
            $translations->verdictFor($this->server(), 'de')->state,
        );
    }

    public function testCallsItUnreadableWhenTheFileIsNotATable(): void
    {
        self::assertSame(
            TranslationVerdict::UNREADABLE,
            $this->reading('<html>404</html>')->verdictFor($this->server(), 'de')->state,
        );
    }

    /** An empty but well-formed table is not a translation either. */
    public function testCallsItUnreadableWhenTheTableCarriesNoName(): void
    {
        self::assertSame(
            TranslationVerdict::UNREADABLE,
            $this->reading('{}')->verdictFor($this->server(), 'de')->state,
        );
    }

    public function testSaysSoWhenThereAreNoCredentialsAtAll(): void
    {
        $files = $this->createMock(FileBrowserInterface::class);
        $files->expects(self::never())->method('readTail');

        $translations = new ItemTranslations($files, new ArrayAdapter());
        $verdict = $translations->verdictFor(new GameServer('No transfer'), 'de');

        self::assertSame(TranslationVerdict::NO_CREDENTIALS, $verdict->state);
        self::assertNull($verdict->path);
        self::assertFalse($verdict->needsAttention());
    }

    public function testRefusesALanguageCodeNobodyCouldHave(): void
    {
        $files = $this->createMock(FileBrowserInterface::class);
        $files->expects(self::never())->method('readTail');

        $translations = new ItemTranslations($files, new ArrayAdapter());
        $verdict = $translations->verdictFor($this->server(), '../../etc');

        self::assertSame(TranslationVerdict::UNSUPPORTED_LANGUAGE, $verdict->state);
        self::assertNull($verdict->path);
    }

    /**
     * The old code cached the empty array for a week, so an operator who
     * fixed their FTP path still read English names seven days later.
     */
    public function testRetriesAFailureLongBeforeItRetriesASuccess(): void
    {
        $failing = new RecordingCache();
        $files = $this->createStub(FileBrowserInterface::class);
        $files->method('readTail')->willThrowException(
            new StorageException('storage.operationFailed', 'not found'),
        );
        $files->method('directoryExists')->willReturn(false);

        (new ItemTranslations($files, $failing))->verdictFor($this->server(), 'de');

        $succeeding = new RecordingCache();
        $reader = $this->createStub(FileBrowserInterface::class);
        $reader->method('readTail')->willReturn(json_encode(
            ['Base.PipeBomb' => 'Rohrbombe'],
            \JSON_THROW_ON_ERROR,
        ));

        (new ItemTranslations($reader, $succeeding))->verdictFor($this->server(), 'de');

        self::assertLessThan(
            $succeeding->lifetime,
            $failing->lifetime,
            'a failure must expire sooner than a translation',
        );
        self::assertLessThanOrEqual(3600, $failing->lifetime);
        self::assertGreaterThan(86400, $succeeding->lifetime);
    }

    /**
     * "Neue Fassung laden" must not hand back new items under the names
     * a week-old failure left behind.
     */
    public function testARefreshReadsTheFileAgainRatherThanTheCache(): void
    {
        $files = $this->createMock(FileBrowserInterface::class);
        $files->expects(self::exactly(2))->method('readTail')->willReturn(json_encode(
            ['Base.PipeBomb' => 'Rohrbombe'],
            \JSON_THROW_ON_ERROR,
        ));

        $translations = new ItemTranslations($files, new ArrayAdapter());
        $server = $this->server();

        $translations->verdictFor($server, 'de');
        $translations->verdictFor($server, 'de', true);
    }

    /** A refresh that fails must replace the held verdict, not hide behind it. */
    public function testARefreshOverwritesAHeldTranslationWithTheNewVerdict(): void
    {
        $cache = new ArrayAdapter();
        $server = $this->server();

        $reader = $this->createStub(FileBrowserInterface::class);
        $reader->method('readTail')->willReturn(json_encode(
            ['Base.PipeBomb' => 'Rohrbombe'],
            \JSON_THROW_ON_ERROR,
        ));

        self::assertTrue((new ItemTranslations($reader, $cache))->verdictFor($server, 'de')->isTranslated());

        $broken = $this->createStub(FileBrowserInterface::class);
        $broken->method('readTail')->willThrowException(
            new StorageException('storage.authenticationFailed', 'refused'),
        );

        $verdict = (new ItemTranslations($broken, $cache))->verdictFor($server, 'de', true);

        self::assertSame(TranslationVerdict::UNREACHABLE, $verdict->state);
        self::assertSame(
            TranslationVerdict::UNREACHABLE,
            (new ItemTranslations($broken, $cache))->verdictFor($server, 'de')->state,
            'the failing verdict has to be what is held now',
        );
    }

    public function testReadsTheFileOnceAndThenTheCache(): void
    {
        $files = $this->createMock(FileBrowserInterface::class);
        $files->expects(self::once())->method('readTail')->willReturn(json_encode(
            ['Base.PipeBomb' => 'Rohrbombe'],
            \JSON_THROW_ON_ERROR,
        ));

        $translations = new ItemTranslations($files, new ArrayAdapter());
        $server = $this->server();

        $translations->verdictFor($server, 'de');

        self::assertSame('Rohrbombe', $translations->forLanguage($server, 'de')['Base.PipeBomb']);
    }

    /** Every existing caller reads the array and must keep working. */
    public function testForLanguageStillHandsBackTheNames(): void
    {
        $names = $this->reading(json_encode(
            ['Base.PipeBomb' => 'Rohrbombe'],
            \JSON_THROW_ON_ERROR,
        ))->forLanguage($this->server(), 'de');

        self::assertSame(['Base.PipeBomb' => 'Rohrbombe'], $names);
    }

    private function reading(string $payload): ItemTranslations
    {
        $files = $this->createStub(FileBrowserInterface::class);
        $files->method('readTail')->willReturn($payload);

        return new ItemTranslations($files, new ArrayAdapter());
    }

    private function failing(string $messageKey, bool $directoryExists): ItemTranslations
    {
        $files = $this->createStub(FileBrowserInterface::class);
        $files->method('readTail')->willThrowException(new StorageException($messageKey, 'failed'));
        $files->method('directoryExists')->willReturn($directoryExists);

        return new ItemTranslations($files, new ArrayAdapter());
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');

        // The constructor attaches itself to the server.
        new FtpConfig($server, 'localhost', 'user');

        return $server;
    }
}
