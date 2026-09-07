<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * The slash commands the bot offers, as Discord wants them declared.
 *
 * Grouped by area rather than flat: Discord allows one level of
 * subcommands with 25 options each, which turns the panel's 35 events
 * and 22 player routes into six commands instead of fifty-odd.
 *
 * **Autocomplete is the point.** A choice list is capped at 25 entries,
 * useless for 5000 items or 224 vehicles; autocomplete is not, because
 * Discord asks us as the operator types. So a player name is picked from
 * who is actually online, and nobody types `Base.Trousers_SuitWhite` by
 * hand — the same rule as the panel's "type nothing you could click".
 */
final class CommandCatalogue
{
    /** Discord's option types, from its documented enum. */
    public const TYPE_SUBCOMMAND = 1;
    public const TYPE_STRING = 3;
    public const TYPE_INTEGER = 4;
    public const TYPE_BOOLEAN = 5;

    /**
     * Every command, with its subcommands and their options.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'name' => 'spieler',
                'description' => 'Spieler verwalten',
                'options' => [
                    self::subcommand('liste', 'Wer ist gerade online'),
                    self::subcommand('info', 'Zustand eines Spielers', [
                        self::player(),
                    ]),
                    self::subcommand('kick', 'Einen Spieler vom Server werfen', [
                        self::player(),
                        self::text('grund', 'Warum', required: false),
                    ]),
                    self::subcommand('bannen', 'Einen Spieler sperren', [
                        self::player(),
                        self::text('grund', 'Warum', required: false),
                        self::integer('tage', 'Für wie viele Tage; leer heißt dauerhaft', required: false),
                    ]),
                    self::subcommand('entbannen', 'Eine Sperre aufheben', [
                        self::text('spieler', 'Name oder SteamID', autocomplete: true),
                    ]),
                    self::subcommand('teleport', 'Einen Spieler zu einem anderen bringen', [
                        self::player(),
                        self::text('ziel', 'Zu wem', autocomplete: true),
                    ]),
                    self::subcommand('zugriffsstufe', 'Rechte eines Spielers ändern', [
                        self::player(),
                        self::choices('stufe', 'Welche Stufe', [
                            'none', 'observer', 'gm', 'overseer', 'moderator', 'admin',
                        ]),
                    ]),
                    self::subcommand('item', 'Einem Spieler etwas geben', [
                        self::player(),
                        self::text('item', 'Welches Item', autocomplete: true),
                        self::integer('anzahl', 'Wie viele', required: false),
                    ]),
                ],
            ],
            [
                'name' => 'welt',
                'description' => 'Zeit, Strom und Wasser',
                'options' => [
                    self::subcommand('zeit', 'Uhrzeit im Spiel setzen', [
                        self::integer('stunde', 'Stunde, 0 bis 23'),
                    ]),
                    self::subcommand('strom', 'Strom an- oder abschalten', [
                        self::boolean('an', 'An oder aus'),
                    ]),
                    self::subcommand('wasser', 'Wasser an- oder abschalten', [
                        self::boolean('an', 'An oder aus'),
                    ]),
                ],
            ],
            [
                'name' => 'wetter',
                'description' => 'Das Wetter steuern',
                'options' => [
                    self::subcommand('regen', 'Regen starten', [
                        self::integer('stärke', 'Von 0 bis 100', required: false),
                    ]),
                    self::subcommand('sturm', 'Ein Gewitter starten'),
                    self::subcommand('schnee', 'Schneefall starten', [
                        self::integer('stärke', 'Von 0 bis 100', required: false),
                    ]),
                    self::subcommand('nebel', 'Nebel setzen', [
                        self::integer('stärke', 'Von 0 bis 100', required: false),
                    ]),
                    self::subcommand('klar', 'Das Wetter beruhigen'),
                ],
            ],
            [
                'name' => 'server',
                'description' => 'Den Server ansehen und ansprechen',
                'options' => [
                    self::subcommand('status', 'Läuft er, und wie viele sind drauf'),
                    self::subcommand('spieler', 'Wie viele online sind'),
                    self::subcommand('nachricht', 'Allen Spielern etwas sagen', [
                        self::text('text', 'Was gesendet wird'),
                    ]),
                    self::subcommand('speichern', 'Die Welt jetzt speichern'),
                    self::subcommand('konsole', 'Einen RCON-Befehl senden', [
                        self::text('befehl', 'Der Befehl'),
                    ]),
                ],
            ],
        ];
    }

    /** Every `command.subcommand` the catalogue declares. */
    /** @return list<string> */
    public static function names(): array
    {
        $names = [];

        foreach (self::all() as $command) {
            foreach ($command['options'] ?? [] as $option) {
                $names[] = $command['name'].'.'.$option['name'];
            }
        }

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $options
     *
     * @return array<string, mixed>
     */
    private static function subcommand(string $name, string $description, array $options = []): array
    {
        $declared = ['type' => self::TYPE_SUBCOMMAND, 'name' => $name, 'description' => $description];

        return $options === [] ? $declared : $declared + ['options' => $options];
    }

    /**
     * A player, always by autocomplete.
     *
     * Suggested from who is actually online, with their play time — so
     * a name is picked rather than spelled, and a typo cannot silently
     * target nobody.
     *
     * @return array<string, mixed>
     */
    private static function player(): array
    {
        return self::text('spieler', 'Welcher Spieler', autocomplete: true);
    }

    /** @return array<string, mixed> */
    private static function text(
        string $name,
        string $description,
        bool $required = true,
        bool $autocomplete = false,
    ): array {
        $option = [
            'type' => self::TYPE_STRING,
            'name' => $name,
            'description' => $description,
            'required' => $required,
        ];

        return $autocomplete ? $option + ['autocomplete' => true] : $option;
    }

    /** @return array<string, mixed> */
    private static function integer(string $name, string $description, bool $required = true): array
    {
        return [
            'type' => self::TYPE_INTEGER,
            'name' => $name,
            'description' => $description,
            'required' => $required,
        ];
    }

    /** @return array<string, mixed> */
    private static function boolean(string $name, string $description): array
    {
        return [
            'type' => self::TYPE_BOOLEAN,
            'name' => $name,
            'description' => $description,
            'required' => true,
        ];
    }

    /**
     * A fixed list, for the few options that really have one.
     *
     * Choices are capped at 25 by Discord, so this is only for sets that
     * are genuinely small and stable — the six access levels are, the
     * 5000 items are not.
     *
     * @param list<string> $values
     *
     * @return array<string, mixed>
     */
    private static function choices(string $name, string $description, array $values): array
    {
        return [
            'type' => self::TYPE_STRING,
            'name' => $name,
            'description' => $description,
            'required' => true,
            'choices' => array_map(
                static fn (string $value): array => ['name' => $value, 'value' => $value],
                $values,
            ),
        ];
    }
}
