<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Server\Chat\ChatBroadcaster;
use App\Server\Items\ItemGiver;
use App\Repository\PlayerSnapshotRepository;
use App\Server\Players\ModerationRecorder;
use App\Server\Players\PlayerModerator;
use App\Entity\RconConfig;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconException;
use App\Server\Rcon\RconUnreachable;
use Psr\Log\LoggerInterface;

/**
 * Carries out one allowed Discord command.
 *
 * Goes through the same services the HTTP controllers use, rather than
 * reimplementing anything: a kick from Discord and a kick from the panel
 * must do the same thing, log the same way, and be refused for the same
 * reasons.
 *
 * **Every action is recorded**, with the Discord name as the reason and
 * `performedBy = null`. The panel has no account for a Discord user, and
 * inventing one would be worse than saying plainly that this came from
 * Discord — `join` and `leave` already record that way.
 */
final readonly class CommandRunner
{
    public function __construct(
        private PlayerModerator $moderator,
        private ItemGiver $items,
        private ChatBroadcaster $chat,
        private PlayerSnapshotRepository $players,
        private RconClientInterface $rcon,
        private ModerationRecorder $recorder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string what to tell the member, already plain text
     */
    public function run(GameServer $server, Interaction $interaction): string
    {
        try {
            return match ($interaction->name()) {
                'spieler.liste' => $this->playerList($server),
                'spieler.info' => $this->playerInfo($server, $interaction),
                'spieler.kick' => $this->kick($server, $interaction),
                'spieler.bannen' => $this->ban($server, $interaction),
                'spieler.entbannen' => $this->unban($server, $interaction),
                'spieler.teleport' => $this->teleport($server, $interaction),
                'spieler.zugriffsstufe' => $this->accessLevel($server, $interaction),
                'spieler.item' => $this->giveItem($server, $interaction),
                'server.status' => $this->serverStatus($server),
                'server.spieler' => $this->playerCount($server),
                'server.nachricht' => $this->broadcast($server, $interaction),
                'server.speichern' => $this->save($server, $interaction),
                'server.konsole' => $this->console($server, $interaction),
                default => 'Dieser Befehl ist noch nicht eingerichtet.',
            };
        } catch (RconException $exception) {
            $this->logger->warning('a discord command could not reach the server', [
                'command' => $interaction->name(),
                'error' => $exception->getMessage(),
            ]);

            return '⚠️ Der Server ist gerade nicht erreichbar.';
        }
    }

    private function playerList(GameServer $server): string
    {
        $online = $this->players->findForServer($server, onlineOnly: true);

        if ($online === []) {
            return 'Gerade ist niemand online.';
        }

        $names = array_map(
            static fn ($player): string => MessageTemplate::escape($player->getUsername()),
            $online,
        );

        return sprintf('**%d online:** %s', count($names), implode(', ', $names));
    }

    private function playerInfo(GameServer $server, Interaction $interaction): string
    {
        $wanted = $interaction->string('spieler');

        foreach ($this->players->findForServer($server) as $player) {
            if (strcasecmp($player->getUsername(), $wanted) !== 0) {
                continue;
            }

            return sprintf(
                '**%s** — %s, Gesundheit %d %%, %s Zombies erledigt, %d Stunden überlebt.',
                MessageTemplate::escape($player->getUsername()),
                $player->isOnline() ? 'online' : 'offline',
                (int) round($player->getHealth() * 100),
                $player->getZombieKills() ?? '?',
                (int) round($player->getHoursSurvived()),
            );
        }

        return sprintf('**%s** ist diesem Server nicht bekannt.', MessageTemplate::escape($wanted));
    }

    private function kick(GameServer $server, Interaction $interaction): string
    {
        $player = $interaction->string('spieler');
        $reply = $this->moderator->kick($server, $player, $interaction->string('grund') ?: null);

        $this->record($server, ModerationAction::KICK, $player, $interaction, $reply);

        return sprintf('👢 **%s** wurde vom Server geworfen.', MessageTemplate::escape($player));
    }

    private function ban(GameServer $server, Interaction $interaction): string
    {
        $player = $interaction->string('spieler');
        $reply = $this->moderator->ban($server, $player, $interaction->string('grund') ?: null);

        $this->record($server, ModerationAction::BAN, $player, $interaction, $reply);

        return sprintf('🔨 **%s** wurde gesperrt.', MessageTemplate::escape($player));
    }

    private function unban(GameServer $server, Interaction $interaction): string
    {
        $player = $interaction->string('spieler');
        $reply = $this->moderator->unban($server, $player);

        $this->record($server, ModerationAction::UNBAN, $player, $interaction, $reply);

        return sprintf('🕊️ Die Sperre von **%s** wurde aufgehoben.', MessageTemplate::escape($player));
    }

    private function teleport(GameServer $server, Interaction $interaction): string
    {
        $player = $interaction->string('spieler');
        $target = $interaction->string('ziel');
        $reply = $this->moderator->teleportToPlayer($server, $player, $target);

        $this->record($server, ModerationAction::TELEPORT, $player, $interaction, $reply);

        return sprintf(
            '🧭 **%s** wurde zu **%s** gebracht.',
            MessageTemplate::escape($player),
            MessageTemplate::escape($target),
        );
    }

    private function accessLevel(GameServer $server, Interaction $interaction): string
    {
        $player = $interaction->string('spieler');
        $level = $interaction->string('stufe');
        $reply = $this->moderator->setAccessLevel($server, $player, $level);

        $this->record($server, ModerationAction::ACCESS_LEVEL, $player, $interaction, $reply);

        return sprintf(
            '🛡️ **%s** hat jetzt die Stufe **%s**.',
            MessageTemplate::escape($player),
            MessageTemplate::escape($level),
        );
    }

    private function giveItem(GameServer $server, Interaction $interaction): string
    {
        $player = $interaction->string('spieler');
        $item = $interaction->string('item');
        $count = max(1, $interaction->integer('anzahl', 1) ?? 1);

        $this->items->give($server, $player, [['type' => $item, 'count' => $count]]);
        $this->record($server, ModerationAction::ITEMS, $player, $interaction, null);

        return sprintf(
            '🎁 **%s** hat %d × %s bekommen.',
            MessageTemplate::escape($player),
            $count,
            MessageTemplate::escape($item),
        );
    }

    private function serverStatus(GameServer $server): string
    {
        $online = count($this->players->findForServer($server, onlineOnly: true));
        $known = count($this->players->findForServer($server));

        return sprintf(
            '**%s** — %d online, %d bekannt.',
            MessageTemplate::escape($server->getName()),
            $online,
            $known,
        );
    }

    private function playerCount(GameServer $server): string
    {
        return sprintf('%d Spieler online.', count($this->players->findForServer($server, onlineOnly: true)));
    }

    private function broadcast(GameServer $server, Interaction $interaction): string
    {
        $text = $interaction->string('text');
        $reply = $this->chat->broadcast($server, $text);

        $this->record($server, ModerationAction::BROADCAST, $text, $interaction, $reply);

        return '📢 Gesendet.';
    }

    private function save(GameServer $server, Interaction $interaction): string
    {
        $reply = $this->rcon->execute($this->rconConfig($server), 'save');

        $this->record($server, ModerationAction::CONSOLE, 'save', $interaction, $reply);

        return '💾 Die Welt wurde gespeichert.';
    }

    private function console(GameServer $server, Interaction $interaction): string
    {
        $command = $interaction->string('befehl');
        $reply = $this->rcon->execute($this->rconConfig($server), $command);

        $this->record($server, ModerationAction::CONSOLE, $command, $interaction, $reply);

        // The reply is the server's own words and may hold anything the
        // server prints -- including its passwords. The redaction at the
        // client is what stops that, and it runs on the way out.
        return $reply === '' ? '⌨️ Ausgeführt.' : "```\n".mb_substr($reply, 0, 1500)."\n```";
    }

    private function rconConfig(GameServer $server): RconConfig
    {
        $config = $server->getRconConfig();

        if ($config === null) {
            throw new RconUnreachable('this server has no RCON credentials');
        }

        return $config;
    }

    /**
     * Writes the action down, marked as having come from Discord.
     *
     * `performedBy` is null because the panel has no account for a
     * Discord user; the name goes in `reason`, which is exactly how
     * `join` and `leave` already record something nobody in the panel
     * did.
     */
    private function record(
        GameServer $server,
        string $action,
        string $subject,
        Interaction $interaction,
        ?string $reply,
    ): void {
        $this->recorder->record(
            $server,
            $action,
            $subject,
            null,
            sprintf('Discord: %s', $interaction->userName),
            $reply,
            null,
            ['source' => 'discord', 'discordUser' => $interaction->userId],
        );
    }
}
