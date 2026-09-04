<?php

declare(strict_types=1);

namespace App\Server\Items;

use App\Entity\GameServer;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconException;
use App\Server\Rcon\RconUnreachable;

/**
 * Puts items into a player's inventory over RCON.
 *
 * The server silently caps a single additem at 100 and says so only on
 * its own console, so a larger request is split here rather than being
 * quietly cut down.
 */
final readonly class ItemGiver
{
    /** What one additem accepts before the server clamps it. */
    public const PER_COMMAND = 100;

    /** A ceiling of our own: beyond this someone has mistyped. */
    public const MAX_TOTAL = 1000;

    public function __construct(private RconClientInterface $rcon)
    {
    }

    /**
     * @param list<array{type: string, count: int}> $items
     *
     * @return list<array{type: string, count: int, reply: string, failed: bool}>
     *
     * @throws RconException when the server cannot be reached at all
     */
    public function give(GameServer $server, string $username, array $items): array
    {
        $config = $server->getRconConfig();

        if ($config === null) {
            throw new RconUnreachable('This server has no RCON configuration.');
        }

        $results = [];

        foreach ($items as $item) {
            $type = self::sanitiseType($item['type']);
            $count = max(1, min(self::MAX_TOTAL, $item['count']));

            if ($type === '') {
                continue;
            }

            $replies = [];
            $failed = false;

            // Split rather than let the server clamp: it caps at 100 and
            // reports that only to its own console, so asking for 250
            // would quietly deliver 100.
            for ($remaining = $count; $remaining > 0; $remaining -= self::PER_COMMAND) {
                $batch = min(self::PER_COMMAND, $remaining);
                $reply = $this->rcon->execute($config, sprintf(
                    'additem "%s" "%s" %d',
                    self::sanitiseUsername($username),
                    $type,
                    $batch,
                ));

                $replies[] = $reply;

                // Zomboid answers in prose; a missing item says so plainly
                // and there is nothing to gain by asking again.
                if (self::looksLikeFailure($reply)) {
                    $failed = true;

                    break;
                }
            }

            $results[] = [
                'type' => $type,
                'count' => $count,
                'reply' => trim(implode(' ', array_unique($replies))),
                'failed' => $failed,
            ];
        }

        return $results;
    }

    public static function looksLikeFailure(string $reply): bool
    {
        return preg_match("/doesn't exist|no such user|unknown/i", $reply) === 1;
    }

    /** Item types are module.name; anything else cannot be a real type. */
    public static function sanitiseType(string $type): string
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,120}$/', trim($type)) === 1 ? trim($type) : '';
    }

    private static function sanitiseUsername(string $username): string
    {
        return str_replace(['"', "\n", "\r"], '', trim($username));
    }
}
