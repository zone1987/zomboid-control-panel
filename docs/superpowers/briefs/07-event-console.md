# Brief 07 — Event console, world map, teleport

**Status:** not started
**Depends on:** [01](01-foundation-auth-servers.md), [02](02-lua-bridge-players.md),
[03](03-live-log-chat-rcon.md)

## Why this exists

An operator wants to run a server, not type commands. Rain on demand, a
horde behind somebody who is getting comfortable, a helicopter at the
wrong moment — these are the things that make a server worth playing on,
and none of them should need a console.

Requested 2026-09-04, with fourteen screenshots of another panel as a
reference for scope. That panel is cloned under `reference/` (ignored by
git) and was studied for what it covers, never copied: this panel keeps
its own structure, wording and design. What the screenshots showed is
written out below, so this brief stands without them.

## In scope

### Event console

One page, a list of controls down the left, the chosen control on the
right. Every action says plainly whether it goes through RCON or needs
the bridge, and a bridge action is disabled with a reason when the
bridge is not answering.

Grouped roughly as:

- **Weather** — rain and storms (RCON: `startrain`, `stoprain`,
  `startstorm`, `stopweather`, `thunder`, `lightning`), then the parts
  that need the bridge: snow, tropical storms, fog, wind, temperature,
  humidity, cloud cover, and the visual overrides.
- **World** — in-game clock (hour, day, month), time multiplier,
  electricity and water. All bridge.
- **Sounds** — the RCON ones (`chopper`, `gunshot`, `alarm`) and,
  through the bridge, a sound placed at a player or at coordinates with
  a radius.
- **Players** — spawn a horde around somebody or clear zombies (bridge),
  spawn a vehicle (`addvehicle`), teleport, broadcast (`servermsg`).
- **Advanced** — safehouse and faction management through the bridge.

A recent-actions panel records what this page did, since these are
world-changing and it should be clear afterwards who caused what.

### World map

Like projectzomboidmap.com in behaviour: pan, zoom, layer switching,
search. Own controls and own look.

On top of the map, live: player positions and safehouses. A right-click
menu that teleports any player to the clicked spot.

### Teleport to coordinates

Already established as impossible over RCON: `teleportto` moves whoever
typed it, and RCON has nobody — the live server answers a bare `Error`.
Doing this needs the bridge to accept a command, which is the piece the
event console needs anyway.

## What the screenshots showed, control by control

A page titled "Event console" with a search box over a list of controls
on the left, the chosen control filling the right. A banner across the
top reports whether the bridge is answering, with a link to set it up,
and how many players are online. Controls needing the bridge carry a
small "bridge" tag in the list and are disabled with an explanation when
it is offline. A "recent actions" panel sits under the list.

**Rain and storms** (RCON, always available): a rain intensity slider
(0-100%), start and stop buttons; a storm duration slider in hours,
start storm, and clear weather.

**Extreme weather** (bridge): blizzard with a duration slider and
trigger; tropical storm likewise; a snow toggle; stop all weather; a
weather front with an intensity slider and a direction dropdown
(stationary among the options); and a helicopter event with trigger and
stop.

**Climate** (bridge): six sliders — fog, wind, temperature in °C, cloud
cover, humidity, precipitation — and one "apply all" button. Beneath
them a live readout of the current rain value with a toggle.

**Visual** (bridge): five sliders — view distance, daylight strength,
night strength, desaturation, ambient light — and "apply all".

**In-game clock** (bridge): an hour slider showing the time, four
shortcuts (dawn, noon, dusk, midnight), a day field, a month dropdown,
and "apply time and date".

**Time speed** (bridge): a multiplier slider with 1x, 5x, 10x, 24x
shortcuts and an apply button. Notes that a restart puts it back to 1x.

**Electricity and water** (bridge): two toggles. Carries a warning that
in B42 multiplayer, sandbox changes are not yet pushed to connected
clients.

**Quick sounds** (RCON): helicopter, gunshot, lightning, thunder, and a
building alarm. Explains which pick a random online player and which
need an admin in game, and that these draw zombies.

**Targeted sounds** (bridge): radius and volume sliders, then two ways to
place it — at a chosen player, or at world coordinates with x and y
fields — each with gunshot, alarm and noise buttons.

**Spawn horde** (bridge): a count slider, spawn near or spawn behind a
random player; then a clear radius slider, remove near a random player,
and remove all loaded zombies.

**Spawn vehicle** (RCON): a vehicle dropdown and a per-player button.
Spawns beside an online player.

**Teleport** (RCON): player-to-player on the left with two dropdowns;
coordinates on the right with x, y and z fields, plus a few named
landmarks as hints (Muldraugh 10500,9700 · West Point 11800,6900 ·
Riverside 6500,5300).

**Broadcast** (RCON): a message field with a character count out of 500,
three preset buttons (event warning, loot hint, horde alarm), and send.

**Bridge tools** (bridge, privileged): an action dropdown with grouped
categories — area (9 actions: list safehouses, add and remove players,
set owner, toggle respawn, list factions, add and remove players, set
tag), vehicles (1), events (3), moderation (4). Chosen action, its input
fields, and an execute button, with the inputs validated before sending.

An event-target switch in the top banner chooses between "all online"
and a named player, and applies to the controls that can be aimed.

## The thing to establish first

**The bridge has to become two-way.** Everything above marked "bridge"
depends on the panel being able to ask the server to do something, not
just read what it wrote.

Verified so far: `getFileInput`/`getGameFilesInput` return a
`DataInputStream`, `getFileOutput` a `DataOutputStream`, and the bridge
has read and written a binary file end to end. Reading a command file
the panel uploads over FTP is therefore plausible — but it is not yet
proven, and the shape matters: a queue with acknowledgements, so a
command is not run twice and the panel can tell whether it worked.

Until that exists, only the RCON actions can be built. They are worth
building on their own.

## What "done" looks like

An operator starts rain, waits a minute, spawns a helicopter over
somebody, and watches on the map as they run. They right-click a
rooftop and drop another player onto it. Nothing in that sequence
required knowing a command name.
