<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\GameServer;
use App\Repository\PlayerSnapshotRepository;
use App\Server\Items\ItemCatalogue;

/**
 * Suggestions while somebody types a command.
 *
 * **This is what makes the bot usable.** A choice list caps at 25, which
 * is nothing against 5000 items or 224 vehicles; autocomplete has no
 * such limit because Discord asks as the operator types. So a player is
 * picked from who is actually online and nobody spells
 * `Base.Trousers_SuitWhite` by hand — the panel's "type nothing you
 * could click", carried into Discord.
 *
 * **Answered from the database and cache only.** Discord allows three
 * seconds for the whole round trip and does not accept a deferred reply
 * for autocomplete, so waiting on the bridge or on RCON here would spend
 * the budget and show the member nothing.
 */
final readonly class Autocomplete
{
    /** Discord's own cap on the suggestions it will show. */
    private const LIMIT = 25;

    public function __construct(
        private PlayerSnapshotRepository $players,
        private ItemCatalogue $items,
    ) {
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    public function suggest(GameServer $server, Interaction $interaction): array
    {
        $typed = mb_strtolower(trim($interaction->string($interaction->focused ?? '')));

        return match ($interaction->focused) {
            'spieler', 'ziel' => $this->players($server, $typed, $interaction->name()),
            'item' => $this->items($server, $typed),
            default => [],
        };
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function players(GameServer $server, string $typed, string $command): array
    {
        // Unbanning is the one case where an offline player is the point,
        // so it is the one that lists everybody known.
        $onlineOnly = $command !== 'spieler.entbannen';
        $found = [];

        foreach ($this->players->findForServer($server, onlineOnly: $onlineOnly) as $player) {
            $name = $player->getUsername();

            if ($typed !== '' && !str_contains(mb_strtolower($name), $typed)) {
                continue;
            }

            $found[] = [
                // The play time turns a list of names into something
                // that says who is who.
                'name' => $onlineOnly
                    ? sprintf('%s (%d h)', $name, (int) round($player->getHoursSurvived()))
                    : $name,
                'value' => $name,
            ];

            if (count($found) >= self::LIMIT) {
                break;
            }
        }

        return $found;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function items(GameServer $server, string $typed): array
    {
        // The catalogue is whatever the bridge last wrote and is served
        // from cache, so this does not wait on the server. When the
        // bridge has never run it is simply empty, and an empty list is
        // an honest answer.
        $catalogue = $this->items->forServer($server);
        $found = [];

        foreach ($catalogue['items'] as $item) {
            $type = (string) ($item['type'] ?? '');

            if ($type === '') {
                continue;
            }

            $name = (string) ($item['name'] ?? $type);

            // Matched against both, because somebody may know either the
            // display name or the script id.
            if ($typed !== ''
                && !str_contains(mb_strtolower($name), $typed)
                && !str_contains(mb_strtolower($type), $typed)
            ) {
                continue;
            }

            $found[] = [
                'name' => mb_substr($name === $type ? $type : $name.' — '.$type, 0, 100),
                'value' => mb_substr($type, 0, 100),
            ];

            if (count($found) >= self::LIMIT) {
                break;
            }
        }

        return $found;
    }
}
