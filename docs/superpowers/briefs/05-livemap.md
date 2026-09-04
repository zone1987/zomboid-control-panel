# Brief 05 — Live map

**Status:** complete — built as part of brief 07, from the game's own map tiles
**Depends on:** [01](01-foundation-auth-servers.md), [02](02-lua-bridge-players.md)

## Why this exists

Coordinates in a table say little. A map shows at a glance where players are
and which areas are claimed.

## In scope

- Map view with player positions
- Safehouses drawn with owner and members
- Following a player

## Constraints established by research

- shadcn's `marker` component is an ARIA text marker, **not** a map component.
  An external library (Leaflet or similar) is required.
- Safehouses come from `SafeHouse.getSafehouseList()`, which exposes
  coordinates, owner, members, and expiry.

## What "done" looks like

An administrator opens the map and sees where everyone is, and whose safehouse
they are standing next to.
