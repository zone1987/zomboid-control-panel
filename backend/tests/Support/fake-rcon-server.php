<?php
declare(strict_types=1);

/**
 * Minimal Source RCON server, matching how Project Zomboid answers:
 * plain-text bodies, and "players" listing connected users.
 */

const SERVERDATA_AUTH = 3;
const SERVERDATA_AUTH_RESPONSE = 2;
const SERVERDATA_EXECCOMMAND = 2;
const SERVERDATA_RESPONSE_VALUE = 0;

$password = $argv[1] ?? 'secret';
$port = (int) ($argv[2] ?? 27015);

$server = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);

if (!$server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}

fwrite(STDERR, "fake rcon listening on $port\n");

function readPacket($client): ?array
{
    $sizeRaw = fread($client, 4);

    if ($sizeRaw === false || strlen($sizeRaw) < 4) {
        return null;
    }

    $size = unpack('V', $sizeRaw)[1];
    $body = '';

    while (strlen($body) < $size) {
        $chunk = fread($client, $size - strlen($body));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $body .= $chunk;
    }

    $id = unpack('V', substr($body, 0, 4))[1];
    $type = unpack('V', substr($body, 4, 4))[1];
    $payload = substr($body, 8, -2);

    return ['id' => $id, 'type' => $type, 'body' => $payload];
}

function sendPacket($client, int $id, int $type, string $body): void
{
    $payload = pack('VV', $id, $type).$body."\x00\x00";
    fwrite($client, pack('V', strlen($payload)).$payload);
}

/**
 * A reply long enough that Zomboid splits it. Measured against the real
 * server: "help" arrives as 4086 bytes followed by the remainder, with
 * the continuation sent unprompted.
 */
function longAnswer(): string
{
    $lines = ['List of server commands : '];

    for ($i = 0; $i < 60; ++$i) {
        $lines[] = sprintf(
            '* command%02d : A description long enough to push the whole reply past one packet. Use: /command%02d "username" "argument"',
            $i,
            $i,
        );
    }

    return implode("\n", $lines);
}

function answer(string $command): string
{
    return match (true) {
        $command === 'help' => longAnswer(),
        $command === 'players' => "Players connected (2):\n-Bob\n-Alice",
        str_starts_with($command, 'servermsg') => 'Message sent.',
        str_starts_with($command, 'kick') => "User \u{0001} kicked.",
        $command === 'quit' => 'Quitting.',
        default => 'Unknown command: '.$command,
    };
}

while ($client = @stream_socket_accept($server, 30)) {
    $authorized = false;

    while (($packet = readPacket($client)) !== null) {
        if ($packet['type'] === SERVERDATA_AUTH) {
            $ok = $packet['body'] === $GLOBALS['password'];
            $authorized = $ok;

            // Source protocol: an empty RESPONSE_VALUE precedes the auth result,
            // and a failure answers with id -1.
            sendPacket($client, $packet['id'], SERVERDATA_RESPONSE_VALUE, '');
            sendPacket($client, $ok ? $packet['id'] : -1, SERVERDATA_AUTH_RESPONSE, '');

            continue;
        }

        if ($packet['type'] === SERVERDATA_EXECCOMMAND) {
            $body = $authorized ? answer(trim($packet['body'])) : '';

            // Zomboid caps a packet body at 4086 bytes and sends the rest
            // unprompted, rather than waiting to be asked for it.
            foreach (str_split($body, 4086) ?: [''] as $chunk) {
                sendPacket($client, $packet['id'], SERVERDATA_RESPONSE_VALUE, $chunk);
            }
        }
    }

    fclose($client);
}
