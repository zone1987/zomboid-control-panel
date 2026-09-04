# What to do next

Written 2026-09-04 before a compaction, so work can continue without the
conversation. Read `CONTEXT.md` first for the state everything rests on.

Order matters below: each item is doable on its own, and earlier ones
unblock later ones.

---

## 1. Correct the teleport dialog — small, do it first

`frontend/src/features/players/teleport-dialog.tsx` tells the operator
that teleporting to coordinates is impossible over RCON. **That is
wrong**, and the wrong explanation is worse than none.

What is true, verified against the live server on 2026-09-04:

- `teleportto x,y,z` — one argument, moves whoever typed it. RCON has
  nobody, so it answers a bare `Error`.
- `teleportto "name" x,y,z` — **two arguments, moves a named player.**
  Answers *"admin teleported to 10778,9770,0 please wait two seconds to
  show the map around you."* Quotes optional, coordinates
  comma-separated without spaces.

To do:

- Add `teleportToCoordinates()` to `PlayerModerator`, alongside the
  existing `teleportToPlayer()`.
- Accept `{ x, y, z }` in the teleport endpoint as an alternative to
  `{ target }`.
- Give the dialog two tabs — to a player, to coordinates — and delete
  the note claiming coordinates are impossible.
- Sanity-check the numbers: Kentucky runs roughly 0–15000 on both axes,
  z is 0–7. Reject anything outside that rather than sending it.
- Landmarks as hints, the way the reference panel does: Muldraugh
  10500,9700 · West Point 11800,6900 · Riverside 6500,5300.

---

## 2. Event console, the RCON half

Brief: `docs/superpowers/briefs/07-event-console.md`, which describes
every control the operator asked for. Everything here works over RCON
today and needs no bridge change.

One page, controls listed down the left, chosen control on the right,
each stating plainly whether it is RCON or bridge. A recent-actions
panel records what the page did — these change the world and it should
be clear afterwards who caused what.

Buildable now, all verified present in the live server's own `help`:

- **Rain and storms** — `startrain`, `stoprain`, `startstorm`,
  `stopweather`, `thunder`, `lightning`
- **Sounds** — `chopper`, `gunshot`, `alarm`
- **Vehicle** — `addvehicle`
- **Teleport** — `teleportplayer`, and `teleportto` per item 1
- **Broadcast** — `servermsg`, already built in the chat page; reuse
  `ChatBroadcaster` rather than writing it twice
- **Zombies** — `createhorde`, `createhorde2`, `removezombies`

The catalogue of 60 commands with their syntax is already parsed and
cached: `App\Server\Rcon\CommandCatalogue`. Use it to decide what to
show rather than hard-coding a list — a modded server may have more.

Grey out anything the connected server does not report, the way the
console page already does.

---

## 3. World map

Behaves like projectzomboidmap.com — pan, zoom, layer switching,
search — with our own controls and look.

Unknowns to settle before building:

- **Where do the tiles come from?** projectzomboidmap.com serves its
  own. Rendering the operator's actual world would mean reading map
  chunks off the server, which is a large piece of work on its own.
  Decide: link out, embed someone else's tiles, or render our own.
- Coordinates are the same space the bridge already reports for
  players, so overlaying them is straightforward once tiles exist.

Then:

- Live player positions from `players.json` (already read every 3s).
- Safehouses from `safehouses.json` (already read, currently unused).
- Right-click menu to teleport a player to the clicked spot — needs
  item 1 and nothing else.

---

## 4. Two-way bridge

Everything else in brief 07 — snow, fog, wind, temperature, the in-game
clock, time speed, electricity and water, placed sounds, safehouse and
faction management — needs the panel to ask the server to do something.

What is proven:

- `getFileInput(path)` returns a `DataInputStream`, `getFileOutput` a
  `DataOutputStream`. The bridge has read and written a binary file end
  to end (a 420-byte PNG, byte-identical).
- **`getFileInput` resolves inside the Lua cache directory only** and
  blocks `..`. So the panel writes its command file to `Lua/<name>` over
  FTP — the same place the bridge already writes to.

What is not proven, and needs deciding:

- The queue shape. It needs acknowledgements so a command cannot run
  twice and the panel can tell whether it worked. A sequence number
  written back into a result file is the obvious approach.
- How often the bridge polls for commands without costing frames.

Read `reference/zomboid-control-panel/pz-mod/PanelBridge/media/lua/server/PanelBridge.lua`
first — it does exactly this, with `getFileReader` and a cursor file.
Study it; do not copy it.

---

## 5. Icon upload screen

`app:icons:extract` needs a path to a local game installation. That
works for the operator who has the game on the same machine as the
panel, and for nobody else.

A hosted server ships no texture packs — GTX does not — so every other
operator needs to upload `UI.pack`, `UI2.pack` and `ApComUI.pack`
through the interface. The parser and store already exist
(`App\Server\Items\Icons`); this is an upload form and a progress
report, nothing more.

---

## 6. Brief 06, configurable roles and permissions

Untouched. `docs/superpowers/briefs/06-role-permissions.md`.

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
