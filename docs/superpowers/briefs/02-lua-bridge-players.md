# Brief 02 — Lua bridge and player management

**Status:** complete
**Depends on:** [01](01-foundation-auth-servers.md)

## Why this exists

Zomboid exposes no API. A server-side Lua mod is the only way to read game
state, and it can only communicate by writing files. This sub-project builds
that mod and the worker that collects what it writes.

## In scope

- A Lua mod for `media/lua/server` writing JSON status files
- A worker polling those files over SFTP and storing them
- Player list: name, Steam ID, position, health, infection, skills, traits,
  time survived
- Kick and ban over RCON
- Reading and setting access levels

## Out of scope

- The B42 capability system (`Roles`/`Role`). Whether those classes are
  writable from Lua could not be established; classic access levels first.
- Chat and log streaming — sub-project 03
- Item granting — sub-project 04

## Constraints established by research

Verified against `projectzomboid.jar` build 42.20.2:

- Lua has no HTTP client and no sockets. File I/O is limited to
  `getFileWriter` / `getModFileWriter`, sandboxed to the Zomboid data folder.
- `EveryTenMinutes` and `EveryHours` do **not** exist in this build. Use
  `OnTick` with a counter.
- RCON replies are free text, not JSON. Status belongs in the file bridge;
  RCON is for actions.

## What "done" looks like

A server administrator uploads the bridge from the interface, waits a few
seconds, and sees who is online, where they are, and how hurt they are — and
can kick someone from that list.
