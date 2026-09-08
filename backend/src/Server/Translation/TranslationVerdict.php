<?php

declare(strict_types=1);

namespace App\Server\Translation;

/** Why the names read from the game are, or are not, in the reader's language. */
final readonly class TranslationVerdict
{
    public const TRANSLATED = 'translated';
    public const NO_SUCH_LANGUAGE = 'noSuchLanguage';
    public const UNSUPPORTED_LANGUAGE = 'unsupportedLanguage';
    public const PATH_MISSING = 'pathMissing';
    public const NO_CREDENTIALS = 'noCredentials';
    public const UNREACHABLE = 'unreachable';
    public const UNREADABLE = 'unreadable';

    /** @param array<string, string> $names */
    private function __construct(
        public string $state,
        public ?string $language,
        public array $names,
        public ?string $path,
    ) {
    }

    /** @param array<string, string> $names */
    public static function translated(string $language, array $names, string $path): self
    {
        return new self(self::TRANSLATED, $language, $names, $path);
    }

    /** The installation was reached, but the game ships no file for that language. */
    public static function noSuchLanguage(string $language, string $path): self
    {
        return new self(self::NO_SUCH_LANGUAGE, $language, [], $path);
    }

    /** Not a language the panel itself speaks, so no path was tried. */
    public static function unsupportedLanguage(string $language): self
    {
        return new self(self::UNSUPPORTED_LANGUAGE, $language, [], null);
    }

    /** The game's own `media/` is not where the credentials open. */
    public static function pathMissing(string $language, string $path): self
    {
        return new self(self::PATH_MISSING, $language, [], $path);
    }

    public static function noCredentials(): self
    {
        return new self(self::NO_CREDENTIALS, null, [], null);
    }

    /** The credentials were refused, or the host did not answer. */
    public static function unreachable(string $language, string $path): self
    {
        return new self(self::UNREACHABLE, $language, [], $path);
    }

    /** The file is there but not the table it should be. */
    public static function unreadable(string $language, string $path): self
    {
        return new self(self::UNREADABLE, $language, [], $path);
    }

    /** Only the message key separates "look somewhere else" from "fix the login". */
    public static function fromStorageFailure(string $language, string $path, string $messageKey): self
    {
        return match ($messageKey) {
            'storage.authenticationFailed', 'storage.unreachable' => self::unreachable($language, $path),
            default => self::pathMissing($language, $path),
        };
    }

    public function isTranslated(): bool
    {
        return $this->state === self::TRANSLATED;
    }

    /** A language the game does not ship is not a fault and must not be shown as one. */
    public function needsAttention(): bool
    {
        return \in_array($this->state, [self::PATH_MISSING, self::UNREACHABLE, self::UNREADABLE], true);
    }

    /** @return array{state: string, language: string|null, path: string|null, count: int} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'language' => $this->language,
            'path' => $this->path,
            'count' => \count($this->names),
        ];
    }
}
