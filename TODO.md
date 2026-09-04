# What to do next

Written 2026-09-04 before a compaction. **All six items on the original
list are done** — what each turned into is recorded below, because three
of them rested on assumptions that proved wrong.

Read `CONTEXT.md` first for the state everything rests on.

---

## Still open

### 1. The two-way bridge

The last piece of brief 07. Everything marked "bridge" there — snow,
fog, wind, temperature, the in-game clock, time speed, electricity and
water, placed sounds, safehouse and faction management — needs the panel
to ask the server to do something rather than only read what it wrote.

`CONTEXT.md` has a section, "The reference panel's two-way bridge", with
the analysis of how the other panel solves this: the queue shape, the
forward-only resync and its reasoning, tombstones for dropped sequence
numbers, and the mistakes its changelog records. **Start there.** It was
studied, not copied.

One thing to settle first, cheaply: whether `getFileReader` in this
project's bridge can read a file the panel uploads over FTP into the
Lua directory. Everything else depends on that answer.

---

## Done, and what came of it

### Permissions, all the way through

Every endpoint guards on a permission; the navigation shows only what
the user can reach; roles are assignable in the account dialog. A
moderator can kick without being able to ban, and without the FTP and
RCON credentials that come with editing a server — which is what brief
06 asked for.

The firewall had to loosen for it: `^/api/servers` demanded
ROLE_SERVER_ADMIN before any controller was reached, so a narrow role
was turned away at the door. Signing in is now the bar there and on
`/api/users` and `/api/roles`. A test caught that, not a browser.

### Teleport to coordinates — the assumption was wrong

`teleportto` has two argument forms. The single-argument one moves
whoever typed it, which over RCON is nobody, hence the bare `Error`
that led to the wrong conclusion. `teleportto "name" x,y,z` works, and
the command class names its capability `TeleportToCoordinates`.

### Event console — larger than expected

14 actions over RCON. Two argument forms came from the command classes:
`createhorde2` and `removezombies` are varargs taking `-x -y -z
-radius`, so hordes never needed the bridge at all, and `gunshot` and
`alarm` take no argument.

`createhorde2` answers "invalid location" when no player is near the
spot — the chunk has to be loaded. That is a world constraint, not a
syntax error, and the page says so.

### World map — no renderer, no foreign tiles

Rendering the world isometrically costs about 404 GB. pzmap.org's tiles
are barred by their `robots.txt` and blocked by a
`cross-origin-resource-policy` header.

Neither is needed: the game draws its own map and ships the result.
`pyramid.zip`, 6582 tiles, 51 MB, five levels, level 0 being exactly the
world in squares. `app:map:import` takes it from a game or server
installation.

### Texture pack upload

Packs upload in the settings; `apiFetch` passes `FormData` through
untouched, because setting a content type strips the multipart boundary.

### Roles and permissions

17 permissions in five groups, three built-in roles that refuse deletion
and keep their identifiers when renamed.

---

## Standing rules

- Never read or print stored credentials, environment values or
  passwords. Checking whether a value is set is fine.
- The panel will be published for anyone to self-host, and those people
  are server operators, not developers. Anything assuming technical
  knowledge has to be avoided or explained in the interface.
- Everything is live: the panel polls, there are no refresh buttons.
- Verify against the live server where possible, and say plainly what
  was verified and what was not.
