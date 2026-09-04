# Brief 03 — Live log, chat, RCON console

**Status:** not started
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

## What "done" looks like

An administrator sees the server log scroll in real time, reads a player's
question in chat, and answers it without leaving the browser.
