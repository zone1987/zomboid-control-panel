# Brief 03 — Live log, chat, RCON console

**Status:** complete — log, chat and console all built and verified live
**Depends on:** [01](01-foundation-auth-servers.md), [02](02-lua-bridge-players.md)

## Why this exists

Administrators watch logs and talk to players. Doing that over SSH and an
in-game window means being at a computer with the game running. This makes
both possible from a browser.

## In scope

- Live server log, tailed over SFTP and streamed to the browser
- Chat in both directions: reading what players say, and answering
- Broadcasts to everyone and private messages to one player
- An RCON console for arbitrary commands

## Constraints established by research

- **There is no server-side chat event.** `OnAddMessage` fires only in the
  client (`media/lua/client/Chat/ISChat.lua:1176`). Chat is read from the
  engine's own `_chat.txt` log and sent with RCON `servermsg`.
- Flysystem cannot read from an offset; tailing needs phpseclib directly.
- Two traps when tailing: a rotated log becomes shorter than the remembered
  offset (reset to zero), and a byte offset can land mid-character (discard
  up to the first newline).

## Command discovery, requested 2026-09-04

`help` over RCON returns the command list of the server actually running,
which beats shipping a list compiled from one build and hoping it still
matches. The console should:

- Fetch `help` once per server and cache the result
- Offer the commands in a searchable dropdown with their syntax
- Check arguments against that syntax before sending, so a mistyped
  command fails in the browser rather than silently doing nothing

Two things to establish first, neither of which is settled:

- The exact shape of Zomboid's `help` output. The command classes are
  known from the jar (67 of them, with syntax and required capability),
  but whether `help` prints them in a parseable form has not been
  checked against a live server.
- Whether `help` lists only what the connected account may run. If it
  does, the dropdown reflects permissions for free; if not, unavailable
  commands need marking some other way.

Fall back to the catalogue extracted from the jar when the output cannot
be parsed — a stale list beats no list.

## What "done" looks like

An administrator sees the server log scroll in real time, reads a player's
question in chat, and answers it without leaving the browser. Typing in the
console offers the commands the server actually accepts, and rejects a wrong
argument count before it reaches the server.
