# Brief 01 — Foundation, authentication, server configuration

**Status:** in progress
**Spec:** [../specs/2026-09-04-foundation-auth-servers.md](../specs/2026-09-04-foundation-auth-servers.md)
**Plan:** [../plans/01-foundation-auth-servers.md](../plans/01-foundation-auth-servers.md)

## Why this exists

A Project Zomboid dedicated server is administered today by editing files over
FTP and typing commands into a console. Everything the later sub-projects want
to offer — player lists, chat, item granting, a map — depends on two things
being in place first: somewhere to sign in, and a verified connection to the
game server.

This sub-project builds exactly those two things. It deliberately ends with a
connection that has been proven to work, not with a form that stores
credentials nobody has tested.

## In scope

- Symfony API and React frontend, running as one container plus a database
- Sign-in by email and password, Google, Steam, and passkeys, with TOTP
- One account may register a passkey per device
- Access by invitation only; a first-run wizard creates the first account
- Two interface languages, German and English
- Server records holding FTP/SFTP and RCON credentials, encrypted at rest
- A connection test, a directory browser, and upload of the bridge file
- Docker image published to ghcr.io, deployable in Coolify

## Out of scope

- Anything that reads or writes game state (players, chat, items, map).
  Those need the bridge, which is sub-project 02.
- The Lua mod itself
- Live updates over SSE. The infrastructure is prepared (a second FPM pool),
  but nothing streams yet.

## Preconditions

- A PostgreSQL database
- An SMTP account for invitation and password-reset mail
- For Google sign-in: OAuth client credentials
- For the connection test to be meaningful: access to a real Zomboid server

## What "done" looks like

Someone with no account can open the application, be guided through creating
the first administrator, sign in again with a passkey on a second device,
invite a colleague, register a Zomboid server, press "test connection", and
see either a directory listing or a comprehensible error.

## Open risks

- **RCON client unproven against Zomboid.** It is documented that Zomboid
  speaks Source RCON, and that `xpaw/php-source-query-class` implements Source
  RCON. That those two work together has not been demonstrated. Verify against
  a real server before building on it.
- **SSE exhausts FPM workers.** Each open stream occupies one worker for its
  whole lifetime. Mitigated by a separate pool and a 60-second cap, but the
  limit is real and worth measuring once streaming exists.
