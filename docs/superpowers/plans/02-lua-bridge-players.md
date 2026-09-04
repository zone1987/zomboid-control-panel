# Plan 02 — Lua bridge and player management

**Brief:** [../briefs/02-lua-bridge-players.md](../briefs/02-lua-bridge-players.md)
**Status:** not started; waiting on plan 01 step 7

## Step 1 — Spike: RCON against a real server ⬜

Answer one question before anything else: does
`xpaw/php-source-query-class` 6.0.0 talk to a Zomboid dedicated server? Send
`players`, read the reply. If it does not, the alternatives are a thin custom
Source RCON client or driving `rcon-cli` as a subprocess.

## Step 2 — Lua bridge ⬜

A mod for `media/lua/server` writing one JSON object per line via
`getModFileWriter`, paced by `OnTick` with a counter — `EveryTenMinutes` does
not exist in build 42.20.2.

Read per online player: `getUsername`, `getSteamID`, `getX/getY/getZ`,
`getHealth`, `getBodyDamage()` for infection and wounds, `getPerkLevel`,
`getTraits`, `getHoursSurvived`, `getAccessLevel`.

## Step 3 — Polling worker ⬜

A Messenger worker reading the bridge files over SFTP and writing snapshots to
the database. Uses phpseclib directly for offset reads; Flysystem cannot do
partial reads.

## Step 4 — Player list ⬜

Table with status, position, health and infection. This is where the
`data-grid` question from plan 01 has to be answered.

## Step 5 — Moderation ⬜

Kick, ban, unban over RCON. Access levels through `setaccesslevel`. The B42
capability system stays out until it is established whether `Roles` is writable
from Lua.
