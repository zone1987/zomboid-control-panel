<?php

declare(strict_types=1);

namespace App\Server\Rcon;

/**
 * The command list a server reports through "help", parsed.
 *
 * Zomboid answers in prose, one line per command:
 *
 *     * additem : Give an item to a player. Use: /additem "username" "module.item" count
 *
 * The list is what the connected account may run rather than everything
 * the build contains -- a live server reported 60 where the jar holds 67
 * -- so it is read from the server rather than shipped with the panel.
 */
final readonly class CommandCatalogue
{
    /**
     * @param list<array{
     *     name: string,
     *     description: string,
     *     usage: string|null,
     *     parameters: list<array{name: string, quoted: bool, optional: bool}>,
     *     example: string|null
     * }> $commands
     */
    public function __construct(public array $commands)
    {
    }

    public static function parse(string $reply): self
    {
        $commands = [];

        foreach (preg_split('/\R/', $reply) ?: [] as $line) {
            $command = self::parseLine(trim($line));

            if ($command !== null) {
                $commands[$command['name']] = $command;
            }
        }

        ksort($commands);

        return new self(array_values($commands));
    }

    public function isEmpty(): bool
    {
        return $this->commands === [];
    }

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     usage: string|null,
     *     parameters: list<array{name: string, quoted: bool, optional: bool}>,
     *     example: string|null
     * }|null
     */
    private static function parseLine(string $line): ?array
    {
        if (preg_match('/^\*\s*([A-Za-z0-9_]+)\s*:\s*(.+)$/', $line, $matches) !== 1) {
            return null;
        }

        $name = strtolower($matches[1]);
        $body = trim($matches[2]);

        // The usage runs to "Example:" or to the end. A plain "[^.]*"
        // would stop at the dot inside an argument like "module.item".
        $usage = self::extract($body, '/\bUse:?\s*(\/.*?)(?=\s*\bExample\b|\.\s*$|$)/is');
        $example = self::extract($body, '/\bExample:?\s*(\/.*)$/is');

        return [
            'name' => $name,
            'description' => self::describe($body),
            'usage' => $usage,
            'parameters' => $usage === null ? [] : self::parameters($usage, $body),
            'example' => $example,
        ];
    }

    /**
     * Zomboid's own help sometimes names a different command in the usage
     * than in the entry -- "kick" documents "/kickuser". Only the
     * arguments after the verb are taken, so the caller keeps the name the
     * server actually listed.
     *
     * @return list<array{name: string, quoted: bool, optional: bool}>
     */
    private static function parameters(string $usage, string $description): array
    {
        $withoutVerb = preg_replace('/^\/\S+\s*/', '', trim($usage)) ?? '';

        if (trim($withoutVerb) === '') {
            return [];
        }

        // "optional" is stated in prose rather than in the syntax, so the
        // description decides which arguments may be left out.
        $optionalNames = self::optionalNames($description);
        $parameters = [];

        preg_match_all('/"([^"]+)"|(-?\S+)/', $withoutVerb, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $quoted = ($match[1] ?? '') !== '';
            $raw = $quoted ? $match[1] : ($match[2] ?? '');
            $clean = trim($raw, " \t\"'.,");

            if ($clean === '' || str_starts_with($clean, '/')) {
                continue;
            }

            $parameters[] = [
                'name' => $clean,
                'quoted' => $quoted,
                'optional' => self::isOptional($clean, $optionalNames),
            ];
        }

        return $parameters;
    }

    /** @return list<string> */
    private static function optionalNames(string $description): array
    {
        $names = [];

        // "Count is optional", "password is optional", "Key name is optional"
        if (preg_match_all('/\b([A-Za-z][A-Za-z ]{0,20}?)\s+is\s+optional/i', $description, $matches) >= 1) {
            foreach ($matches[1] as $phrase) {
                $words = preg_split('/\s+/', strtolower(trim($phrase))) ?: [];
                $names[] = end($words) ?: '';
            }
        }

        if (preg_match('/if\s+no\s+username\s+is\s+given/i', $description) === 1) {
            $names[] = 'username';
        }

        return array_values(array_filter($names));
    }

    /** @param list<string> $optionalNames */
    private static function isOptional(string $parameter, array $optionalNames): bool
    {
        $needle = strtolower($parameter);

        foreach ($optionalNames as $optional) {
            if ($optional !== '' && str_contains($needle, $optional)) {
                return true;
            }
        }

        return false;
    }

    private static function describe(string $body): string
    {
        $description = preg_replace('/\b(Use|Example):?\s*\/.*$/is', '', $body) ?? $body;

        return trim($description) === '' ? trim($body) : trim($description);
    }

    private static function extract(string $subject, string $pattern): ?string
    {
        if (preg_match($pattern, $subject, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1], " \t.");

        return $value === '' ? null : $value;
    }
}
