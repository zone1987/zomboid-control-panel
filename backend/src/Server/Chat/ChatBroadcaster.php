<?php

declare(strict_types=1);

namespace App\Server\Chat;

use App\Entity\GameServer;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconException;
use App\Server\Rcon\RconUnreachable;

/**
 * Sends a message to everyone on the server.
 *
 * RCON offers only "servermsg", which broadcasts. There is no command
 * that addresses a single player, and none that sends as a chosen
 * name -- the message always appears as coming from the server.
 */
final readonly class ChatBroadcaster
{
    /** Long enough for a sentence; the game truncates well before this. */
    public const MAX_LENGTH = 250;

    public function __construct(private RconClientInterface $rcon)
    {
    }

    /** @throws RconException */
    public function broadcast(GameServer $server, string $message): string
    {
        $config = $server->getRconConfig();

        if ($config === null) {
            throw new RconUnreachable('This server has no RCON configuration.');
        }

        return $this->rcon->execute($config, sprintf('servermsg "%s"', self::sanitise($message)));
    }

    /**
     * The message travels as a quoted argument, so a quote of its own
     * would end it early and the rest would be read as another argument.
     */
    public static function sanitise(string $message): string
    {
        // Line breaks and tabs become spaces so the message stays on one
        // line; the rest are removed outright rather than leaving gaps.
        $clean = str_replace(["\r\n", "\n", "\r", "\t"], ' ', trim($message));
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', '', $clean) ?? '';
        $clean = str_replace('"', "'", $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';

        return mb_substr(trim($clean), 0, self::MAX_LENGTH);
    }
}
