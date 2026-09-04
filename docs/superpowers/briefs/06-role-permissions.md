# Brief 06 — Configurable roles and permissions

**Status:** complete — roles and permissions built; moving the access checks over is the remaining step
**Depends on:** [01](01-foundation-auth-servers.md)

## Why this exists

Roles today are three constants in code: `ROLE_USER`, `ROLE_SERVER_ADMIN`,
`ROLE_ADMIN`. Changing who may do what means editing `security.yaml` and
redeploying. An operator running a community server wants to hand a moderator
the ability to kick players without also handing them the FTP credentials.

## In scope

- A `Role` entity holding a set of permissions, editable in the interface
- A permission catalogue: one entry per capability the application offers
  (view players, kick, ban, grant items, read the log, use RCON, edit server
  configuration, invite users, edit application settings)
- Assigning roles to users
- Symfony voters checking permissions rather than role names
- Built-in roles that cannot be deleted, so an installation cannot lock
  itself out of its own administration

## Out of scope

- Zomboid's own in-game role system (B42 `Roles`/`Role`). Related but
  separate: those govern what a player may do in the game, these govern what
  an operator may do in this panel. Mapping one to the other is a later
  question.

## Migration concern

Every existing access check uses role names — `access_control` entries,
`#[CurrentUser]` guards, `hasRole` calls in the frontend. Switching to
permissions touches all of them. The safe order is: introduce permissions
alongside roles, move checks over one at a time, and only then remove the
role-name checks.

## What "done" looks like

An administrator creates a "Moderator" role, ticks "view players" and "kick",
assigns it to someone, and that person sees the player list with a kick button
but no server configuration at all.
