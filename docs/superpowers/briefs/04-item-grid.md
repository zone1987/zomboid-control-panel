# Brief 04 — Item catalogue and granting

**Status:** not started
**Depends on:** [01](01-foundation-auth-servers.md), [02](02-lua-bridge-players.md)

## Why this exists

Granting an item today means knowing its exact internal type name and typing
an RCON command. This turns that into picking from a grid.

## In scope

- Full item catalogue exported by the bridge, with translations
- A grid at least eight items wide, with plus/minus steppers
- Choosing a player and placing the items in their inventory
- Search and category filtering

## Available building blocks

- `ScriptManager.getAllItems()` for the catalogue
- Translations either through `Translator` or read directly from
  `media/lua/shared/Translate/<lang>/`
- RCON `additem <user> <fullType> [count]` for granting
- ReUI `number-field` for the steppers (free tier)

## What "done" looks like

An administrator searches "axe", sees the German name and icon, sets the count
to 2, picks a player, and the axes appear in that player's inventory.
