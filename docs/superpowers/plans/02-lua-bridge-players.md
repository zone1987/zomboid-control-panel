# Plan 02 — Lua bridge and player management

**Brief:** [../briefs/02-lua-bridge-players.md](../briefs/02-lua-bridge-players.md)
**Status:** backend done and verified live; interface outstanding

## Step 1 — Spike: RCON against a real server ✅ done

Resolved during sub-project 01. `xpaw/php-source-query-class` talks to a real
Zomboid server.

## Step 2 — Lua bridge ✅ done

`ZomboidControlBridge.lua`, uploaded from the interface, running on the user's
server and writing correct data.

Every call was checked against the jar with javap before it went near a live
server, which caught three that would have failed:
`getInfectionLevel()` does not exist (`getApparentInfectionLevel()`),
`isInfected()` is lowercase, and `getSteamID()` returns a long.

Switched from `getModFileWriter` to `getFileWriter`: the former targets the
mod's own `common/` directory and its behaviour without one is unestablished,
while the latter documents its target. It also creates missing subdirectories
on its own, which the verification could not establish and the live run
confirmed.

Version 0.2.0 adds skills and traits. **The user's server still runs 0.1.0** —
it needs uploading again and a restart to pick them up.

## Step 3 — Reading the bridge ✅ done

`BridgeStatusReader` reads the file over SFTP and stores one row per player
per server, updated in place. Refreshed on request rather than by a worker:
the file is small and a round trip is cheap.

Snapshots carry position, health, infection, hours survived, access level,
skills and traits. Verified against the live server.

## Step 4 — Player list interface ⬜ open

**This is the next step.** See CONTEXT.md for the endpoints.

The `data-grid` decision comes due here: ReUI's grid needs the Base UI
variants of dropdown-menu, select and checkbox, which the project does not
have. Either install them or build the table from plain shadcn parts.

## Step 5 — Moderation ✅ backend done, ⬜ interface open

Kick, ban, unban and access level over RCON, with arguments stripped of
quotes and control characters — a quote in a username would end the argument
early and turn the rest into further commands.

Timed bans are the panel's own doing: Zomboid's `banuser` takes no duration,
so a ban is set permanently and lifted by a scheduler running every minute.
While the panel is down bans stay in place, never the other way round.

Every action is recorded with who did it and why. This also answers something
RCON cannot: no command lists bans, so without a record an operator could not
find someone they banned last week.

The interface for all of this is outstanding.
