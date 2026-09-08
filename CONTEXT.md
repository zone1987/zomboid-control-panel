# CONTEXT — ZomboidControl

Complete state of the project. Read this first; append after every task.

---

## What this is

A web interface for administering Project Zomboid dedicated servers. Symfony
as a JSON API, React as the frontend, deployed as one Docker container plus
PostgreSQL — locally, on a server, or in Coolify.

**It will be published for anyone to self-host.** The people running it are
server operators, not developers. Anything that assumes technical knowledge —
a DSN, an environment variable, a DNS record — has to be either avoided or
explained in the interface. This has already shaped several decisions and
should keep doing so.

### Standing rules

- Never read or print stored credentials, environment values or
  passwords. Checking whether a value is set is fine.
- Everything is live: the panel polls, there are no refresh buttons.
- Verify against the live server where possible, and say plainly what
  was verified and what was not.
- Screenshots and scratch notes stay out of the repository.

Work is split into sub-projects; see `docs/superpowers/briefs/`.
Sub-projects 01 through 04 are complete. 05 (live map), 06 (roles) and
07 (event console) are not started.

---

## Current direction — 2026-09-06: external map images (implemented)

**Follow-up completed: self-rendering and S3 are now removed entirely.**
`renderer/`, all backend map-render classes and commands, render progress UI,
S3 settings/API/dependencies and local render working directories are deleted.
A migration deletes the five retired S3 settings and queued RenderWorld messages;
it has been applied locally and the remaining S3-setting count is zero.
Item-icon chunk uploads were moved to `App\Server\Items\Icons` and no longer
need renderer textures; all 4,351 existing icons remain. Async mail and scheduler
workers are retained. The old statements below about retaining the rendering
backend/data describe the previous task and are superseded by this cleanup.
Verification: 377 backend tests, 81 frontend tests, frontend build, Symfony
container lint and Docker image build pass. See the final log entry for scope,
paths, removed local data and remaining deployment limitations.


The user explicitly replaced the self-rendering plan with images from
projectzomboidmap.com and supplied a working image-tile prototype at
`~/Downloads/screen-map (1).jsx`. Only the map imagery is
embedded: the panel keeps its own viewer, controls, search and live overlays.
The follow-up request to place attribution directly right of the coordinates
is also implemented and verified. No world render is required or requested now.

The default source is `PROJECT_ZOMBOID_MAP` in
`frontend/src/features/map/map-config.ts`: immutable version
`https://tiles.projectzomboidmap.com/maps/b42.20.2-r1/base`, geometry read and
verified against that version's `map_info.json`. The viewer uses inline DZI
descriptors and OpenSeadragon's HTML drawer, loading ordinary image elements
directly from the provider. This needs neither cross-origin fetch permission,
a backend proxy, object storage nor texture uploads. Earlier statements below
that the external map is ruled out or a full render must start are historical
and superseded by this explicit user decision and the browser verification.

All 47 floors (-17 through 29) are available. Ground imagery is JPG; other
floors are transparent WebP. Above ground, layers 0 through the selected floor
are stacked; underground, layers -17 through the selected basement are stacked,
without the opaque ground. Coordinates use this source's `originY=-139296`,
not the former local render's `-143392`.

Current verification: production build green, 94 frontend tests green, linter
0 errors / 27 warnings. Real external image requests, own controls, floor
switching, search, attribution layout and a right-click dialog with the selected
floor verified in a browser. Marker alignment verified with an intercepted test
player response; no real player was online. Failure display verified with image
requests blocked, then restored. Backend code was not changed or retested.

Remaining limits: a fixed public base map does not depict player construction
or mod maps; imagery depends on the provider remaining available. The pinned
version and metadata must be updated together for a future provider version.
The old rendering backend and its stored data remain; its UI entry points are
removed. No data was deleted and no background render was started or stopped.

## Historical next concrete step — superseded by the external map decision

**Start a full world render.** Nothing is running: the run recorded here
on 2026-09-05 is gone -- no `messenger:consume`, no `main.py`, checked
2026-09-06 -- and the store under `B42` is empty.

Everything it needs is ready: renderer present, texture packs complete
and unpacked, `omit_levels: 2` verified against a real cell. The run
should now cost about four hours drawing and four uploading rather than
the sixty-seven and fifty-nine that made every previous attempt
hopeless. See the log entry for 2026-09-06.

Everything else on the map is built and verified. What remains open is
listed under "Not yet built".

### How the map works now

Nothing needs a game installation anywhere near the panel.

| Piece | Where it comes from |
|---|---|
| Cell geometry | the game server, a cell at a time over FTP |
| Artwork | texture packs, uploaded once through the interface |
| Renderer | `pzmap2dzi`, in the image at `/opt/pzmap2dzi` |
| Finished tiles | the object store, under `B42/base` |

Tiles are served **only** from the store. An empty bucket means an
empty map; there is no local fallback.

### The survey, and why it is the whole saving

A run reads every cell before drawing a tile, and draws only the
cell-floor pairs that hold something. Measured against the user's
server, 2026-09-05:

| | Value |
|---|---|
| Cells the server has | 4,064 of 4,992 (928 do not exist) |
| **Floors the world has** | **47, from -17 to +29** |
| Possible cell-floor passes | 191,008 |
| **Passes that hold anything** | **7,140** |
| Skipped as empty | 183,871 — **96 %** |

The survey costs 50 minutes of FTP, so its answer is kept at
`map/occupancy.json` (2.5 MB). A second run starts drawing at once --
verified: the restart skipped straight to `uploading`.

**Floors are not a fixed list.** `FLOOR_ORDER = [0, 1, -1, 2, 3]` is
only the starting assumption for the progress display. The floors
actually drawn come from `floorsIn()`, which reads what the survey
found -- B42 allows -32 to 32, and Louisville really does reach 29
while a bunker sits at -17. A fixed range surveyed them and then never
drew them, which is what the 5-floor run before this one did.

The order is outwards from the ground: 0, 1, -1, then by distance, so
the bunker at -17 comes before the 29th storey.

**The check is per cell and floor, not per tile.** A block is 8x8
squares against a tile's 256, so block occupancy is finer than a tile
-- but `render_cell_range` addresses cells, so a drawn cell-floor may
still contain empty tiles where a tower occupies one corner. Going
below cell level would mean rewriting the renderer's task builder.

### What a run now costs

Projected from a real render, 441 tiles per cell-floor pass:

| | Without the survey | With it |
|---|---|---|
| Tiles | 8.96 M | **3.15 M** |
| Runtime at ~13/s | 173 h | **~71 h** |
| Storage | 1.97 TB | **0.69 TB** |

The 1 TB bucket fits the second and not the first. Note that 330 GB,
recorded here earlier, was wrong: measured at 236 KB a tile
(16.5 GB / 71,833 objects).

**Those figures are for `omit_levels: 0`, which is no longer what runs.**
The survey is one of two levers and was the only one in use; the second
is the pyramid depth, and it had never reached the renderer. Both
savings multiply: the survey skips 96 % of the passes, and dropping two
levels quarters what the rest produce twice over.

**Measured from the run of 2026-09-06, not projected:**

| | Value |
|---|---|
| Survey | 40 min, 4,992 cells |
| Passes skipped as empty | 183,917 -- 96 % |
| Passes to draw | 7,140 |
| Floors the world has | **47**, -17 to +29 |
| Tiles the run estimates | **~590,000** |
| Storage at 241 KB a tile | **~135 GB** |
| Runtime | **~12 h** |

A projection of 197 k tiles and 45 GB was recorded here first and was
wrong by a factor of three. It assumed the five floors of
`FLOOR_ORDER`, which is only the starting assumption for the progress
display; the survey finds 47, because Louisville reaches 29 and a
bunker sits at -17. The run's own estimate comes from the tile rate of
the cells already drawn and is the one to trust.

**All 47 floors are rendered deliberately.** `layer_range` would cut
this sharply, and it is the wrong saving: buildings really do reach 29
and bunkers -17, and a panel blind at those heights is blind exactly
where players build. If 135 GB ever presses, `omit_levels: 3` gives
~34 GB and ~3 h and costs one zoom step rather than a floor.

### The defect that ruined two runs

pzmap2dzi sizes the DZI pyramid to the cells it is given, so every
batch wrote a different `w`, `h`, `x0` and `y0`. Tiles from batch 1 and
batch 500 belonged to different coordinate systems, and zooming showed
forest where none stood.

`dzi_cell_range` pins the image to the whole world regardless of what a
batch draws. `TileRenderer::writeConfiguration()` writes it, along with
removing the `dzi_cell_range[default]` line, which otherwise wins.

Verified 2026-09-05: `w=2314688` in the render's own `map_info.json`,
not a cropped extent.

**Two traps around this.** The renderer refuses to write into a
directory whose existing `map_info.json` disagrees -- correct
behaviour, but it means a geometry change needs the output directory
genuinely empty, not merely cleared of tiles. And with leftovers
present it reports "Affected tiles: 0" and draws nothing at all.

**Careful with `rm -rf var/map/iso`:** the 502 MB of unpacked textures
live in the same tree, under `var/map/iso/texture`. Deleting the render
takes them with it, and every tile then comes out `.empty`. Restore
with `app:map:render <server> --unpack`.

### Upload concurrency: the cap was not the constraint

`ObjectStorageFactory` handed AsyncAws no HTTP client, so it built one
with Symfony's default of six connections per host. That looked like
the reason `IN_FLIGHT = 32` never behaved like 32.

It was not. Measured against Hetzner, 2026-09-05:

| Connections | Objects/s |
|---|---|
| 4 | 14.1, 13.8 |
| 8 | 15.4, 14.4 |
| 16 | 14.8, 15.1 |
| 32 | 9.2 |
| 64 | 9.1 |

The line saturates at about 3.5 MB/s well before the connection count
matters, and past 16 the transfers compete and it gets *worse*. Both
the client and `IN_FLIGHT` are now 16; 32 was in the range that
measured slower.

What the change did buy: the `S3Client` is kept between calls, so a
batch no longer pays for a fresh set of TLS handshakes.

### Batch deletes, measured

`clear()` deleted one object per request. `DeleteObjects` takes a
thousand keys: **78,701 objects in 75 seconds, 1,045 a second**,
against roughly 19 before. `app:storage:clear <prefix>` uses it.

### Measurements that decided the design

All against the user's Hetzner bucket, not estimated.

| Approach | Tiles/s | MB/s | Whole world |
|---|---|---|---|
| **Individual objects, 32 in flight** | **19** | **3.7** | **~28 h** |
| Archives of 200 tiles | 9.7 | 0.32 | ~55 h |

Bundling was built, measured, and removed. The reasoning was that the
store answers about nineteen requests a second whatever the
concurrency, so fewer larger requests should win. It does not: one 6 MB
archive takes 18.6 seconds, while 32 small transfers in parallel use
the line far better. The machinery worked -- packing, byte-range reads,
an index -- and a tile came back byte-identical through it. See commit
`4deddd4` if another provider behaves differently.

The store serves byte ranges (verified: 1000 bytes from offset 1000
came back exactly), which is what made the archive idea worth testing.

### Storage, from a real render

| Depth | Whole world |
|---|---|
| Full resolution | ~330 GB |
| Without the deepest level | ~72 GB |
| Two levels short | ~16 GB |

The deepest level is around three quarters of the total; each level
quarters the one below. Full resolution was chosen deliberately.

### The store refuses intermittently

A freshly created Hetzner key pair answered `AccessDenied` to roughly
4 requests in 10 for its first hour, then stopped. Not the key, the
clock, the request rate or the object name -- all ruled out by
measurement. Every write is retried twelve times with rising pauses,
which is what carries a run through it.

`app:storage:test --diagnose` reports how a store refuses, if it
returns.

### The retry rounds fire on every batch, not on the odd one

Measured on the run of 2026-09-06, over two 15-minute windows:
**100 % of batches exhaust all four retry rounds.** Five of five, then
four of four. It is not the opening hour settling down -- it is the
steady state.

`RETRY_ROUNDS = 4` with `sleep(2 << $round)` is 2+4+8+16 = **30 seconds
a batch**, and a batch takes about 134 seconds. So roughly **22 % of the
drawing time is spent waiting**, about 3 of 13 hours on a full run.

Two things keep this from being urgent:

- **Nothing is lost.** `tilesFailed` sat at 0 throughout; the mechanism
  does what it was built for, and the store here really does refuse a
  fraction of requests with a working key.
- **The run got faster while it happened** -- 12.3, then 16.1, then
  18.1 tiles/s against the 13 recorded earlier. Waiting a fifth of the
  time still beat the documented rate.

Worth changing before the next full render, not during one: four rounds
of rising pauses assume a rare event. When it happens every time, the
retry belongs at the end of a pass rather than inside every batch, or
the pauses belong shorter. Interrupting a running render to save three
hours is the worse trade.

**A caution about diagnosing this.** Comparing the tiles held on disk
against the store mid-run shows them all present and suggests `verify()`
is wrong. It is not: that comparison catches the moment between an
upload and its confirmation. Checking single files a moment later shows
some genuinely absent -- freshly drawn, not yet sent. A fix for
`isUnchanged()` was written on this false reading and reverted; the test
written for it stayed green with and without the change, which was the
tell.

### Ruled out, with reasons

**projectzomboidmap.com's tiles.** Asked twice, refused twice. CORS is
allowlisted to their own domain, there is no imprint or contact, the
operator holds no rights from The Indie Stone to grant, and the tile
path carries a version stamp that would break every shipped panel at
once. Their own site documents three moves already.

Worth saying plainly: their map is build 42.20.2 as shipped. This one
is *this server* -- what players built and demolished, redrawn on
demand.

**The other panel's approach.** `fpsacha/zomboid-control-panel` proxies
`tiles.pzmap.org` and caches to disk, with no word anywhere about
licensing, and reaches it with curl because Node is blocked by the bot
challenge. Its disk-cache idea is worth borrowing; its source is not.

**The game's own top-down map.** Removed in `9a9f23b`. One pixel per
world square is a blur at any useful zoom -- nothing on it can be made
out. It was only ever the thing that worked while the isometric render
did not.

**PMTiles and MBTiles, 2026-09-06.** Proposed to cut the upload from
days, on the reasoning that request latency dominates. Measured here it
does not: 3.15 M tiles at 15 objects/s is 58.3 h and 724 GB at 3.5 MB/s
is 58.8 h -- the same figure. At the measured 19 objects/s the run is
already faster than the byte floor allows, so it is bandwidth-bound.
Packaging changes the request count and leaves the bytes untouched.

This repeats what commit `4deddd4` already measured with archives of 200
tiles: 9.7 tiles/s against 19 for individual objects.

Three further obstacles, checked against the specifications:

- PMTiles addresses square grids -- the v3 tile id is a Hilbert curve
  over 2^z x 2^z, and the reference decoder throws on `x >= 1 << z`
  (`pmtiles` 4.5.0, `js/src/index.ts:77-95`). A 2261 x 998 level fits
  only sparsely inside a square address space. Max zoom 26; 22 would fit.
- OpenSeadragon has no PMTiles tile source, shipped or community.
  `getTileUrl` returns a URL; a PMTiles tile is a byte range behind an
  async directory lookup.
- MBTiles is roughly ten times past its practical envelope at 690 GB and
  cannot be range-served from object storage at all.

Two ideas from the same review are worth keeping, because they attack
bytes rather than packaging: **rclone** against the existing per-tile
layout, and **content-hash deduplication** -- forest and water tiles
repeat, and uploading each distinct blob once would cut the payload.
Neither is built.

**Leaflet, again.** Proposed alongside PMTiles. The map moved from
Leaflet to OpenSeadragon in `8445bab` one day earlier, because
pzmap2dzi writes DZI, which OpenSeadragon reads natively and Leaflet
needs a custom `L.TileLayer` for: TileSize 1024, ~22 levels, per-floor
`Format`, cropped edge tiles on a 2.26:1 image. Deep-link zoom semantics
would also change -- OSD measures in image widths, Leaflet in log2
levels. None of it touches the render duration.

---

## Current state — 2026-09-04

### Verified working, against a real server where possible

| Area | Evidence |
|---|---|
| Symfony 7.4.18 on PHP 8.4.24 | running in ddev |
| PostgreSQL 17.11 | migrated |
| Credential encryption | tests prove no plaintext reaches the database |
| Setup wizard | creates the first admin, then refuses |
| Password login | session survives reload |
| Passkeys | registration, login, management; virtual authenticator |
| TOTP two-factor | setup, login, recovery codes; real codes in a browser |
| Google sign-in | **tested by the user against real credentials** |
| Steam sign-in | **tested by the user; SteamID64 stored** |
| Invitations, password reset | endpoints and screens |
| FTP/SFTP to the game server | **works against the user's GTX Gaming server** |
| RCON to the game server | **works; "Players connected (1): -admin"** |
| Lua bridge | **uploaded, loaded, writing correct player data** |
| Player list | **verified live: position, health, access level, skills** |
| Moderation | kick, ban, unban, access level; timed bans via scheduler |
| Mail sending | **user received a test message** |
| Mail deliverability check | SPF, DKIM, DMARC with the exact record to publish |
| Account administration | roles, activation, deletion; last admin protected |
| Server log | **tailed live over FTP, all seven kinds found automatically** |
| Chat | **sent from the panel, read back out of the log within 3s** |
| RCON console | **60 commands read from the live server, syntax checked** |
| Item catalogue | **5,092 items, 5,138 German names, from the live server** |
| Item icons | **4,343 of 5,092 extracted from the texture packs** |
| Giving items | **an axe and 150 nails delivered into a player's inventory** |
| World state | **in-game date, time, weather read from the bridge** |
| Teleport to coordinates | **`teleportto "name" x,y,z` answered live** |
| Event console | **rain started and stopped on the live server from the page** |
| Zombie removal | **`removezombies` with -x/-y/-radius answered "Zombies removed."** |
| Texture pack upload | **a real pack uploaded through the browser, icons extracted** |
| Roles and permissions | 17 permissions in five groups, three built-in roles |
| Map coordinates | **searching 11800,6900 lands on 11800,6900** |
| Two-way bridge | **a command answered in 1.5s; setting the hour put it in the world** |
| Vehicles and factions | bridge 0.10.0 writes them; empty until players load chunks |
| Live world reading | `readSurroundings` built, not yet exercised with a player online |
| Cell data from the server | **world_X_Y.lotpack byte-identical to a local copy; ~1 MB, ~0.7 s each** |
| OpenSeadragon map | **deep links, floor control, layer toggles, right-click teleport** |
| Isometric render | **one cell, 559 tiles, 3 s; interiors, furniture, trees** |
| Texture pack upload for the map | **414 MB in 8 MB pieces, SHA-256 identical to the originals** |
| Object storage | **write, read back, delete against the user's Hetzner bucket** |
| Tiles served from the store | **verified with nothing local: 12331 objects, four floors, the region drew** |
| World geometry | **w=2314688 pinned, not a moving crop** |
| Production image | **builds, starts healthy, serves the whole panel** |

380 backend tests, 78 frontend tests. Both suites green.

### Not yet built

- **A finished world render.** One is running; nothing has completed
  end to end yet. Until it does, the map shows only what has been drawn.
- **Death locations as a map layer** — the fourth layer a design draft
  asked for. The log has the data; nothing reads it yet.
- **Automatic re-render after a server update** — the bridge stamps a
  session id, so a restart is detectable, but nothing acts on it. A
  second run is cheap: unchanged cells are skipped before they are
  drawn, and unchanged tiles are never re-sent.
- **Zombies as a map layer** — deliberately absent: `getZombieList()`
  is in the API index but the game never calls it from Lua anywhere, so
  a layer built on it might silently stay empty. Test it against a live
  server before building it.
- **`stopRain` bridge handler is incomplete** — it ends the weather
  period but does not clear the admin-forced rain override, so rain
  resumes.

### Known open risks

1. **A custom role does not restrict anything yet.** Permissions exist and
   are editable, but every controller still guards with a legacy role
   name. The voter honours both, so nothing is broken; the restriction
   simply has no effect until the checks move over.
2. **The map needs texture packs before it can be drawn.** Five files,
   414 MB, from a game installation — a dedicated server does not have
   them and Steam does not ship them for app 380870. Checked against
   the user's own server: `media/texturepacks` absent, not one `.pack`
   among its 85 entries. The interface says so and explains where to
   find them on Windows, Linux and macOS.
3. **A killed worker blocks its own message.** Cancelling kills the
   worker outright, which leaves the row marked as delivered. The stop
   endpoint deletes it, and `redeliver_timeout: 120` covers a worker
   that dies some other way — but a message stuck this way is silent:
   the interface sits at "queued" and nothing says why. It happened
   three times before the endpoint was made to clean up.
4. **Mail lands in spam** without DKIM. Not a defect — see the DNS section
   — but new operators will hit it. The deliverability check now names the
   exact record.
5. **Two workers can run at once.** `ddev restart` starts one and a
   manual start adds another; both then write the progress file, and
   the older one's stale counters overwrite the newer one's. Check with
   `ps -eo pid,etime,cmd | grep messenger:consume` before trusting what
   the interface reports.

---

## The user's server, for testing

- Server name in the panel: "GTX Gaming", host `176.57.168.46`
- FTP/SFTP and RCON both configured and verified
- Bridge installed and running
- Base path contains the Zomboid data folders directly: `Logs`, `Saves`,
  `Server`, `media`, `db` — **not** under a `~/Zomboid` subdirectory
- Bridge status file: `Lua/ZomboidControl/status.json` relative to the base
  path
- Test account: `<the maintainer's Google address>`, password
  `ein-ausreichend-langes-passwort`, roles ROLE_ADMIN + ROLE_SERVER_ADMIN,
  with Google, Steam and one passkey linked

**Credentials rule the user set:** never read or print stored credentials,
environment values or passwords. Checking whether a value is set is fine;
printing it is not.

---

## Facts established by research

Checked against primary sources. Do not re-litigate without new evidence.

### Symfony 7.4 is forced

`scheb/2fa-bundle` v8.6.1 requires `symfony ^7.4` on `php ~8.4.0`.
`DoctrineEncryptBundle` 7.0.x requires the same independently. Native SSE
classes arrived in 7.3.

### Sessions, not JWT

`scheb/2fa` documents that the firewall must be stateful. WebAuthn stores its
challenge in the session. `EventSource` cannot send an `Authorization` header.
Same-origin removes the CORS argument for tokens.

### `SameSite=Lax`, not `Strict`

`Strict` withholds the cookie on the Steam OpenID cross-site return.

### Zomboid, verified against projectzomboid.jar build 42.20.2 with javap

Confirmed present and used by the bridge:

- `getFileWriter(filename, createIfNull, append)` → writes to the Zomboid data
  folder; **creates missing subdirectories on its own** (verified live)
- `getOnlinePlayers()` → `ArrayList<IsoPlayer>`, iterate with `size()`/`get(i)`
- `getUsername()`, `getSteamID()` (**a `long`, not a string**), `getX/Y/Z()`,
  `getHealth()`, `getHoursSurvived()`, `getAccessLevel()`, `getBodyDamage()`
- `BodyDamage`: **`isInfected()` lowercase** for the flag,
  `getApparentInfectionLevel()` for the value. `getInfectionLevel()` does not
  exist, and `IsInfected()` capitalised is a different method.
- `getPerkList()` → `PerkInfo` with `.perk:getId()` and `:getLevel()`
- `getCharacterTraits():getKnownTraits()` → list with `:getName()`
- `getTimestamp()` wraps `System.currentTimeMillis() / 1000` — real wall-clock
  seconds. `getTimestampMs()` and `getTimeInMillis()` are the same thing in
  milliseconds. **`getGametimeTimestamp()` is in-game time despite the name.**
- `Events.OnTick` fires on a dedicated server, but **not at a fixed rate**.
  `GameServer` compiles in `FPS = 10` and holds it with a 100 ms
  `UpdateLimit`, which is a ceiling: under load a cycle takes longer and the
  event fires less often. Measured live: 10/s on an empty server, 5/s with one
  player. There is no server option for it — `ServerFramerate`, `UPS` and
  `MaxFramerate` do not exist in `ServerOptions`.
  **Never count ticks for a real-time interval; compare `getTimestamp()`.**

Hard limits:

- **No server-side chat event.** `OnAddMessage` is client-only, and
  `ChatServer` (package `zombie.network.chat`, not `zombie.chat`) never calls
  `LuaEventManager.triggerEvent` at all. Chat is read from the `_chat.txt` log
  and sent via RCON `servermsg`.
- **Player messages are logged, but as a Java `toString()`.** `ChatServer`
  writes `Got message:ChatMessage{chat=<tabKey>, author='<name>',
  text='<text>'}`, then the same message again as `Message ... sent to chat
  (id = N) members` — so every message appears twice and the duplicate has to
  be dropped. Nothing is escaped, so the text can contain `'` and `}`; the
  author runs to the first `', text='` and the text to the last `'}`.
  `chat=` holds a translation key, not "Say" or "Shout", so the chat type is
  only recoverable through the numeric id and the `"<Type> chat has id = N"`
  lines written at startup.
- **The Discord bridge has a third shape**: `Got message '<msg>' by author
  '<author>' from discord`.
- **Chat logging cannot be switched off.** `ServerOptions` has `GlobalChat`,
  `ChatStreams`, `ChatMessageCharacterLimit` and others, but no `ChatLogs`.
- **`servermsg` is the only chat command over RCON.** Nothing sends to one
  player, and nothing sends under a chosen name — `ChatServer` can do both in
  Java, but neither is reachable from RCON.
- **Log lines are stamped `dd-MM-yy HH:mm:ss.SSS`** by `ZLogger`, with the
  level in a second bracket only when the caller passed one.
- **No HTTP, no sockets in Lua.** The file bridge is mandatory.
- **Lua CAN read and write binary**, contrary to what this file said for
  a while. `getGameFilesInput(path)` returns a `DataInputStream`,
  `getFileInput(path)` likewise, and `getFileOutput(path)` a
  `DataOutputStream`. `getFileWriter` is merely the text one.
  **Paths for `getGameFilesInput` need the `media/` prefix** —
  `media/inventory/BerettaClip.png` opens, `inventory/BerettaClip.png`
  does not. Proven end to end on 2026-09-04: the bridge read that PNG
  byte by byte and wrote it back through `getFileOutput`; the panel
  fetched 420 bytes with a valid PNG signature, a readable 34×32 image.
- **The `Every*` events run on in-game time, not real time.** `EveryOneMinute`
  fires when a game minute passes, which depends on the sandbox day length and
  stops entirely while the server is paused. `EveryTenMinutes`, `EveryHours`
  and `EveryDays` do exist and do fire server-side (`GameTime.update()`), but
  none of them is usable as a real-time timer.
- **No server-side join or leave event.** `OnPlayerConnect` and
  `OnPlayerDisconnect` do not exist; `OnConnected` and `OnDisconnect` are
  client-only. The bridge compares the roster on every tick instead and writes
  the moment it differs.
- **`getMaxPlayers()` needs an argument on a server.** Its documented
  zero-argument form is client-side, and calling it bare throws "Not enough
  arguments", which took the whole file down with it. Read `getServerOptions()`
  instead.
- **RCON replies are free text**, not JSON.
- **No RCON command lists bans** — only `banuser`/`unbanuser`. The panel keeps
  its own record, which is why `moderation_action` exists.
- **`banuser` takes no duration.** Timed bans are the panel's own doing: ban
  permanently, lift later by scheduler.
- **`mod.info` is not needed** when the Lua file goes straight into the
  server's own `media/lua/server`. It is only required for mods under `mods/`.

### Interface libraries

- ReUI's catalogue is readable without a licence; its **77 UI components
  install freely**, its **1638 page blocks return 401**.
- shadcn's `marker` is an ARIA text marker, **not** a map component.

---

## Findings from implementation

Things that cost time and would cost it again.

**Doctrine types cannot be constructor-injected.** The cipher reaches
`EncryptedStringType` through a Doctrine middleware; kernel and console events
do not fire for migrations or tests.

**WebAuthn has two handler contracts.** The firewall wants Symfony's
`AuthenticationSuccessHandlerInterface`; registration wants the bundle's own
`SuccessHandler`.

**`CanSaveCredentialRecord` is required**, or the bundle validates a ceremony
and stores nothing, answering 501.

**Lazily loaded collections read zero.** The last-passkey rule counted through
a collection and would have let an account lock itself out.

**Logout redirected.** A `LogoutEvent` listener answers JSON instead.

**`/app` without a trailing slash 404s** on both Vite and Apache. Both OAuth
authenticators redirect to `/app/`, and a dev-server middleware redirects the
bare path.

**Vite must build into `backend/public/app`.** It built into `frontend/dist`,
which Apache never serves, so the `.htaccess` SPA fallback looped until Apache
gave up with a 500. The production path had never worked.

**RCON needed a hard deadline.** The library's timeout bounds individual
reads, not the exchange; with `max_execution_time=0` a silent port held a
worker indefinitely. Now: `default_socket_timeout` during the call, a
twelve-second deadline, `set_time_limit(20)` as backstop. A silent port raises
`InvalidPacketException`, which is classified as unreachable, not as a failed
command.

**The OAuth client bundle cannot see runtime credentials.** Google's client is
built by a factory at call time. An earlier attempt used a kernel.request
listener that was never registered and silently did nothing —
`debug:event-dispatcher` showed it absent.

**scheb needs every token type listed.** `WebauthnToken` had to be added to
`security_tokens`, or a passkey login skips the second factor.

**The TOTP provider is off until configured.** Without a `totp:` section the
`TotpAuthenticatorInterface` service does not exist and autowiring fails.

**Flex skipped several recipes** (`symfony/apache-pack`, the WebAuthn bundle,
the OAuth bundle). `.htaccess` and `bundles.php` entries were written by hand.

**`node_modules` are platform-bound.** Installed for Linux in the container;
running npm on the host fails with missing native bindings.

---

## The reference panel's two-way bridge

The panel under `reference/zomboid-control-panel` (gitignored, MIT, read
but never copied) solves the two-way problem this project still has open.
Its `PanelBridge.lua` is 9132 lines and its changelog is a catalogue of
mistakes worth not repeating. Analysed 2026-09-04; the essentials:

**A write restriction that may or may not apply here.** Its changelog
says Build 42 buildid 24449161 (2026-07-29) restricted `getFileWriter`
to a small set of extensions and refuses `.json` outright, returning
nil. It works around this by appending `.txt` to every path it writes
(`status.json.txt`), leaving the content JSON.

**This project's bridge writes plain `.json` and it works.** Verified
2026-09-04 against the live GTX server: `players.json` carried a
timestamp from moments earlier while the server was running. So either
the restriction was lifted, or it never applied to that server's build.
Worth remembering if writes ever start failing silently, but not worth
pre-emptively changing.

`getFileReader` is unaffected either way. The panel writes the files it
owns (`inbox/cmd-*.json`) under their plain names, because it writes
from outside the game's sandbox.

**Directories cannot be created from Lua in Build 42.** The panel has to
create `inbox/` and `outbox/` itself.

**The queue.** Two counters, each owned by exactly one side and read by
the other: the bridge keeps `lastCommandSeq` in its own state file, the
panel keeps `nextCommandSeq` in its own. Command files are named
`cmd-<10-digit-seq>.json` and written atomically (temp file plus
rename), because a half-written file would otherwise be read.

Three separate guards stop a command running twice: the sequence
cursor, setting `lastCommandSeq` *before* the handler runs (a crash
therefore loses a command rather than repeating it), and a set of
already-seen command ids.

**Resync is forward-only.** If the two counters drift — the documented
case is a redeployed panel whose state file was reset while the mod kept
counting — every command waits forever on a file that will never
appear. The bridge reads the panel's declared position after 10 seconds
stuck and moves *forward* to it, never backward. The reasoning is the
part worth keeping: a stale read of the other side's counter can only
ever be too low, never invented too high, so a forward-only jump cannot
replay a command that already ran.

**Every gap in a sequential numbering must be filled actively.** When
its result buffer overflows it writes a tombstone result for the
dropped number rather than skipping it, because the reader is strictly
sequential and would otherwise wait forever.

**A heartbeat that does not travel the command path proves nothing.**
Its status file is written outside the queue, so it reported healthy
while every command was stuck. It now carries the queue counters.

**Not thrown is not succeeded.** Most of its changelog is one mistake in
different clothes: an action reporting success while doing nothing.
`triggerCustomWeatherStage` returns a real boolean that was discarded;
a fallback chain put a method that never throws first, so the real one
was never reached.

**Whether a value can be read back has to be settled per method.**
`ClimateFloat.getFinalValue()` is not refreshed until the next game
tick, so reading it straight after writing proves nothing — but
`getAdminValue()` is a plain field read and does. `permanentlyRemove()`
removes from the live collection synchronously, so re-checking works
there. Both look identical in Lua and behave oppositely.

**Java methods are not always Lua fields.** Guarding with
`if obj.method then` reports false for methods that are callable. This
made a live server with 21 vehicles report zero.

---

## Mail deliverability

The first real test message reached the user's inbox but landed in spam. The
cause is DNS on the sending domain, not the application:

- SPF is `v=spf1 a mx ptr ?all` — does not cover Hetzner's relay, and `ptr` is
  deprecated. Should be `v=spf1 include:_spf.hetzner.com mx ~all`.
- DMARC has `pct=50`, checking only half the mail.
- **DKIM is absent entirely** — the heaviest factor. Enabled per domain in
  Hetzner's konsoleH.

On the application side: mail now carries an HTML part alongside text, names
the application, and says why it arrived. A checker endpoint reads the three
records and reports what is missing.

**Hetzner shared hosting note:** the outgoing server is `mail.your-server.de`
for every mailbox regardless of domain. Entering the own domain fails with a
certificate mismatch, since the certificate covers `*.your-server.de`.

---

## Log

### 2026-09-04 — Foundation
Symfony 7.4 skeleton, auth packages at researched versions, PostgreSQL,
Apache in ddev, `CredentialCipher` with 11 tests. Commit `722b8d8`.

### 2026-09-04 — Data model
Seven entities and tables. Cipher injection moved to a Doctrine middleware.
Integration tests prove no plaintext reaches the database. Commit `d36217b`.

### 2026-09-04 — Frontend and container
Vite 8, React 19, Tailwind v4, shadcn plus free ReUI components. Multi-stage
Dockerfile, compose file, CI workflow. `ddev dev` and `ddev setup`.
Commit `7506010`.

### 2026-09-04 — Setup wizard and password login
Wizard that locks itself, enumeration-resistant login, route guards.
Commit `769020a`.

### 2026-09-04 — Passkeys
Registration, login, device management. Verified with a virtual authenticator.
Commit `10a4c59`.

### 2026-09-04 — Documentation
CLAUDE.md, CONTEXT.md, briefs, spec, plans. Commit `df2adc1`.

### 2026-09-04 — TOTP two-factor
Session-held pending secret, ten hashed recovery codes, password confirmation
for disabling. Commit `18063ff`.

### 2026-09-04 — Google and Steam
OAuth2 for Google, OpenID 2.0 with an own authenticator for Steam. A forged
Steam callback is rejected. Commit `1759dbd`.

### 2026-09-04 — Settings page
Steam, Google and SMTP credentials editable in the interface, encrypted, with
how-to instructions per field. Commit `b8fb562`.

### 2026-09-04 — Google credential fix
Stored credentials never reached the authorization request; replaced the
listener with a factory. Commit `24a3a42`.

### 2026-09-04 — Sign-out and production path
Three faults: sign-out cleared the session query instead of refetching, the
.htaccess looped into a 500, and Vite built where Apache never looks.
Commit `a5b22f8`.

### 2026-09-04 — Dark theme
Dark by default, applied before mount. The apparent theme reset after Google
sign-in was really the bare `/app` 404. Commit `7947c23`.

### 2026-09-04 — Server configuration
RCON spike resolved the open risk; seven integration tests against a local
Source RCON server. Flysystem for FTP/SFTP with a directory browser and path
traversal refused. Commit `d25c7d2`.

### 2026-09-04 — Sidebar and server UI
Collapsible icon sidebar with server switcher, server list and detail pages,
directory browser. `app:user:merge` added. Commit `3cf6e10`.

### 2026-09-04 — Connection tests on save
Test buttons enable as soon as the fields allow it and explain themselves
otherwise; saving verifies but never discards input. Commit `15fbcad`.

### 2026-09-04 — Repository hygiene
Twenty Playwright artefacts removed from version control. Commit `6f999a5`.

### 2026-09-04 — RCON deadline
A silent port held a worker indefinitely; three layers of timeout added.
Commit `2fd4e17`.

### 2026-09-04 — Card and badges
Whole server card clickable, verified connections in green. Commit `9e1b387`.

### 2026-09-04 — Invitations and password reset
Hashed tokens, enumeration-resistant reset, best-effort delivery.
Commit `11eba23`.

### 2026-09-04 — Lua bridge
Written and corrected against the jar: `getApparentInfectionLevel`, lowercase
`isInfected`, SteamID as a long. Commit `c6b2c47`.

### 2026-09-04 — Invitation and reset screens
Token screens reachable while signed in; mod.info dropped; bridge switched to
`getFileWriter`. Commit `ffe0c3d`.

### 2026-09-04 — Bridge confirmed live
File read endpoint added. **The bridge runs on the user's server and returns
correct player data.** Commit `99db169`.

### 2026-09-04 — Player list and moderation backend
Player snapshots, kick/ban/unban/access level, timed bans by scheduler,
moderation record. Mail configuration moved from DSN to individual fields with
thirteen provider presets. Commit `53d4b8b`.

### 2026-09-04 — Field alignment
Paired fields aligned; Hetzner and five more providers added. Commit `9945fe7`.

### 2026-09-04 — Mail deliverability and password visibility
HTML mail templates, an SPF/DKIM/DMARC checker, and a reveal toggle on every
password field. Commit `4e24f67`.

### 2026-09-04 — Teleport to coordinates
`teleportto` has two argument forms and only the single-argument one fails
over RCON, because it moves the caller and RCON has none. `PlayerModerator`
gained `teleportToCoordinates()`, the endpoint takes `{x, y, z}` as an
alternative to `{target}`, and the dialog has two tabs plus six landmarks.
The note claiming coordinates were impossible is gone. Commit `52eba8e`.

### 2026-09-04 — Event console
`App\Server\Events`: a catalogue of 14 actions declared once with their
fields and handed to the interface, so a control and its command cannot
drift. Each is marked with the commands it needs and greyed out when the
server's own `help` does not report them.

Two argument forms came from the command classes rather than guesswork:
`createhorde2` and `removezombies` are varargs taking `-x -y -z -radius`,
which is why hordes are RCON here rather than waiting for the bridge, and
`gunshot`/`alarm` take no argument at all.

Verified live: rain started and stopped from the page, and the action was
recorded with time and author. `removezombies` answered "Zombies removed."
`createhorde2` answers "invalid location" without a player nearby — the
flags parse, the chunk has to be loaded — and the page says so.
Commits `48da285`, `f8879ce`.

### 2026-09-04 — Texture pack upload
A rented server ships no texture packs, so `app:icons:extract` served only
operators running the panel beside the game. Packs can now be uploaded in
the settings. `apiFetch` had to learn to pass `FormData` through untouched:
setting a content type of its own strips the multipart boundary.
Verified with a real pack through the browser. Commit `a1b3dae`.

### 2026-09-04 — Roles carrying permissions
Brief 06. A `Role` entity with 17 permissions in five groups, editable in
the interface, assignable to users. Three built-in roles refuse deletion so
an installation cannot lock itself out, and keep their identifiers when
renamed because code refers to them by name.

Permissions and the legacy role names are both in force, as the brief asks:
`PermissionVoter` grants from an assigned role *or* from a legacy name, so
checks can move over one at a time and nothing breaks in the meantime.
Commit `4f1970d`.

### 2026-09-04 — World map
The research offered two dead ends — rendering the world isometrically costs
about 404 GB, and pzmap.org's tiles are barred by their `robots.txt` and
blocked by a `cross-origin-resource-policy` header.

Neither is needed: **the game ships its own map**. `pyramid.zip` holds 6582
tiles of 256 pixels in five levels, 51 MB, and level 0 is 19968x16128 —
exactly the world in squares, so no projection is needed at all.

`MapTileStore` reads a tile straight out of the archive (a zip's central
directory is an index, so unpacking 6582 files would buy nothing).
`app:map:import` takes the archive from a game or server installation.

Two things had to line up in Leaflet, each wrong once against the browser
before it was right: `CRS.Simple` puts one unit on one pixel at zoom 0 while
the pyramid's finest level is the *highest* zoom, and Simple's y axis runs
upward while tile rows run downward — which left every requested row
negative. Verified by measuring what sits under the viewport centre:
searching 11800,6900 lands on 11800,6900. Commit `d7b9db0`.

### 2026-09-04 — Permissions enforced everywhere
Every endpoint now guards on a permission rather than a role name, the
session reports the permissions a user effectively holds, and the
navigation shows only what they can reach. Roles are assignable in the
account dialog.

`access_control` had to loosen: it runs before any controller, so
`^/api/servers` demanding ROLE_SERVER_ADMIN turned a narrow role away
before the endpoint's own permission was consulted. Those three entries
are `ROLE_USER` now. A functional test caught that, not the browser.

The seven server pages in the sidebar collapsed into a table on the way
through — they were seven copies of the same twenty lines and each
needed its permission added. Commits `d46519c`, `6e9b9d4`, `70c6313`.

### 2026-09-04 — Two-way bridge
Bridge 0.8.0 reads as well as writes: the panel writes cmd-<seq>.json,
the bridge answers res-<seq>.json, each side owning its files and its
cursor. Twelve event actions go that way — the in-game clock, the
climate values, sounds at a point — because RCON has no command for any
of them.

Three decisions came from the reference panel's mistakes rather than
from repeating them: the resync only moves forward (a stale read of the
other cursor can be too low, never invented too high); the panel's
cursor is written after the command it accounts for; and each handler
confirms by reading back where that is safe — `getAdminValue` is a
plain field read, `getFinalValue` is not refreshed until the next tick.

`OnTickEvenPaused` replaces `OnTick`, so an empty server keeps
listening.

The Lua JSON reader is tested in Lua (`tests/Bridge/decode-test.lua`),
because Lua is what runs on the game server. Its first version silently
truncated any value containing an escaped quote — Lua patterns have no
alternation, so `"(.-)"` stops at the escaped one. It is a character
scanner now, and `BridgeDecoderTest` checks the copy in the test has
not drifted from the bridge. Commit `d3642d7`.

### 2026-09-05 — Two-way bridge confirmed live
The server restarted with 0.8.0 and answered. The assumption everything
rested on holds: **`getFileReader` reads a file the panel uploaded over
FTP into the Lua directory.**

A command takes 1 to 1.5 seconds from click to answer. Every handler
type was exercised against the live server — `setTime`, `setDate`,
`bridgeStartRain`, `bridgeStopRain`, `soundAtPoint`, `setClimateValue`
— and each answered with a real verdict rather than "the call did not
throw":

    {"seq":1,"ok":true,"message":"climate value set",
     "data":{"index":5,"value":0.6000,"asked":0.6000}}

The loop closes end to end: setting the hour to 13 from the panel put
`"hour":13` into the world state the bridge writes a minute later.

One thing worth noting for later: the bridge picked up the command file
left over from the pre-restart test and processed it on startup, which
is the queue behaving exactly as intended — a command survives a
restart rather than being lost.

### 2026-09-05 — Map rebuilt on OpenSeadragon, and the isometric render
Leaflet gave way to OpenSeadragon because the target format decided it:
pzmap2dzi writes DZI, which OpenSeadragon reads natively, and swapping
a floor is `open()` on the running viewer rather than a rebuild.

The map fills its frame with everything floating over it — search top
left, places bottom right and collapsible, coordinates bottom left,
players and safehouses right, floor control centred right as plus,
floor, minus, layer toggles centred left in green.

Three bugs found in a browser rather than reasoned about:

The right click never opened the teleport dialog. OpenSeadragon's
MouseTracker claims the button before `nonPrimaryPressHandler` sees a
real click, so the handler hangs off the `contextmenu` event instead.

A deep link landed on the default position twice over — first because
the route is lazily loaded and `window.location.hash` reads empty by
then, then because the viewer's own debounced writer had already
overwritten it. The hash is captured at module load now.

Setting the view on `open` was undone a frame later by OpenSeadragon's
home animation, so it waits for the first drawn frame.

**The map now comes from the game server**, not a local installation:
`app:map:import --from-server="<name>"` pulls the 51 MB pyramid over
FTP. The server was running build 24909836 against the local game's
24909800 — 36 builds newer, which is exactly why this matters.

**The isometric render works.** `main.py unpack` was the missing step;
without it every tile is `.empty`. Measured: 438 GB for the whole world
at full resolution, of which level 22 alone is 336 GB, and one cell
renders in about a second. On-demand rendering of that level is built
but cannot reach the renderer from inside the container — see "Next
concrete step".

`TileGeometry` inverts pzmap2dzi's transform to find the cells behind a
tile. Its tests caught a factor of two in that inversion, which would
have rendered every missing tile from the wrong part of the world.
Commits `8445bab` through `d444519`.

### 2026-09-05 — The map, rebuilt to render itself

The panel now draws the world from the game server's own data, with no
game installation anywhere near it. The pieces, and what each cost.

**Cell data comes from the server.** `media/maps/<map>/world_X_Y.lotpack`
plus its header, about 1 MB and 0.7 s a cell, verified byte-identical
to a local copy by SHA-256. This was the finding that made everything
else possible, and it arrived late: a dedicated server was assumed to
have no map data at all. It has all of it except the artwork.
`CellFetcher` fetches; `app:server:find` was written to search the
server for it, and `app:server:ls --download` to pull a file down.

**Texture packs are uploaded once.** Five files, 414 MB, listed in
pzmap2dzi's own `conf/vanilla.txt`. They arrive in 8 MB pieces because
the production image accepts a 16 MB request and raising that would
apply to every endpoint; measured at 6.4 s a piece on a 10 Mbit line
against a 60-second limit. `TexturePackStore`, `ChunkedUpload`,
`TexturePackController`. Both upload cards take a drop as well as a
click.

Two things the real files taught. A pack is recognised by either
shape — `JumboTrees2x.pack` is the older format and carries no `PZPK`
marker, so demanding it refused a file the renderer reads perfectly
well. And a short pack is refused rather than stored: it parses far
enough to look valid and then renders holes.

**The renderer rides in the image.** `renderer/` in the repository,
`/opt/pzmap2dzi` in the container, its own virtualenv. `requests` is in
the requirements because `render_impl/save.py` imports it at module
load, though nothing here calls it.

**Object storage is configured through the interface**, like every
other credential — the operators are not developers and have no `.env`.
Five settings, the secret encrypted. The connection test writes, reads
back and deletes: a key that may list but not write passes a read-only
check and fails hours later. The endpoint is accepted with or without a
scheme, because Hetzner's console prints a bare host and AsyncAws
rejects it with "the endpoint is invalid", which names neither cause
nor fix.

**A run is a Messenger job** that draws one floor over the whole world
before the next, in the order 0, 1, -1, 2, 3. Before that it went cell
by cell drawing every floor, and after hours the map existed only in
the first few columns — a diagonal stripe.

Each batch is rendered, uploaded, verified against the store, and only
then deleted locally. Uploads compare content, not existence: S3
returns the body's md5 as the etag, so an unchanged world sends
nothing and a changed one sends only what changed. Tiles the new render
no longer produces are swept at the end rather than cleared at the
start, so the map is never missing tiles it still needs. A cell whose
lotpack checksum matches the ledger is skipped before it is drawn.

**Progress lives over the map**, not in the settings: it is the thing
being built. It streams (SSE, with polling alongside because Vite's dev
proxy buffers a stream until it ends), reports the tile name going
past, and carries pause and cancel.

#### What went wrong, and what it cost

**The geometry moved.** pzmap2dzi sizes the pyramid to the cells it is
given, so every batch wrote a different origin. Two full runs were
thrown away because of it — the second only after the user reported
seeing forest where none stood. `dzi_cell_range` pins the image to the
whole world; see "Next concrete step" for the two traps around it.

**Verify listed the whole bucket every batch**, and the listing grew
with the run. Seven hours in, a render was 12 percent done and heading
for 60 hours. Reusing the listing the upload already builds took that
step from 35 seconds to 0.03.

**Bytes were counted twice.** A batch that ended with failures kept all
its tiles, including the ones that had arrived, and the next pass
counted them again: 11.9 GB reported against 1.63 GB held. The figure
now reports what the prefix occupies, which is what an operator is
billed for.

**Cancel did nothing for minutes.** Two faults at once: the route
`/render/{serverId}` shadowed `/render/stop`, and the stop flag lived
in the file the handler rewrites every few seconds. Both fixed; the
flag now has its own file and the endpoint kills the worker outright.
Pause had the same shape — checked once per batch, which is hundreds of
uploads.

**Bundling was built and removed.** Sending tiles in archives should
have cut a day of round trips to minutes. Measured, it does the
opposite: 9.7 tiles/s against 19, because 32 small transfers in
parallel use the line better than one 6 MB serial stream. Commit
`4deddd4` keeps the reasoning and the numbers.

**ddev ran no worker at all**, and the production image capped one at
an hour with 128 MB — a render is a single message that runs for hours.
Now a `web_extra_daemon` locally, 24 hours and 1 GB in both.

#### Also this session

- The game's own top-down map was removed (`9a9f23b`). One pixel per
  world square is a blur at any useful zoom.
- Icons: the panel's 4351 came from `PZ_GAME_PATH` on the developer's
  machine, not from the server and not from an upload. The reference
  panel has no item icons at all — its spawn browser uses Lucide
  category symbols. Verified by reading its `SpawnBrowser.tsx` and its
  bridge's `getItemCatalog`, which returns ids and names only.
- Vehicle artwork does not exist to fetch: vehicles are `.fbx` models,
  and `media/ui/vehicles/` holds seven control icons.
- Screenshots and `TODO.md` were removed from git and ignored.

Commits `ecb20b2` through `4deddd4`.


### 2026-09-06 — The render finishes in an afternoon, and says so

**The two days had one cause.** `renderer/conf/conf.yaml:175` holds
`omit_levels: 0`, and `TileRenderer::writeConfiguration()` rewrote
`pz_root`, `output_root`, `render_cell_range`, `dzi_cell_range` and
`layer_range` -- but never `omit_levels`. The template's value stood, so
every run drew the deepest pyramid level: three quarters of the output.

Measured from this project's own figures (241 KB a tile, 3.5 MB/s to the
store, ~13 tiles/s rendered):

| Setting | Tiles | Size | Render | Upload |
|---|---|---|---|---|
| `omit_levels: 0` | 3.15 M | 724 GB | ~67 h | ~59 h |
| `omit_levels: 1` | 788 k | 181 GB | ~17 h | ~15 h |
| **`omit_levels: 2`** | **197 k** | **45 GB** | **~4 h** | **~4 h** |

Brief 08 already named 2 as the number to plan around. The setting had
simply never reached the renderer.

**That last row was wrong by a factor of three, and the run said so.**
It assumed five floors -- `FLOOR_ORDER`, which is only the progress
display's starting guess. The survey finds 47. The run's own estimate,
taken from the tile rate of the cells already drawn, is **~590,000
tiles, ~135 GB, ~12 h**. Still a fifth of what `omit_levels: 0` cost,
and it fits the 1 TB bucket with room.

Keep every floor: buildings reach 29 and bunkers -17, and `layer_range`
would blind the panel exactly where players build. `omit_levels: 3` is
the lever if storage ever presses -- ~34 GB, one zoom step, no floor
lost.

`DEFAULT_OMIT_LEVELS = 2`, overridable through `PZMAP_OMIT_LEVELS`.
**Verified against a real cell:** `map_info.json` reports `skip: 2` and
`w: 578672` -- exactly a quarter of 2314688 -- with `cell_rects` still
pinned to the whole world.

**Three traps found on the way.**

`omit_levels` maps to the renderer's own `skip_level` (`main.py:183`),
so the key written must be the former. `map_info_mismatch`
(`pzdzi.py:216-229`) checks `skip` among its keys, so changing the depth
needs the output directory genuinely empty -- and `rm -rf var/map/iso`
takes the 502 MB of unpacked textures with it.

`%env(int:default_omit_levels:PZMAP_OMIT_LEVELS)%` is wrong -- the
`default:` processor is missing -- and turned every tile request into a
500. `MapTileRouteTest` caught it on the first run, which is exactly the
regression that test was written for.

**`descriptorNames()` is not a defect.** An audit reported `$layer < $max`
as an off-by-one dropping the top floor. `maxlayer` is *exclusive*:
pzmap2dzi iterates `range(minlayer, maxlayer)` when writing descriptors
(`pzdzi.py:193, 238`) and copies the same value into `map_info.json`
(`:574`). `<` is correct; `<=` would ask for a floor with no `.dzi`.

**`fresh` was carried and ignored.** `MapController` read it from the
request and put it in the message; `RenderWorldHandler` never looked.
An operator choosing "start over" got a continued run -- and after an
`omit_levels` change that is the one thing that cannot work.
`startAfresh()` now clears the tiles, the store prefix and the cell
ledger, which is also what finally gives the `clearing` phase the
meaning its translation always claimed. The occupancy map survives on
purpose: no render setting changes what a cell holds, and re-reading it
costs 50 minutes.

**The render window, rebuilt.** It was one centred panel over the middle
of the map, with seven figures at equal weight and no answer to "how
much longer".

Positions measured in a browser rather than assumed: every edge and
corner is taken -- zoom and search top left, players top right, layers
and floors on the sides, coordinates bottom left, places bottom right.
The free strip is the bottom centre, 906 px at 1440 and 746 at 1280, and
the map surface starts at x=280/y=80, so the bar centres on the surface
rather than the viewport. Verified at 1280: bar 496-1040, coordinates
end at 411, places begin at 1157.

A **proportional timeline**, not a stepper. `rendering` and `uploading`
alternate once per batch of six cells -- hundreds of times -- so as
consecutive steps the marker would swing back and forth for hours. The
segments are as wide as the time they cost, and the survey segment
disappears entirely on a second run.

**Three timestamps make the estimate honest.** The handler kept 28
counters and one `startedAt`; a rate measured across the 50-minute
survey would put the projection hours out. `surveyStartedAt`,
`surveyFinishedAt` and `drawingStartedAt` now let `render-estimate.ts`
answer -- or return `null`, which it does for the first 30 seconds and
throughout the survey. An invented figure is worse than none.

`retryRound` and `retryPending` were written by the handler all along
and missing from the frontend type, so the one signal that the store is
refusing never reached the screen.

**Two display bugs fixed.**

OpenSeadragon wrote "Unable to open [object Object]: HTTP 404" across
the map whenever no `layer0.dzi` was in the store yet -- the normal state
before the first batch. An `open-failed` handler removes its message
node. Verified with every descriptor 404ing: no message, and the
progress bar explains the situation instead.

`route-error.tsx` showed `[object Object]` as its technical detail,
because React Router throws an `ErrorResponse` rather than an `Error`.
That is the line an operator is asked to send when reporting a problem.
It now reads `404 Not Found: Error: No route matches URL "/app/map"`.

**`ISOMETRIC_DEFAULTS` moved to `scale: 4`,** matching what the panel
actually renders, so a view opened before the render's own
`map_info.json` arrives is at the right size rather than sixteen times
too large. The three tests checking the formula against pzmap2dzi state
`scale: 1` themselves now, rather than inheriting whatever the panel
happens to render with.

**The texture packs are confirmed.** The local game installation was
deleted; a Windows copy on a USB stick at
`/Volumes/ESD-USB/ProjectZomboid` holds all five required packs, and all
five are SHA-256 identical to what was already uploaded. Nothing to
re-upload.

Dead code removed: `renderMissing()` and `parseTilePath()` (69 lines,
would have fatalled -- the call omitted the `GameServer` argument), the
unreachable `gameMapSource` branch, `tileExtensionFor`, `roundToSquare`,
`BUILD_42_FLOORS`, the map feature's `WORLD_BOUNDS`. `PZMAP_KEEP_CONF`
was inverted: `getenv() === ''` is true only when the variable is set to
an empty string, so unset -- the normal case -- kept every temp config,
and `renderer/conf/` had accumulated 19.

450 backend tests, 88 frontend tests. Both suites green.

**Ruled out, with the measurement:** PMTiles, MBTiles and a return to
Leaflet -- see "Ruled out, with reasons" above.

### 2026-09-06 — External base map with the panel's own controls (completed)

Request: replace the impractical self-render workflow with projectzomboidmap.com.
The user supplied `~/Downloads/screen-map (1).jsx` as a
reference and explicitly requested that none of the provider's controls appear.
The prototype loads `<img>` tiles directly, not an iframe. Adopted that approach
inside the existing OpenSeadragon integration; no prototype sample players,
safehouses, deaths or vehicle records were copied into the application.

Verified source evidence on this date:

- `https://projectzomboidmap.com/`: HTTP 200, no X-Frame-Options or frame-ancestors
  restriction in the response. An iframe was technically possible, but discarded
  once the supplied prototype and request for own controls clarified the approach.
- Its public `/assets/index-CT_6K3uo.js` uses
  `https://tiles.projectzomboidmap.com/maps/b42.20.2-r1/` and cumulative floors.
- `base/map_info.json`: w=2314368, h=1019040, x0=1036288, y0=-139296,
  sqr=128, skip=0, cell_size=256, minlayer=-17, maxlayer=30 (exclusive).
- `base/layer0.dzi`, `layer1.dzi`, `layer-1.dzi`, `layer29.dzi` all HTTP 200:
  TileSize=1024, Overlap=0, matching dimensions; ground Format=jpg and the
  sampled non-ground layers Format=webp. The prototype's all-JPG assumption
  would hide upper floors and basements.
- The metadata endpoint does not return an Access-Control-Allow-Origin for the
  panel origin. Ordinary image embedding works in the real browser. Inline
  metadata avoids cross-origin JSON/XML fetching; the HTML drawer avoids pixel
  readback required by WebGL. No forged Referer, CORS proxy or bulk download.

Files and behavior:

- `AGENTS.md`: created at project root from the existing project conventions in
  `CLAUDE.md`, with mandatory CONTEXT-first handling as section 0. Context
  preservation at 90%, 95% and 98% explicitly retains prior records.
- `frontend/src/features/map/map-config.ts`: `PROJECT_ZOMBOID_MAP` holds the
  versioned tile root, verified geometry and 47 floors; `floorFor()` returns an
  available floor or ground for an invalid deep link.
- `frontend/src/features/map/tile-source.ts`: emits positioned inline DZI sources
  for the external map. Ordinary JPG ground plus transparent WebP floor stack;
  negative floors do not include opaque ground. The descriptor shape is supported
  by installed OSD 6.1.0 but absent from the installed v5 typings, hence the narrow
  boundary cast. The existing local DZI source path remains available in code.
- `frontend/src/features/map/use-map-viewer.ts`: external source uses HTML drawer,
  `crossOriginPolicy: false`, `loadTilesWithAjax: false`; initial floor is
  normalized; initial external zoom is 80. A 15-second first-image timeout shows
  a source-unavailable message and is cleared by the first successful image.
  Right-click includes the current z level.
- `frontend/src/features/map/map-page.tsx`: directly selects the external source;
  removes map-status/render progress polling and render overlay. Server overlay
  polling remains every three seconds. Coordinate/place searches use zoom 80.
  Teleport mutation now submits the clicked floor rather than hard-coded z=0.
- `frontend/src/features/map/coordinates.ts`: `WorldPoint` accepts optional z for
  a contextual target; existing x/y projection formulas stay intact.
- `frontend/src/features/map/world-map.tsx`: passes source attribution to own map
  controls and displays localized failure text with a provider link.
- `frontend/src/components/layout/app-layout.tsx`: removes StartRenderButton from
  the global header, eliminating its render polling and obsolete start action.
- `frontend/src/features/settings/settings-page.tsx`: map settings explain the
  external imagery, retained own controls, lack of render/storage requirements,
  and base-map limitations. Texture/render setup cards are no longer mounted.
  Existing object storage settings and underlying rendering code remain intact.
- `frontend/src/i18n/locales/en.json` and `frontend/src/i18n/locales/de.json`:
  `map.external.{title,description,noRender,unavailable}` and floor-aware
  `map.teleportBody`.
- `frontend/src/features/map/tile-source.test.ts`: six regression tests cover
  inline source metadata, cumulative floor order, basement transparency, the
  full floor range, invalid floor fallback and exact external-map projection.

Verification (green except the explicitly listed limits):

- `ddev exec -d /var/www/html/frontend npm run build`: passes TypeScript and
  production Vite build; output is served from `backend/public/app`.
- `ddev exec -d /var/www/html/frontend npm test`: 94 tests across eight files pass.
- `ddev exec -d /var/www/html/frontend npm run lint`: exit 0, 0 errors, 27 warnings.
- `git diff --check`: clean.
- Browser at `/app/servers/01a06d21-0424-7894-a2ac-14d1408a2430/map`: real
  `img[src*="tiles.projectzomboidmap.com"]` have naturalWidth=1024; no iframe.
  Layer 1 loads WebP above layer 0 JPG. Search for 11800,6900 lands exactly there;
  changing floor 2 to 3 preserves `#11800,6900,80` and updates only the floor.
- Right-click on floor 2 opens a dialog naming Etage 2. No teleport was executed.
- Marker test: intercepted only the test tab's overlay response with a player at
  11800,6900, then verified `.pz-player` is centered within two CSS pixels on the
  corresponding map view. Interception removed afterwards; the real roster was
  empty, so actual moving-player alignment was not exercised this session.
- Failure test: blocked the tile origin only in the verification tab, observed
  the localized role=status message after 15 seconds, restored requests and
  reloaded; images loaded and the message disappeared.
- Existing authenticated browser tab was left for the user. Additional verification
  used a separate tab and did not mutate server data. Temporary screenshot is
  `/tmp/zomboid-external-map-verified.png`, outside the repository.

No backend changes, backend tests, production deployment, tile storage cleanup,
renderer removal or server-side gameplay actions in this task. Provider outages
or a removed pinned version remain external dependencies. The current error
indicator covers failure to load the first image; a later partial tile outage is
not globally diagnosed. Sparse basement/upper floor tiles can legitimately 404.

### 2026-09-06 — Attribution beside coordinates (completed)

User screenshot requested moving the credits from above the coordinates to the
right, in the same row. `frontend/src/features/map/map-controls.tsx` now groups
its coordinate-copy button and optional attribution link in a bottom-left flex
row with an 8px gap, vertical centering and wrapping on narrow screens. The link
reads `© The Indie Stone · projectzomboidmap.com · B42.20.2`, opens the provider
in a new tab and explains base-map limitations in its title. The previous
standalone attribution block in `frontend/src/features/map/world-map.tsx` is gone.

Verified browser geometry: coordinate button x=292, width=118.96875, y=796,
height=30; attribution x=418.96875, width=284.140625, y=799.5, height=23.
Both vertical centers are y=811 and the horizontal gap is exactly 8px.
Screenshot visually inspected. Final build and 94 tests green. No open work
remains for the requested placement.

### 2026-09-06 — Retire self-rendering and S3 completely (completed)

The user explicitly authorized removal of every no-longer-needed map-rendering
component, the occupancy/lotheader survey and progress window, the entire
`renderer/` directory, and S3 connection/settings. This task supersedes the
previous decision to leave those components on disk. Existing unrelated changes
from the prior map integration were preserved; no commit or deployment was made.

Deleted source and configuration scope:

- `renderer/` in full, including its Python code, configuration, requirements,
  cached bytecode and local virtual environment (about 58 MB on disk).
- `backend/src/Server/Map/`: CellFetcher, CellLedger, CellOccupancy, CellSurvey,
  IsometricTiles, OccupancyMap, RenderProgress, RenderRoot, TileGeometry,
  TileReader, TileRenderer, TileUploader and the map texture store. No lotheader
  scanning, tile generation, uploading, occupancy survey or render-state storage
  remains in the application.
- `backend/src/Message/RenderWorld.php`,
  `backend/src/MessageHandler/RenderWorldHandler.php`,
  `backend/src/Controller/Api/TexturePackController.php`.
- `backend/src/Command/{BenchmarkUploadCommand,ClearStorageCommand,RenderMapCommand,RenderWorldCommand,TestObjectStorageCommand}.php`.
  General FTP/SFTP diagnostics remain useful for server administration and icons.
- `backend/src/Storage/` in full: ObjectStorageFactory, ObjectStorageInterface,
  ObjectStorageNotConfigured, ObjectStorageProbe. `backend/src/Server/Storage/`
  is unrelated FTP/SFTP server access and is retained.
- `backend/src/Controller/Api/MapController.php`: only the authenticated live
  `GET /api/map/{serverId}/overlay` remains. Removed map status, DZI/tile serving,
  render start/stop/pause/progress/stream routes and their injected services.
- `backend/src/Controller/Api/SettingsController.php` and
  `backend/src/Entity/AppSetting.php`: removed S3 keys from editable/secret lists
  and removed POST `/api/settings/storage/test`.
- `backend/composer.json` and `backend/composer.lock`: Composer removed
  league/flysystem-async-aws-s3, async-aws/s3 and async-aws/core, no other package
  versions changed. Service configuration in `backend/config/services.yaml`
  no longer contains renderer, textures, storage or PZMAP parameters.
- `backend/config/packages/messenger.yaml`: removed RenderWorld routing and the
  render-specific redelivery override. Kept async mail routing and failure queue.
- `Dockerfile`: removed renderer COPY, Python virtualenv/pip installation and
  PZMAP environment declarations; dropped explicit python3/python3-venv packages
  (supervisor can still bring its own system Python dependency).
- `docker/supervisord.conf` and `.ddev/config.yaml`: mail consumer now uses
  3600-second / 256 MB limits instead of render-specific 86400-second / 1 GB
  limits. Kept production scheduler worker. Removed ddev's entire renderer
  post-start installation hook. Current ddev container was not restarted, so
  daemon command changes take effect on next start/restart.
- `backend/.env` and local ignored `backend/.env.local`: removed only PZMAP
  declarations without printing their values or other environment values.
- Root `.gitignore` and `backend/.gitignore`: removed renderer-only entries.

Deleted UI:

- `frontend/src/features/map/{render-overlay,start-render-button,render-timeline,render-figures}.tsx`
  and `render-estimate.ts`, `render-format.ts`, `render-estimate.test.ts`.
- `frontend/src/features/settings/{object-storage-card,world-render-card,texture-packs-card}.tsx`,
  `render-progress.ts`, `texture-packs.ts`.
- `frontend/src/features/settings/settings-page.tsx`: S3 tab and card removed;
  remaining tabs are Steam, Google, E-Mail, Item-Icons, Karte.
- `frontend/src/features/settings/settings.ts`: S3 keys and storage test API removed.
- `frontend/src/features/settings/instructions.tsx`: removed obsolete storage/map
  texture instructions and now-unused imports.
- `frontend/src/features/map/map-config.ts`: removed local ISOMETRIC_DEFAULTS,
  PENDING_SOURCE, isometricSourceFrom, optional external flag and unused DZI field.
  The published external metadata, geometry types and quick targets remain.
- `frontend/src/features/map/map.ts`: removed MapStatus/IsometricSource types and
  mapStatus API. `tile-source.ts`, `use-map-viewer.ts`, `world-map.tsx` and
  `map-controls.tsx` now assume the external map directly; no local-source branch
  or render-wait UI remains. Coordinate tests use published map geometry.
- `frontend/src/i18n/locales/{de,en}.json`: removed S3, self-render progress/setup,
  map texture instructions and obsolete no-render strings. Item-icon texture
  instructions remain because they serve item artwork, not map rendering.
- `docs/superpowers/briefs/{05-livemap,08-isometric-map}.md`: marked old rendering
  plans historical with a pointer to current CONTEXT; historical evidence retained.

Shared icon dependency resolved before deleting the renderer:

- `backend/src/Server/Items/Icons/ChunkedUpload.php` and `UploadRefused.php`
  replace their old map namespace. ChunkedUpload has no TexturePackStore
  dependency or map-pack-only methods. It keeps arbitrary icon pack name
  validation, ordered appends, size validation, deletion after extraction, and
  both PZPK and legacy pack-header acceptance. Error keys use the icons namespace.
- `backend/src/Controller/Api/IconController.php`: updated type references;
  existing icon chunk/finish endpoints and extraction behavior retained.
- Temporary icon uploads now use `backend/var/icons/incoming`; any existing
  non-map .part files were moved there before deleting the old texture directory.
- `backend/tests/Unit/Server/Items/Icons/{ChunkedUploadTest,SafeNameTest}.php`:
  name tests retained and moved, with five meaningful upload tests covering
  assembly/deletion, legacy packs, invalid content, missing chunk and size mismatch.
- Retired rendering-only tests under `backend/tests/{Unit,Integration}/Server/Map`,
  `backend/tests/Unit/Storage` and Functional/MapTileRouteTest were removed.
  Functional/SettingsTest replaces S3 feature tests with a regression asserting
  that an obsolete S3 key is rejected (422), not persisted and not listed.

Data cleanup:

- Added and locally ran `backend/migrations/Version20260906043000.php`.
  It deletes exactly the five s3.* configuration rows and only messages carrying
  App\Message\RenderWorld, checking PHP-serialized/escaped body and JSON type
  headers. Mail and other queued messages are not cleared. The migration is
  explicitly irreversible because obsolete credentials/jobs cannot be restored.
- `ddev exec -d /var/www/html/backend php bin/console dbal:run-sql
  "SELECT count(*) AS remaining_s3_settings FROM app_setting WHERE name LIKE 's3.%'"`
  returned 0. Values were not read or printed. The old doctrine:query:sql command
  no longer exists; dbal:run-sql is the correct current command.
- Verified no messenger render worker or Python render process was running before
  deleting local `backend/var/map` (~687 MB), `backend/var/map-cells`,
  `backend/var/pz-root`, and `backend/var/textures` (~408 MB). Together with
  renderer/ this frees roughly 1.1 GiB. `backend/var/icons` remains (~17 MB).
- No remote bucket data was accessed/deleted, no provider keys revoked, no FTP
  server data removed and no gameplay commands executed.

Verification:

- Backend suite: `ddev exec -d /var/www/html/backend php bin/phpunit` —
  377 tests, 724 assertions, green. Expected access-denied logs from permission
  tests are not test failures. Includes icon upload/extraction tests.
- Frontend suite: `ddev exec -d /var/www/html/frontend npm test` —
  81 tests in seven files, green. Count reduction is retirement of renderer tests.
- Frontend production build passes; final assets written to backend/public/app.
- `php bin/console lint:container` in ddev passes; dependency wiring is valid.
- `docker build -t zomboidcontrol:renderer-cleanup .` completes successfully.
  Existing vendor-stage `composer run-script ... || true` still suppresses the
  pre-existing dev-environment MakerBundle cache-clear error during the no-dev
  vendor stage; this cleanup did not change that unrelated behavior.
- Real browser: settings exposes five tabs and no S3; external map images load
  with naturalWidth > 0; map source explanation remains. Retired render,
  texture, local tile and storage-test routes all return 404.
- Authenticated GET `/api/icons` returned HTTP 200, count=4351, available=true.
  Initial settings text briefly said no icons while its query was still pending;
  the subsequent API result confirms the original artwork remains intact.
- Source audit finds no ObjectStorage, RenderWorld, RenderProgress,
  TexturePackStore, old Server\Map namespace or local-map fallback references
  in active backend/frontend source. Historical documentation and migration
  deliberately mention retired components. `git diff --check` is clean.

No unfinished renderer/S3 removal work remains. Existing image-source limits
(public provider availability and fixed base-world imagery) remain as documented.

Additional final verification: `docker run --rm --entrypoint php
zomboidcontrol:renderer-cleanup bin/console lint:container --no-debug` passes
inside the built production image as well. Final frontend rebuild after removing
the unused layer DZI field also passes.

### 2026-09-06 — Vehicles on the map, place names, and a permission of their own

Three requests in one session: show vehicles on the map (the layer switch
existed but nothing was ever drawn), label the towns, and give both a
switch. The vehicle work grew into reading the server's save files and
rendering the game's own 3D models.

#### Vehicles: where they come from

**The bridge alone cannot answer this.** Zomboid only keeps chunks in
memory where a player stands, so with nobody online `getCell():getVehicles()`
returns nothing — not an error, simply an empty world. The panel needs
every vehicle, always.

**`Saves/Multiplayer/<world>/vehicles.db` is the answer.** A real SQLite
database, one row per vehicle, written by `zombie.vehicles.VehiclesDB2`.
Verified against the user's server: 125 vehicles with world coordinates,
readable over FTP with no player online. Vehicles in never-visited
regions are genuinely absent — `IsoChunk.AddVehicles()` generates them
the first time a chunk loads and writes them straight to this file, so
nothing exists to read before that. `map_meta.bin` holds no vehicle data
(checked in `IsoMetaGrid.load()`).

**The `data` BLOB was decoded from the decompiled `BaseVehicle.save()`
and verified byte for byte against real rows:**

| Offset | Field | Checked against a real taxi |
|---|---|---|
| 0 | serialise flag | 1 |
| 1 | class id | 33 = `IsoObject.factoryGetClassID("Vehicle")` |
| 2, 6 | offsetX, offsetY | 64.0, 192.0 |
| 10, 14, 18 | x, y, z | identical to the row's own columns |
| 22 | `IsoDirections` ordinal | agrees with the quaternion |
| 26 | mod-data flag | 0; a 1 here makes the rest unreadable, so such a row is refused |
| 27 | physics height | |
| 31 | rotation quaternion x,y,z,w | **all 125 headings readable** |
| 47 | script name, uint16 + UTF-8 | `Base.PickUpVan` etc. |
| then | skin, engine, four durabilities | |

`App\Server\Vehicles\SavedVehicleReader` parses this forwards rather than
by fixed offsets, because the mod-data flag can shift everything after
it. 12 unit tests use real bytes from the user's save; synthesised ones
would only prove the reader agrees with itself.

**Heading is taken as it stands, with no offset.** An identity quaternion
means unrotated and must read as zero — verified against the taxi in the
save, which carries the identity and stands unturned in the game. The
row's own eight-way `IsoDirections` field looks half a circle out against
this; an offset to match it was tried and reverted, because that field is
measured from the game's north rather than the model's front and matching
it put every vehicle back to front.

**Paint cannot be read from the file.** `colorHue`/`colorSaturation`/
`colorValue` sit behind the part list, and every part can carry nested
inventory items of variable length — skipping it would mean
reimplementing the game's item serialisation. The bridge reads them off
the live object instead (`getColorHue()` and siblings are public), so the
two sources are combined: the database supplies every vehicle, the bridge
fills in paint, rust and skin for the ones it can see. `VehicleOverlay`
merges them, keyed by the id both carry.

#### The bridge

`vehicles.json` was always empty, and the reason was not what it looked
like. Three separate faults, in order:

1. `IsoCell.getVehicles()` returns a `java.util.Set`. It was **not** the
   problem — the game's own `ISVehicleBloodUI.lua` iterates exactly that
   with `size()`/`get(i-1)`, so Kahlua does expose it. An earlier
   diagnosis blaming the Set was wrong and is recorded here so it is not
   repeated.
2. The real fault: the bridge's own `indexable()` guard probed
   `list:get(0)` to decide whether a source was usable. An **empty** list
   has no element zero, so the probe threw and every source was thrown
   away — with nobody near a vehicle the panel was told "none" rather
   than "none loaded". Now it accepts anything answering `size()`.
3. The bridge reports which source answered (`cell`, `manager`, `world`,
   `none`) and why the others were rejected, so an empty list can be told
   from an unreadable one. That diagnostic is what found fault 2, live.

Bridge 0.12.1 also writes `angle`, `hue`, `saturation`, `value`, `rust`
and `skin`. **The server still runs 0.12.0 — until 0.12.1 is uploaded
every vehicle draws grey, because the paint never arrives.**

#### Rendering the real models

The map draws each vehicle from the game's own FBX with its own texture,
in three.js. What that cost, and what is settled:

- **The map is the 2:1 projection 2D games use.** Its ground plane is
  squashed to half but height is drawn at full scale — its own geometry
  proves it: a storey is 192 px and a Zomboid storey is three metres, so
  64 px a metre, the same as along the ground. A camera cannot do both,
  so the body is stretched by `1/cos(30°)` to put back what the tilt
  takes away.
- **The heading is turned in the scene, not on the finished image.**
  Equal steps of heading are unequal steps on screen — 0, 45, 90 degrees
  land at 63.4, 90, 116.6 — because a turning vehicle traces an ellipse
  on a squashed plane. A CSS rotation turns on a circle and cannot
  express that. Each heading is therefore its own render, bucketed to 15
  degrees, which caps a vehicle type at 24 cached images.
- **Camera yaw 225°, elevation 30°.** The elevation follows from the map's
  vertical squash (`asin(0.5)`). Two yaws satisfy the geometry, one
  viewing the vehicle's front and one its back, and the arithmetic cannot
  tell them apart; 225 was settled in a browser against the same taxi
  seen in the game.
- **Size comes from the model, not from `extents`.** The script's
  `extents` is the physics collision box and runs anywhere from 15 % small
  to 15 % large against a real body, so it cannot be corrected by a
  factor — it is only the fallback for the 188 catalogue entries that
  ship no model file. The 53 that do are measured and scaled by the
  script's own `scale`.
- **`FBXLoader` already converts the axes.** After loading, length is on
  z and height on y. An extra quarter turn to "stand the model up" laid
  every vehicle back down; it is not needed.
- **Rendered at 512 square.** A five-metre car spans about 250 screen
  pixels at the closest useful zoom, and the shell textures are 512, so
  anything smaller is upscaled and looks smeared.

`VehicleCatalogue` (generated, 241 entries) maps a script name to its
model, shell texture, paint mask, scale, measured size and wheel
positions, with `template!` inheritance resolved — most vehicles declare
only their model and inherit the rest.

#### Wheels: not finished

Wheels ship only as `media/models/Vehicles_Wheel.txt`, the game's own
text mesh format. `frontend/src/features/map/zomboid-mesh.ts` reads it —
7 tests against the real file, and the format's v axis is flipped on the
way in because three.js reads it the other way.

**They are still in the wrong place, and the cause is identified but the
fix is unverified.** What was established:

- `VehicleScript.Loaded()` multiplies every wheel offset by the body
  model's scale once at load; `BaseVehicle.updateTransform()` divides the
  same scale straight back out (`scriptWheel.offset.x / scale`). The net
  multiplier on a file's offset is therefore **exactly one**. Four
  factors were tried by eye first — 1.82, 0.91, 0.83, 1.0 — and each put
  either the front or the rear wheels right.
- `updateTransform()` **negates x** (`offset.x / scale * -1.0f`). Without
  that every wheel sits on the side of the car with no arch.
- `radius` and `width` are **not used for drawing at all**. The render
  path never reads them; their only uses are a ground-clearance test, a
  collision distance and a debug wireframe. Scaling the mesh to them gave
  wheels taller than the car. The mesh keeps its own size.
- `models_vehicles.txt` and `template_tire.txt` declare **no** offset,
  scale or rotation for wheels — all defaults, so no script value is
  missing.
- **The likely remaining cause:** `FBXLoader` puts `rotation.x = -1.571`
  on the node holding the body geometry, and the wheels were being added
  to the group *around* that node, so they kept the file's axes while the
  body had already been turned. A fix attaching them to that node is in
  `vehicle-renderer.ts` (`bodyFrame()`), written but **never seen
  working** — the browser kept serving a cached render and ddev was
  restarted before it could be confirmed.

Next step for whoever picks this up: force a fresh render (the cache is
keyed by type, paint and heading, so a code change alone does not
invalidate it), then look at one vehicle at close zoom and check all four
wheels sit in their arches.

#### Places

Town names are drawn from the game's own `worldmap-annotations.lua`
(`addUntranslatedText("MapLabel_<name>", "text-town", x, y)`), which is
where the game itself puts them. The list previously used `map.info`'s
`zoomX`/`zoomY` — a start area's camera point, not a town — which drew
Muldraugh's name in the woods east of the town and made every jump target
miss by the same distance.

All names are drawn the same size and at every zoom: the game's own
`setScale` runs 4 to 10 and every label carries `setMinZoom(0)`, but
carried straight into CSS that made Louisville tower over the map, and
the user asked for one size.

Labels and vehicles are both pinned to floor 0. They stand on the ground,
and following the selected floor's offset lifted them off the road when
an operator looked at an upper storey.

#### Permissions, switches, settings

- New `vehicles.view` permission. `Version20260906060000` grants it to
  every role that already had `players.view`, so existing administrators
  keep the layer. The map endpoint returns no vehicles without it.
- A layer switch whose permission is missing is disabled rather than
  hidden, with a tooltip saying why — a control that vanished would read
  as a fault. Applies to players and safehouses as well.
- Vehicle models and textures are uploaded through the settings, like the
  item icons: 146 models and 399 textures, in batches of 15 (PHP's
  `max_file_uploads` is 20 here and silently drops the rest). They live
  in `backend/var/vehicle-models`, out of git, because the artwork is The
  Indie Stone's.
- The map tab in the settings is gone — the credits are on the map
  itself. The save button now appears only on the tabs that hold fields;
  on the upload tabs a file is transferred the moment it is dropped, so a
  save button there suggested the transfer still needed confirming.

#### A crash fixed on the way

The player page died with "Cannot read properties of undefined (reading
'month')". `WorldStrip` checked `gameTime !== null`, which lets
`undefined` through: the bridge rewrites its file in place, so a read can
catch it mid-write and return an object whose fields are absent. It now
checks every field it reads. The regression test fails on the old guard
in three of five cases.

#### Files

Backend: `src/Server/Vehicles/{SavedVehicleReader,SavedVehicleStore,
VehicleRecord,VehicleCondition,VehicleOverlay,BridgeVehicleSource,
VehicleSourceInterface}.php`, `src/Server/Vehicles/Models/{ModelStore,
VehicleCatalogue}.php`, `src/Controller/Api/VehicleModelController.php`,
`src/Command/VehicleListCommand.php`, `migrations/Version20260906060000.php`,
`resources/bridge/ZomboidControlBridge.lua` (0.12.1).

Frontend: `features/map/{vehicle-renderer,vehicle-marker,zomboid-mesh,
use-vehicle-renderer}.ts`, `features/settings/{vehicle-models,
vehicle-models-card}.{ts,tsx}`, plus changes to `use-map-markers.ts`,
`layer-toggles.tsx`, `map-config.ts`, `map.ts`, `world-map.tsx`,
`world-strip.tsx`, `settings-page.tsx`, `index.css` and both locales.

#### Verification

418 backend tests, 137 frontend tests, 14 Lua checks, production build and
linter (0 errors) all green. The reader was additionally run against all
125 rows of the user's live database: every row parsed, every heading
readable. Vehicle position, size, rotation, texture and paint were checked
in a browser against a screenshot of the same taxi in the game.

Not verified: the wheel fix described above, and bridge 0.12.1 on the
server. Damage textures, rust and broken windows are not started — the
textures exist (`Veh_Damage1/2`, `Veh_Rust`, `_damaged_01/02` per vehicle)
and the scripts declare them, and `VehicleWindow.isDestroyed()` is
readable per part through the bridge, but nothing reads any of it yet.
There are no separate door, bonnet or boot models in the game: only two
vehicles declare them and even those ship no file, so the arches and
openings in the body are the model as authored.

### Next task, asked for on 2026-09-06

**The server name cannot be edited.** A server's name is set when it is
created and there is no way to change it afterwards. The user asked for
this first, ahead of the unfinished vehicle work above.

## 2026-09-06 — The server name and description became editable

Done. This was the task recorded above as the next one.

### What was actually missing

Nothing in the backend. `PATCH /api/servers/{id}` already accepted both
`name` and `description`, and `updateServer()` in
`frontend/src/features/servers/servers.ts` already sent them. The gap was
the interface: `server-detail-page.tsx` printed the name as a heading and
the description as a paragraph beneath it, and offered no field for
either. The create dialog on the list page asks only for a name, so a
description could never be set at all.

### The change

A third tab, **General**, first in the row and the one that opens by
default, holding the two fields:
`frontend/src/features/servers/server-detail-page.tsx:214`. It reuses the
page's existing draft-and-save mechanism, so a change there is saved by
the same button as the FTP and RCON tabs and cancelled by the same
Cancel. `Input` rather than a textarea — the project has no textarea
primitive and a one-line note is what the field is for.

`maxLength={100}` on the name matches the column and the
`Assert\Length(max: 100)` on the entity, so the limit is felt while
typing rather than reported after saving.

### A blank name is now refused rather than swallowed

`ServerController::update()` used to test
`trim($payload['name']) !== ''` as part of deciding whether to apply the
name at all, so clearing the field and pressing Save returned 200 with
the old name and a "saved" toast. The operator was told their change was
kept when it was discarded. It now answers 422 with
`errors.name = validation.required`, the same shape `create()` uses
(`backend/src/Controller/Api/ServerController.php:95`).

The interface does not rely on that: `nameIsBlank` disables Save and
shows `servers.nameRequired` under the field. The backend check is the
one that matters for anything calling the API directly.

### Translations

Five new keys per locale, added to the existing `servers` block:
`generalTab`, `generalTitle`, `generalDescription`,
`descriptionPlaceholder`, `nameRequired`. `servers.name` and
`servers.description` already existed and are reused.

### Verification

- Three new backend tests in
  `backend/tests/Functional/ServerConfigurationTest.php`:
  `testRenamesAServer` (and that it trims), `testChangesAndClearsTheDescription`
  (set, then clear back to null), `testKeepsTheNameWhenTheNewOneIsBlank`
  (422, and the stored name is untouched afterwards). The last one failed
  against the old controller with 200, which is what proved the fault.
- Permission is already covered: `PermissionEnforcementTest::testSeeingServersDoesNotAllowEditingThem`
  patches `name` with only `ViewServers` and expects 403. No new test was
  added for it.
- 421 backend tests, 137 frontend tests, 14 Lua checks, production build,
  linter 0 errors. `git diff --check` clean.
- Checked in a real browser against the live panel
  (`https://zomboidcontrol.ddev.site:5173/app/servers/…`): renamed
  "GTX Gaming" to "GTX Gaming Kentucky" with a description, and the
  heading, the paragraph, both fields and the sidebar entry all followed.
  Blanking the name showed the message and disabled Save. Cancel
  discarded. The server was then set back to "GTX Gaming" with an empty
  description, which also confirmed a cleared description reaches the
  database as null.

### Still open, unchanged from the entry above

1. Wheels — the `bodyFrame()` fix in `vehicle-renderer.ts` is written but
   has never been seen working. Force a fresh render first; the cache is
   keyed by type, paint and heading, so editing the code does not
   invalidate it.
2. Bridge 0.12.1 is not on the server yet — the panel reports 0.12.0
   installed against 0.12.1 available. Until it is uploaded and the game
   server restarted, vehicles render in the default paint.
3. Body damage, rust and broken windows: researched, nothing implemented.
4. The upload descriptions naming the exact source folder for each asset
   kind were asked for and are not written.

---

# 2026-09-06 — A long session: shell, logo, vehicles, and a suite that finally passes

The full plan for everything below lives at
**`~/.claude/plans/snug-stargazing-russell.md`** — read it before picking
any of this up. It carries the reasoning, the measured facts and the
decisions the user made, in more depth than this log.

## Everything the user asked for, in the order they asked

Recorded verbatim in intent, because much of it is not yet built.

1. **Rename "Eventkonsole" to "Events"**, and give it sub-categories in the
   sidebar — weather as its own entry.
2. **A weather page with icons** — rain, fog, thunderstorm — not a text list.
3. **Separate actions from weather**: "Blitz ist eher eine aktion, donner
   gehört eher zu geräuschen."
4. **"Das UX muss einfach intuitiver und ansprechender werden"** — repeated
   three times over the session. Now rule 7 in CLAUDE.md.
5. **Vehicle spawning out of Events entirely**, into a top-level page built
   like Items: a grid, single selection, player on the right. With **all
   variants and liveries** per vehicle, two levels (body first, then
   livery), drawn with the map's own renderer.
6. **Weather off the players page** — "wieso wird mir dort das wetter
   angezeigt?"
7. **Language and theme toggles in the top bar**, theme with dark/light/
   **system**. A notification bell later, only once there is a real use.
8. **The panel as a PWA**, with a Zomboid zombie face as its logo.
9. **A player dossier like the reference panel's** — vitals, moderation,
   spawn, abilities, notes and log — built whole, including healing, god
   mode, XP and the rest.
10. **Design closer to the reference panel**: "volle Annäherung" — Zomboid
    green, monospace section marks, terminal feel.
11. **Two-column card lists** where a card holds a short control.
12. **Charts** on the overview and a statistics page — kills per player was
    the example.
13. **Panel version shown, and update checks** against GitHub — "vielleicht
    wäre was für die benachrichtigungsglocke".
14. **Security headers** and **a proper `llms.txt`**.
15. **Avatars from the social login, and changeable** — with cropping and
    scaling, not just upload.
16. **Web-optimised images**: AVIF or WebP, quality 80, scaled down.
17. **Maximum performance**: lazy loading everywhere, caching where it pays.
18. **Lighthouse 100** everywhere.
19. **`prefers-reduced-motion` honoured everywhere — except the map.**
20. **GDPR compliance** — with the explicit correction that **nothing the
    panel needs may be removed**.
21. **Discord integration** like the reference panel's, and beyond it:
    **admin actions individually switchable** (`addItem` yes, healing no),
    with **every message editable**.
22. **Conventional commits** from now on.
23. **Better hover effects, page transitions, gradients and backgrounds.**
24. **Favourites for vehicles, and filtering by them.**
25. **Delegate to subagents** — but only tasks with no points of contact,
    and **any bridge change must surface in the main task**.

## Built and committed today

Nine commits, `34053c4` through `11f603c`. All green: **468 backend tests,
172 frontend tests, 10 Lua checks, 0 lint errors**, three consecutive
backend runs to prove stability.

### `fix(tests): leave the database as the test found it` — `a451b0e`

**The most valuable fix of the session.** The functional suite had failed
differently on every run since the tests were written — 10 to 50 errors, a
broad collapse into 401s across unrelated classes. Two subagents hit it
independently and both correctly called it pre-existing; one proved it with
five runs of the untouched baseline.

Cause: every functional test emptied its tables in **`setUp`**. That cleans
before each test but never after the last, so whichever class ran last left
rows in the shared test database and the next run died on a duplicate
email.

`backend/tests/Functional/FunctionalTestCase.php` now clears in
**`tearDown`**, once, and all 17 tests extend it. Dependent tables first,
because Postgres enforces the keys.

**Deliberately not in `setUp`:** touching the container there boots the
kernel before `createClient()`, which Symfony refuses. My first attempt did
exactly that and turned 30 flaky errors into 167 consistent ones — which at
least proved the mechanism.

### `feat: give the panel a shell` — `72d61a3`

- Top bar with language and theme toggles. **Theme gained its third state**:
  `ThemeProvider` supported dark/light/system all along, but the sidebar's
  flip (`theme === 'dark' ? 'light' : 'dark'`) could not express "follow the
  system".
- **Weather removed from the players page** and moved to the overview.
- The overview, previously 32 lines holding one untranslated card, now
  carries stat cards, the world strip, an activity timeline and section
  tiles with per-area state. **Host telemetry deliberately absent** — it
  would measure our own container.
- Panel version declared once in `services.yaml` (`app.version: 1.0.0`),
  reported by `/api/health`, shown in the sidebar footer.
- `PanelUpdateChecker` against the GitHub releases API, **cached six
  hours** because unauthenticated calls are limited to sixty an hour.
  `upToDate` is **null** when the check fails: not knowing is not the same
  as being current.
- Security headers in the kernel rather than a vhost, since the panel runs
  behind whatever Docker or Coolify provides. HSTS only over TLS.
- `robots.txt`, `X-Robots-Tag: noindex`, and `llms.txt` as a map of the
  project for contributors.

### `perf: split the heavy chunks` — `21328af`

- **three.js was static in the map bundle**: 960 kB for a renderer that
  idles until artwork is uploaded. Now dynamically imported — the map page
  is **368 kB** and the renderer a 592 kB chunk a bare map never fetches.
  Note the abandonment guard: closing the map while three.js loads would
  otherwise leave an ownerless renderer.
- **Both locales sat in the entry chunk.** English stays (it is the
  fallback every missing key resolves against); German is its own chunk.
  564 kB → 524 kB.
- Motion: page transitions, hover lift, a faint primary wash on the
  surface. All stops under `prefers-reduced-motion` — **measured 0.00001s
  against 0.16s** — with `.pz-map` excluded by design.
- `locales.test.ts`: same keys in both languages, no empty values, matching
  interpolations. It catches exactly the mistake made earlier in the branch,
  where five action names existed in neither locale.

### `feat: make the panel installable` — `d79f9ea`

PWA with manifest, service worker and icons. API **never** cached; map
tiles cached a month. A newer build **offers** a reload in the top bar
rather than taking it — reloading mid-ban-reason would lose it.

**A real accessibility bug fixed:** `index.html` declared `lang="de"`
unconditionally, so a screen reader read English with a German voice. Now
hooked to i18next's own `languageChanged` event — the one place every path
passes through. My first attempt set it only after `init()`, which never ran
for an English start; **caught in a browser**, English text under
`lang="de"`, not by reasoning.

### `feat: put the real logo on the panel` — `399b1f9`

The user supplied three variants. The badge sits on the sign-in page, the
hexagon mark in the sidebar and as the app icon.

**The name beside the badge is text, not part of the image.** Rendered from
the wordmark at 128 px, "CONTROL PANEL" was already illegible; as text it
stays sharp and a screen reader can read it.

**Optimised, not shipped as delivered:** 1254 px squares over a megabyte
each, a third of it border. Trimmed, scaled, offered as AVIF and WebP.
**3.6 MB → 412 kB**, the badge alone 1205 kB → 30 kB. WebP beat AVIF here —
the opposite of the earlier photograph measurement — because these are flat
shapes with hard edges.

**The favicon stays the drawn SVG.** At 32 px the real logo collapses to a
green hexagon; the SVG scales sharply.

### `refactor(bridge)` + `fix(bridge)` — `7ba32bf`, `a598999`

Bridge **0.13.3**, currently **running on the live server**.

`writeVehicleCatalogue()` walks `getAllVehicles()` — whatever the server
loaded, mods included — and reports each script's model. Written
incrementally, the way `writeItems()` already handles five thousand items.

**Three corrections, each from running it rather than reading about it:**

1. `getModel()` returns a **Model object**, not a string. Printing it gave
   `VehicleScript$Model@1a35f99a` for all 241 vehicles; the file name is on
   the object (`getFile()`).
2. **A script carries exactly one skin.** The 51 van liveries are 51
   separate scripts, not skins of one — confirmed in `vehicle_van.txt`,
   which holds a single `skin` block. `getSkinCount()` reported nothing
   useful because the assumption was wrong, not the call.
3. **`VehicleScript` has two members**: `textures` (one Skin, holding the
   script's own block) and `skins` (a list, empty throughout the base game).
   `getTextures()` is asked first, `getSkin(0)` kept as a fallback for a
   modded vehicle that fills the list.

**Then the user corrected the whole approach**, and was right: the panel
*already* holds every vehicle's model, texture and paint mask in
`VehicleCatalogue`, generated from the game's scripts — and the artwork
those names point at is uploaded by the operator through an interface that
already exists. Asking the server for textures was a second source of truth
for no gain. The bridge now reports **only what the server alone knows**:
which vehicles exist, and which model each uses.

### `feat(vehicles): give spawning a page of its own` — `11f603c`

`/app/servers/<id>/vehicles`, sidebar entry between Items and Chat.

Live figures from the running server: **241 vehicles, 21 bodies, 68
drawable now** (the rest need textures uploaded). Real names throughout —
"Franklin Valuline — van (57)", "Dash Bulldriver (45)".

- `SpawnableVehicles` joins the bridge's list with the panel's artwork,
  cached per bridge session id like `ItemCatalogue`.
- `VehicleNames` (generated, 213 entries from `IG_UI.json`) supplies the
  display names the scripts do not carry. **42 catalogue entries have
  none** — wrecks, burnt shells, build 42's trade vans — so it falls back
  to the script name spaced out.
- Bodies group by **shared paint mask**; the 20 unmasked entries are all
  wrecks and go into one group rather than 20 groups of one. Two masks
  share the name "Franklin Valuline", so a duplicate gets a readable
  qualifier ("— van", "— vanseats").
- `VehicleSpawnController` reuses the event catalogue's `addvehicle`
  wiring, whose argument shape is pinned by `EventDispatcherTest`.
- **A vehicle the panel cannot picture is still listed and still
  spawnable.** Hiding it would remove a working capability, and that is
  exactly the modded case.

### `feat(players)` ×2 — `736decf`, `c9a6a92`

From subagents, both checked and both green.

`RosterWatcher` records joins and leaves by diffing the roster against the
snapshots, which makes it idempotent under a 5-second poll. **The case that
mattered:** a bridge that is not answering reports an empty roster, and that
must not read as everybody leaving. The discriminator already existed —
`refresh()` throws on an unreadable file and flags `stale` when too old.

`StalePlayerPurger` with an operator-set horizon, **off by default**. The
ban list is untouched by design: a ban that expired by itself would be a
security regression. A row exactly on the horizon stays. Scheduled beside
the ban lifter in `MainSchedule`, not the unused Flex stub in
`Schedule.php` — the brief named the wrong file and the agent said so.

### The rest

- `feat(settings): group the tab strip into sections` — `dc490bb`. Labels
  are `aria-hidden` so the tablist keeps its roles; a heading announced
  between tabs would break "tab 3 of 5".
- `perf(vehicles): immutable ETag for model artwork` — `34053c4`. A 304
  still reads the file to hash it, because `ModelStore` offers no digest.
- `fix(events): stop range-checking an empty optional number` — `47c9759`.
  The early-out sat after the number branch, so an optional number was
  effectively required. No action declares one today, which is why it needed
  a test rather than a bug report.
- `refactor(nav): one table of server pages` — `4dfdb59`. The sidebar and
  breadcrumbs each held the same seven pairs, in different orders.
- `feat: let a server be renamed after it is created` — `e8c44bc`. Clearing
  the name used to answer 200 and keep the old one.

## Uncommitted, in progress

`git status` shows these mid-flight:

```
 M frontend/src/features/vehicles/vehicles-page.tsx
 M frontend/src/features/vehicles/vehicle-preview.tsx
 M frontend/src/i18n/locales/{de,en}.json
 M frontend/src/routes/router.tsx
?? frontend/src/features/vehicles/body-tile.tsx
```

State: typecheck clean, lint 0 errors, locale test green. **Not yet checked
in a browser since the last change.** What changed and why:

- **The body row became a picture grid** (`body-tile.tsx`). Twenty-one text
  buttons in three wrapping rows made an operator read a wall of names to
  find a shape they would recognise on sight. The user called the old
  version "maximal unintuitiv und die IX ist grausam".
- **Both grids share `minmax(9rem,1fr)`** — the user asked for equal box
  widths, and 9 rem is what fits "Chevalier Cerise Wagon" over two lines.
- **A heading over each grid**: "Karosserie" above, "Lackierungen" below.
- **The lower grid is empty until a body is chosen** — the user's last
  instruction, and clearer than the "one of each" third state I had.
- **`CATALOGUE_HEADING = 285`** in `vehicle-preview.tsx`. The map's heading
  zero points away down the isometric axis, which showed a catalogue tile
  the vehicle's back. 285 turns the nose towards the viewer. **This is the
  one thing that needs a browser check first** — it was set, built, but
  never seen.
- The route needed **deeper indentation** than my first patch assumed, so
  it silently did nothing and the page 404'd. Fixed; the anchor is
  `path: 'servers/:id/chat'` at 20 spaces.

## TODO — pick up here

Ordered so each item is doable without the one after it.

### 1. Finish the vehicle page (in progress, uncommitted)

- [ ] **Check `CATALOGUE_HEADING = 285` in a browser.** Set and built,
      never seen. If the nose still points away, the other candidates are
      **267**, **277** or **297** — the arithmetic is
      `(wanted_screen_angle − 243.4) mod 360`, and the renderer buckets to
      15°.
- [ ] **Vehicle favourites, and filtering by them** — the user's last
      request before the compact. `localStorage` per server id is the
      established pattern here (`active-server.tsx`, `theme-provider.tsx`,
      `i18n/config.ts` all use try/catch around it). A star on the tile, a
      "Favoriten" filter beside the body grid. Strings are **already in
      both locales**: `vehicles.favourites`, `addFavourite`,
      `removeFavourite`, `noFavourites`.
- [ ] Verify a real spawn against the live server — needs a player online.
- [ ] Frontend tests for `vehicles.ts` (`matches`, `representatives` — note
      `representatives` is now unused and should go if nothing needs it).
- [ ] Commit as `feat(vehicles)`, then push.

### 2. Remove vehicle spawning from Events

`spawnVehicle` still sits in `EventCatalogue::players()`. Removing it means:
- [ ] Delete the entry and `VehicleScripts.php`; move `isValidName()` — the
      new controller already carries its own copy of the pattern.
- [ ] `EventDispatcherTest`'s injection case (around line 98) **moves to
      the vehicle tests rather than being deleted** — the injection guard
      is why that test exists.
- [ ] Delete `events.actions.spawnVehicle.*` from both locales.

### 3. Events into five categories (plan phases 2–4)

The largest remaining restructuring. Full detail in the plan; the essentials:
- [ ] `EventAction::GROUP_*` → `CATEGORY_WEATHER/SOUNDS/ACTIONS/ZOMBIES/WORLD`,
      `$group` → `$category`. **Backend and frontend in one commit** — the
      JSON key change breaks the frontend type otherwise.
- [ ] **Thunder → sounds, lightning → actions.** Proven from the bytecode:
      both call `transmitServerTriggerLightning(x, y, doStrike, doLightning,
      doRumble)`, and `thunder` passes `(false, false, true)` — the rolling
      sound alone — while `lightning` passes `(false, true, true)`.
- [ ] **Merge the duplicated rain.** `bridgeStartRain`/`bridgeStopRain` go;
      `startRain`/`stopRain` gain `CHANNEL_PREFERRED` (bridge first,
      fall back to RCON on `BridgeCommandFailed` only).
- [ ] Sidebar children via a `children` field on `SERVER_PAGES` (now in
      `components/layout/server-pages.ts`); `isExactly` helper for the
      children, prefix match kept for the parent.
- [ ] Layout route with an index, a static `weather` child and a
      `:category` child.
- [ ] Third breadcrumb level. `crumbsFor()` is already extracted and tested.
- [ ] `EventDispatcherTest`'s catalogue walk skips
      `!== CHANNEL_RCON` (line ~154) — **invert it to `=== CHANNEL_BRIDGE`**
      or the merged rain silently leaves coverage.
- [ ] `EventsApiTest`: re-pin the `ModerationAction` assertion once rain
      prefers the bridge.

### 4. The weather page

- [ ] Icon tiles as presets (frontend-only bundles of existing actions,
      fired sequentially with one toast). Names verified present in lucide:
      `Sun`, `Cloudy`, `CloudDrizzle`, `CloudRain`, `CloudRainWind`,
      `CloudLightning`, `CloudFog`, `SunDim`.
- [ ] `<WorldStrip serverId={id} />` at the top, imported as-is.
- [ ] Slider rows for fog, clouds, wind, temperature. **`radix-ui` is
      already a dependency and carries `Slider`** — a wrapper, not a new
      dependency.
- [ ] **Snow is omitted**: needs `setPrecipitationIsSnow()`, no action, no
      handler. New capability, deferred.
- [ ] The clickable **day arc** for the world page (the user chose it over
      an analogue clock, which is ambiguous across 24 hours).
- [ ] **Actions page as large directly-actionable cards**, not
      list-and-detail — three entries make a select-then-fill step friction.

### 5. Bridge 0.14: the weather state the page needs

**Requires an upload and a game-server restart — announce it.**
- [ ] Report `fog`, `clouds`, `thunderstorm`, `precipitation` in the world
      state. The game exposes `getFogIntensity()`, `getCloudIntensity()`,
      `getIsThunderStorming()`, `getPrecipitationIntensity()`.
- [ ] Then the live band is complete and the active preset can highlight.

### 6. The player dossier (plan phase 3)

Build whole, as the user asked. Capability research is **done** — the
findings, with signatures, are in the plan. Key points:
- [ ] List left / dossier right. `player-detail.tsx` stops being a `Dialog`
      and becomes the vitals tab. `ban-dialog` and `teleport-dialog` stay
      modal.
- [ ] **God mode, invisible, noclip, XP, voice ban, SteamID ban, whitelist
      are all plain RCON** — `godmodplayer`, `invisibleplayer`,
      `noclip <user>`, each taking `-true`/`-false` so the panel sets a
      state instead of toggling blind. **No bridge needed for that tab.**
- [ ] **Healing needs the bridge**, and is proven possible: the game itself
      does it server-side in `ClientCommands.lua` (`RestoreToFullHealth()`
      per body part, then `syncBodyPart`).
- [ ] **Vitals**: build 42 replaced the old `Stats` setters with
      `set(CharacterStat, float)`; the 24 stats carry their own
      `getMinimumValue()`/`getMaximumValue()`, so generate the sliders from
      the game's bounds. Weight is `IsoPlayer.getNutrition().setWeight(double)`.
- [ ] **A voice ban does not persist** — in-memory only, lost on reconnect.
      The row must say so.
- [ ] **Kill is the one unproven item.** The API exists
      (`Kill`, `dieNetwork`, `setHealth(0)`) but no server-side call site
      exists in the game's own Lua. Test before shipping.
- [ ] Notes and tags need a `PlayerNote` entity and a migration.
- [ ] **The dossier log is filtered to the selected player** — the
      reference panel shows everybody's and needs a second search box
      inside a view already scoped to one player. `moderation_action`
      already has `idx_server_username`.
- [ ] Route `new ModerationAction(...)` through one recorder service while
      adding the dossier actions. It appears in **six** places today; phase
      3 adds several more, and that seam is where notifications later hook
      in. Cheap now, expensive across twelve call sites later.

### 7. Deferred, agreed as separate plans

- [ ] **Discord** — the strongest reference feature. Bot status, per-command
      permissions, two-way chat relay, and **event notifications with every
      message editable**. Beyond the reference: **admin actions individually
      switchable**, default **off**, ideally to a separate channel.
      `ChatLine.php:63` already parses inbound Discord messages;
      `ChatBroadcaster` already sends. **`ChatLine` does not parse the
      channel** (`main_tab_title_id` is ignored), which "General only"
      versus "all public chat" needs.
- [ ] **Steam Workshop / mod management** — the user called it especially
      valuable. `-mods` and `WorkshopItems` live in the INI, readable and
      writable over FTP.
- [ ] **Server config editor** — INI, sandbox (183 values), spawn points
      and regions.
- [ ] **Scheduler** — cron tasks, restart warnings with a countdown,
      preset broadcasts. Caveat: **we can stop a server, never start one.**
- [ ] **Statistics and charts.** `getZombieKills()`, `getSurvivorKills()`,
      `getHoursSurvived()` are on `IsoGameCharacter` — three lines of Lua.
      **But `PlayerSnapshot` is one overwritten row per player, not a time
      series**, so "players online over time" needs a new sample table.
      Charts are a new dependency (no `recharts`), though `--chart-1`…`5`
      tokens already exist in both themes.
- [ ] **The notification bell.** Its first real content is now known:
      panel and bridge updates, plus the join/leave and admin-action feed.
- [ ] **Avatars**: from the social login (`SteamProfileFetcher` already
      calls `GetPlayerSummaries`, whose response carries `avatarfull`) and
      uploadable **with cropping and scaling**. GD is installed and already
      used this way in `IconExtractor::crop()`. **Proxy rather than
      hotlink**, so `img-src 'self'` stays true.
- [ ] **Lighthouse measurement** — never actually run. Needs a browser
      against the running app.
- [ ] **Wheels on the map renderer** — the `bodyFrame()` fix is written and
      still **never seen working**. Force a fresh render; the cache is keyed
      by type, paint and heading, so editing code does not invalidate it.
- [ ] **`ModerationAction`'s overloaded columns** — `username` holds the
      action id, `reason` the command, inputs are dropped and there is no
      `failed` flag, so the recent list cannot say "rain at 70".
- [ ] **Route-level permission guards** — per-page permissions are enforced
      only in the sidebar today.
- [ ] **Coordinate-based thunder and lightning**, blizzard and tropical
      storm, and the seven unexposed climate values already in
      `CLIMATE_VALUES`.

## Verification state at the end of this session

| | |
|---|---|
| Backend tests | **468, green** — three consecutive runs |
| Frontend tests | **172 across 17 files, green** |
| Lua checks | **10, green** (`vehicle-catalogue-test.lua`) |
| Linter | **0 errors**, 30 warnings (all pre-existing) |
| Typecheck | clean |
| Build | clean, PWA generated |
| Bridge on the server | **0.13.3**, matching what ships here |
| Not verified | the vehicle page since the grid rework; `CATALOGUE_HEADING`; Lighthouse; a real spawn |

---

# 2026-09-06 (later) — The vehicle page finished, and every vehicle drawn

Continues the session above, after a `/compact`. Ten commits, all pushed
(`origin/main` at `f3c910c`).

## What the user asked for, in order

1. Vehicle favourites, and filtering by them
2. "auch karosserien sollten favorisierbar sein nicht nur lackierungen"
3. A body row that does not jump when a selection is made
4. Boxes in a grid row all the same height
5. "hier fehlt die ganze karosserie" — the Sports Car ez drawn as wheels alone
6. "die Lackierung ist doch au allen 3 Lackierungen gleich" — three race cars, one picture
7. A larger preview, "machs ruhig noch größer", then centred
8. A road under the preview, then grass and bushes, then several tiles
9. Technical names copyable everywhere, "das gilt auch für items"
10. Vehicle information: seat, glove box and trunk capacity, then "alle informationen die wir bekommen können"
11. A filter by vehicle type — "Kleinwagen, Van, Anhänger, Einsatzfahrzeuge"
12. The Indie Stone attribution, with the terms quoted in full
13. Stand the vehicle on the road

## The three bugs worth remembering

### Every vehicle fell back to a placeholder — and nothing was missing

All 241 vehicles showed the fallback car icon. The subagent search proved
**no file was absent**: all 177 textures and all 117 models were already
in `backend/var/vehicle-models`.

The catalogue held the wrong name for **83 of 117** models. The game does
not name a mesh file in a vehicle script; it names a `model` block that
names the file:

```
model Vehicles_CarLights_NoRandom
{
    mesh = vehicles/Vehicles_CarNormalLights,
```

So the catalogue recorded `Vehicles_CarLights_NoRandom` while the file is
`Vehicles_CarNormalLights.fbx`, `ModelStore::has()` said no, and the tile
silently showed the fallback. Resolved all 83 from the installation's own
model blocks. **Drawable: 34 → 241 of 241.**

Two entries name a file holding several meshes (`path|submesh`); the
renderer draws a whole file, so `ModernCarWithDoors_Martin` lost its shell
among the doors and hood. Both now use the plain body model.

### Grouping by model name looked right and was wrong

Chasing the pickup-mask problem, the grouping was moved onto the model
name. That split one Step Van into five groups: the mesh files spell
damage as `SMASH_`, `CRASH_` **and** `Smashed`, and lighting as `Lights`,
so every spelling the suffix list missed became a group of its own.

Reverted to the paint mask, which has exactly **one** case where it is too
coarse — now a named exception in `SpawnableVehicles::SPLIT_MASKS`:
`vehicle_pickuptruck_mask` paints both the Chevalier D6 (`PickUpTruck`)
and the Dash Bulldriver (`PickUpVan`). **22 bodies from 241 entries.**

### One render served under three names

Three race cars share `vehicle_racecar.fbx` and differ only in their shell
texture. `cacheKey()` keyed on the model alone, so the first render was
served for all three. The map never showed it — a vehicle there is tinted
per instance — but the catalogue puts the variants side by side.

## The ground under the preview: geometry, not tuning

Four attempts, each made worse by adjustment and settled by arithmetic.
Recorded because the reasoning is not obvious:

- A tile pictures **one world square seen from this very angle**. The
  map's 2:1 projection is `sin(30°) = 0.5`, exactly the camera's
  elevation — so a flat square projects back to the diamond it was drawn
  as. Reasoning from `cos(30°) = 0.866` produced "impossible" and a
  camera-pinned backdrop the vehicle floated above.
- A plane maps its texture onto its **square**; the picture is a
  **diamond** with transparent corners. The two are 45° out of step,
  which showed as a chequerboard of holes. Turning each tile 45° about the
  vertical and growing it by `√2` puts the diamond's points on the
  square's edges.
- With yaw 225 the camera sits at **negative** x and z, so the far verge
  is the **positive** corner. Guessing put a bush in front of the vehicle.

Tiles extracted with the existing `SpritePack` reader:
`blends_street_01_0` and `blends_natural_01_16` from `Tiles2x.floor.pack`,
`f_bushes_1_78` from `Tiles2x.pack` (the low-numbered bushes are bare
winter twigs; the leafy ones start around 78). Written to
`backend/var/vehicle-models/` as `floor_street.png`, `floor_grass.png`,
`scenery_bush.png` — **outside git**, like every other game asset.

`draw()` takes `{ ground: true }`, opt-in: the map does not want it,
because the map *is* the ground. The option is part of the cache key.

## Vehicle specifications: resolved from the scripts

`VehicleSpecs.php`, generated, 241 entries: seats, trunk, glove box,
mass, top speed, engine and braking force, mechanic type, engine
loudness and quality.

The scripts state these across a chain: a vehicle inherits a base
template, that inherits part templates, and a part's capacity is either
set outright or implied by the item it is built from
(`itemType = Base.BigTrunk` → `MaxCapacity = 160`). Two resolutions were
not obvious:

- A **closed and an open truck bed are both defined for every vehicle**
  though it carries only one, so the larger is the one it really has.
  Taking the last match gave the Step Van 55 instead of 160.
- The **trailer parts every vehicle inherits** (`TrailerTrunk`,
  `TrailerAnimalFood`, `TrailerAnimalEggs`) appear on 220 of 241 and are
  ignored except on an actual trailer.

Measured: 241 masses, 221 seat counts, 220 trunks, 216 glove boxes. The
extremes match what a player would name — Step Van 160 (largest bed),
sports and race cars 120 km/h (fastest), van with seats 6 (most seats).
A police Nyala is the same 800 kg shell as the saloon but does 100 km/h
against 90, on 4800 engine force against 4000.

**Only stated values are shown.** A modded vehicle has none, and an unset
property takes an engine default the panel does not know.

## Vehicle types: from the mask, not from mechanicType

`VehicleTypes.php`, nine kinds: van 102, service 36, pickup 24, sports
21, wreck 20, car 13, small 10, suv 10, trailer 5.

Derived from the paint mask (the shell) plus the service words the game
itself puts in a script name — a cruiser is a saloon with different
paint, and somebody hunting for one thinks "service vehicle".
**Deliberately not `mechanicType`**: that grades repair difficulty (1
standard, 2 heavy, 3 sports), which files an ambulance beside a step van.

The frontend keeps its own `VEHICLE_TYPES` list to hold the order and the
typing, so `vehicles.test.ts` asserts it against `VehicleTypes::ORDER` by
reading the PHP source — **proven to fail** when the two are reordered
apart.

The filter narrows the ground the other steps work on, so it combines
with search, body and favourites rather than replacing them, and offers
only the kinds the server actually has (`typesPresent()`).

## Favourites, on both levels

Bodies and liveries are separately markable, kept apart in
`localStorage` per server (`zomboidcontrol.vehicleFavourites.<kind>.<id>`)
because a body id is not a script name. Two rules the user's testing
exposed:

- **A chosen body wins over the filter.** Choosing a shell is the request
  to see everything it comes in, so its plain liveries stay visible
  instead of the grid emptying under the cursor.
- **A body filtered out of the row cannot stay selected**, or the grid
  below shows liveries of a shell no longer on screen. Hence `reachable`
  in `vehicles-page.tsx`.

Filtering shows marked bodies **plus** bodies holding a marked livery, so
a favourite livery stays reachable when its body was never marked.

## Grids that stopped jumping

Three separate reflows, all reported by the user:

- The clear-selection button appears only once a body is chosen and is
  taller than the bare heading, so the row grew and pushed everything
  down. The row now carries that height from the start (`h-6`).
- Tiles were only as tall as their own content, so a two-line name left a
  gap under its one-line neighbours. The tile fills its grid cell
  (`h-full` on wrapper and button) and the member count is anchored with
  `mt-auto`.
- The page had no scrollbar gutter, so growing long enough to scroll
  narrowed the content and re-flowed every grid. `scrollbar-gutter: stable`
  on `html`.

Also: "Alle" beside the body heading read as a label for the row rather
than an action, so it now says `vehicles.clearBody` — "Auswahl aufheben".

## Copyable machine values

`components/ui/copyable.tsx`, one component replacing three ad-hoc
clipboard handlers. `Base.StepVan_LouisvilleSWAT` is pasted, not
retyped.

Used for the vehicle script, the item type and a SteamID. **The item type
was not shown at all** in the grid — it sat in a `title` attribute, which
a touch device never reveals — so it is now visible on the tile and in
the selection list.

In `player-detail.tsx` the SteamID moved out of `DialogDescription`: a
button inside a `<p>` is invalid HTML.

## The Indie Stone attribution

`/app/credits`, linked from the sidebar footer. The user established that
the terms permit the game's art in a non-commercial fan project **on
condition of a visible notice** — so the page is the condition the
permission rests on, not decoration.

Carries the wording the terms specify, left in English deliberately;
links to Project Zomboid and to the terms; sets out which content the
panel uses and where. Two things stated because they are easy to get
wrong: **mod content belongs to its authors** and needs their permission
separately, and **opening the map reveals the viewer's IP** to
projectzomboidmap.com.

`breadcrumbs.test.ts` gained a case for it: an unlisted page falls back to
the dashboard label, so a missing title would read as "Übersicht" and
nothing would complain.

## Commits

| Commit | What |
|---|---|
| `c46d9a6` | `docs:` the pre-compact record |
| `5eb7256` | `fix(vehicles):` tell the two pickups apart |
| `f5a13a2` | `feat(vehicles):` favourites on both levels |
| `d45d53e` | `fix(ui):` stop grids reflowing |
| `b818c73` | `fix(vehicles):` resolve the model indirection — 241/241 drawable |
| `65cc70d` | `fix(map):` texture in the cache key; opt-in ground |
| `73ca9f9` | `feat(ui):` `Copyable`, and show the hidden item type |
| `9e9c8d3` | `feat(vehicles):` `VehicleSpecs` from the scripts |
| `fdf6866` | `feat(vehicles):` facts card and type filter |
| `a6546d0` | `fix(map):` stand the vehicle on the road |
| `f3c910c` | `feat(panel):` the credits page |

## Verification at the end of this stretch

| | |
|---|---|
| Backend tests | **493, green** (was 468; +25 for types, specs and grouping) |
| Frontend tests | **205 across 18 files, green** (was 172) |
| Linter | **0 errors**, 29 warnings (all pre-existing; one was fixed) |
| Typecheck | clean |
| Build | clean, PWA generated, 82 precache entries |
| Pushed | `origin/main` at `f3c910c` |
| Checked in a browser | favourites on both levels, the type filter, the facts card, the road, the credits page, grid heights |
| Still not verified | a real spawn against the live server (needs a player online); Lighthouse |

Two process notes worth carrying forward, both now in CLAUDE.md:

- **Vite in ddev kept serving stale modules** twice — the tile without its
  star, then a 404 on a new route — while the file on disk was correct.
  Check what is served with `curl` before debugging the code; restart with
  `ddev dev`, since `pkill -f vite` does not reliably bring it back.
- **`ExtractIconsCommand`'s `SpritePack` reader parses the floor and tile
  packs too.** `SpritePack::parse()` takes bytes, `$pack->pages` and
  `$page->sprites` are public. `/tmp` inside the container is not the
  host's `/tmp`; stage files under `backend/var/tmp/`.

---

# TODO — the current list (supersedes the one at line 2128)

The older list is left in place as a record; this one is what to work
from. Ordered so each item is doable without the one after it.

## Done since that list was written

- [x] **`CATALOGUE_HEADING = 285`** — verified in a browser. The nose does
      face the viewer.
- [x] **Vehicle favourites, and filtering by them** — on both levels,
      bodies and liveries (`f5a13a2`).
- [x] **Frontend tests for `vehicles.ts`** — 28 of them, plus a drift guard
      against `VehicleTypes::ORDER`.
- [x] **Every vehicle drawn** — the model indirection resolved, 241/241
      (`b818c73`).
- [x] **Vehicle information and a type filter** (`9e9c8d3`, `fdf6866`).
- [x] **Copyable machine values** (`73ca9f9`).
- [x] **The Indie Stone attribution** (`f3c910c`), then translated with the
      terms' own wording kept as a foldout.
- [x] `representatives()` — still exported and still unused; see below.

## 1. Small things left over from the vehicle page

- [ ] **Verify a real spawn against the live server.** Needs a player
      online; never actually tried end to end.
- [ ] **`representatives()` in `vehicles.ts` is unused.** It has a test, so
      it is not dead weight by accident — decide whether the "no body
      chosen" state should show one per body (the plan's original shape)
      or stay empty as it does now, then keep or delete it.
- [ ] **The `set-state-in-effect` warnings.** 29 remain, all pre-existing.
      One was removed by consolidating the paging reset into the setters;
      the same treatment would fit several others.

## 2. Remove vehicle spawning from Events

Unchanged, and now the only thing keeping two spawn paths alive.

- [ ] Delete the entry from `EventCatalogue::players()` and delete
      `VehicleScripts.php`; the new controller already carries its own copy
      of `isValidName()`.
- [ ] `EventDispatcherTest`'s injection case (around line 98) **moves to
      the vehicle tests rather than being deleted** — the injection guard
      is why that test exists.
- [ ] Delete `events.actions.spawnVehicle.*` from both locales.

## 3. Events into five categories (plan phases 2–4)

The largest remaining restructuring, unchanged. Detail in the plan and in
the older list at line 2161; the essentials:

- [ ] `EventAction::GROUP_*` → `CATEGORY_WEATHER/SOUNDS/ACTIONS/ZOMBIES/WORLD`.
      **Backend and frontend in one commit** — the JSON key change breaks
      the frontend type otherwise.
- [ ] **Thunder → sounds, lightning → actions**, proven from the bytecode:
      `thunder` passes `(false, false, true)`, `lightning` `(false, true, true)`.
- [ ] **Merge the duplicated rain** with `CHANNEL_PREFERRED` (bridge first,
      RCON on `BridgeCommandFailed` only).
- [ ] Sidebar children on `SERVER_PAGES`; `isExactly` for the children.
- [ ] Layout route with an index, a static `weather` child, a `:category` child.
- [ ] Third breadcrumb level — `crumbsFor()` is extracted and tested, and
      now has a case proving an unlisted page falls back silently.
- [ ] Invert `EventDispatcherTest`'s skip to `=== CHANNEL_BRIDGE` (line ~154).
- [ ] Re-pin `EventsApiTest`'s `ModerationAction` assertion.

## 4. The weather page

Unchanged; see line 2186 for the detail. Icon presets, `WorldStrip` at the
top, slider rows behind a disclosure, the day arc for the world page,
actions as directly-actionable cards. Snow stays omitted.

## 5. Bridge 0.14 — needs an upload and a restart, announce it

- [ ] Report `fog`, `clouds`, `thunderstorm`, `precipitation` in the world
      state (`getFogIntensity()`, `getCloudIntensity()`,
      `getIsThunderStorming()`, `getPrecipitationIntensity()`).

## 6. The player dossier (plan phase 3)

Unchanged; full detail at line 2211. Capability research is done. The
points that decide the work: god mode, invisible, noclip, XP, voice ban,
SteamID ban and whitelist are **plain RCON**; **healing needs the
bridge**; build 42 replaced the `Stats` setters with
`set(CharacterStat, float)` and the 24 stats carry their own bounds; a
voice ban **does not persist**; **kill is unproven**; notes and tags need
a `PlayerNote` entity and a migration; the dossier log is filtered to the
selected player; and `new ModerationAction(...)` should go through one
recorder service while the dossier actions are added.

## 7. Deferred, agreed as separate plans

Unchanged from line 2244: Discord (the strongest reference feature, with
admin actions individually switchable and every message editable), Steam
Workshop and mod management, the server config editor, the scheduler,
statistics and charts, the notification bell, avatars with cropping,
Lighthouse measurement, wheels on the map renderer,
`ModerationAction`'s overloaded columns, route-level permission guards,
and the unexposed climate values.

Two additions to that list from this stretch:

- [ ] **`llms.txt` does not exist.** It was asked for alongside the
      security headers and never written. The headers are done
      (`SecurityHeadersSubscriber`); this is not.
- [ ] **A retention notice for the credits page.** The GDPR work (an
      operator-set retention horizon, per-player export and erase) is
      built, but nothing tells an operator where to find it. The credits
      page is the natural home for a short pointer.

## Verification at the end of this stretch

| | |
|---|---|
| Backend tests | **493, green** |
| Frontend tests | **207 across 19 files, green** |
| Linter | **0 errors**, 29 warnings (all pre-existing) |
| Typecheck | clean |
| Build | clean, PWA generated |
| Pushed | `origin/main` at `f3c910c`; the credits fixes are later and unpushed |
| Bridge on the server | **0.13.3** |
| Still not verified | a real spawn; Lighthouse |

One bug found by the user after the push, worth recording because the
cause is invisible in the source: **the sidebar link read
`to="/app/credits"` while the router is mounted with
`basename: '/app'`**, so the click went to `/app/app/credits` and no
route matched. Every other link in that file uses a bare path.
`app-sidebar.test.ts` now reads the basename out of `router.tsx` and
asserts no link repeats it — proven to fail against the original mistake.

---

# 2026-09-06 (evening) — Sidebar grouped, spawning taken out of Events

Working autonomously through the TODO list. Written per finished block
rather than at the end, per the new rule in CLAUDE.md section 0b.

## Sidebar grouped by what the operator is doing — `293e869`

One flat list of eight server pages put looking things up beside
intervening beside handing out content. Five bands now:

| Band | Pages |
|---|---|
| **Live** (`nav.sectionLive`) | Players, Chat, Console |
| **World** (`nav.sectionWorld`) | Events, Map |
| **Content** (`nav.sectionContent`) | Items, Vehicles |
| **Diagnostics** (`nav.sectionDiagnostics`) | Log |
| **Administration** | Settings, Users |

The band is a `section` field on `SERVER_PAGES` rather than an order in
the JSX, so adding a page decides its place in one edit. `pagesOf()` and
`SERVER_SECTIONS` drive the rendering; a section with nothing under it
draws no heading at all.

The log moved out of the middle of the old list deliberately: it is read
to find out why something happened, not to hand anything out. First
attempt filed it under Content, which was wrong for that reason.

`app-sidebar.test.ts` (new) asserts: every page has a section the sidebar
draws, every offered section has a page, every page is reachable from
exactly one section, and every section is labelled in both locales.

**Also renamed the event console to Events** — the first thing asked for
in this whole overhaul, and it had been missed until the user pointed at
it again. `nav.events` and `events.title` only; the `events.*` key
namespace is untouched.

## Vehicle spawning out of the event catalogue — `8749baa`

Spawning had two paths since it got its own page: the page called
`dispatch('spawnVehicle', ...)`, which looks the action up in
`EventCatalogue`, so the entry had to stay — and the events page kept
offering a dropdown of script names beside it.

`EventDispatcher::run($server, $actionId, $command)` is new: it sends a
command the caller has already built, so a page owning its own command
still gets the RCON plumbing and the `EventOutcome` shape.

Gone with the catalogue entry:

- `src/Server/Events/VehicleScripts.php` and its 17 hard-coded names
- `EventDispatcher::spawnVehicle()` and the match arm
- `'vehicles' => VehicleScripts::NAMES` on the events endpoint
- The `vehicles` field on the frontend `EventCatalogue` type, the prop
  threaded through `events-page.tsx` → `EventForm` → `FieldInput`, and
  the `action.id === 'spawnVehicle'` special case in the choice field
- `events.actions.spawnVehicle.*` in both locales

`FieldInput` lost its `action` prop as a consequence — the build caught
that (`TS6133`), not the linter.

**The injection guard moved rather than being deleted.**
`EventDispatcher::clean()` is now public, because a caller quoting its
own argument needs the same guarantee: a player named `bob" ; quit "`
must not become two commands. The controller applies it to the player
name; `NAME_PATTERN` already admitted no quote in the script.

`tests/Functional/VehicleSpawnTest.php` (new, 6 tests) pins the
endpoint's refusals over HTTP: a quote, a semicolon, an empty script, a
missing player, a permission-less role — and that a modded name
(`SomeMod.WhateverVan_02`) passes the shape check and fails later on RCON
(502) rather than being refused at the guard (422).

`EventDispatcherTest` keeps a `clean()` test in place of the two it lost,
so the guarantee stays pinned on both sides.

## `representatives()` deleted

It was exported, tested and unused — written for a "no body chosen" state
that shows one vehicle per body. The state that shipped instead says
"choose a body above", which is clearer than 22 example tiles, so the
function and its test are gone rather than left as a decision nobody
made.

## Verification

| | |
|---|---|
| Backend tests | **498, green** (was 493; +6 spawn, −1 moved) |
| Frontend tests | **205, green** across 19 files (211 before `representatives` and its test went) |
| Linter | 0 errors, 30 warnings (all pre-existing; `set-state-in-effect` in `events-page.tsx` is the one that moved into view) |
| Typecheck / build | clean |
| Checked in a browser | the grouped sidebar, with Events renamed |

---

# 2026-09-06 (evening, continued) — llms.txt, retention, five categories

## llms.txt, and AGENTS.md stopped drifting — `d45c48e`

Asked for alongside the security headers; the headers shipped and the
file never did. It describes what the panel is, how it reaches a game
server it does not sit on, and what that distance makes impossible.

**Deliberately in the repository rather than served.**
`backend/public/robots.txt` disallows everything, because an
administration panel belongs in no index and its login page should not
advertise that the installation exists — serving a description of it
would undo that. The file says so in its own second paragraph.

`tests/Unit/DocumentationTest.php` checks the figures a reader would take
on faith against what they describe: the vehicle count (241), the body
count the grouping produces (22), the bridge version that ships (0.13.3),
and that `backend/var/` is still ignored. Proven to fail when either
number is edited away.

**AGENTS.md became a pointer.** It was a copy of CLAUDE.md, had fallen
four sections and two hundred lines behind, and nobody noticed until the
two were diffed. A stale copy of the rules is worse than no copy.

## The retention horizon is settable — `b131fc5`, `a7b7079`

`StalePlayerPurger` read `players.retention_days`; nothing could write
it. Not in `SettingsController::EDITABLE`, not on any endpoint, not in
any interface. So the purge ran daily, did nothing, and said nothing.

Now a **Retention** tab under a **Data protection** group, with the
credits page pointing at it. Off stays the default and a valid answer.

**Below seven days the endpoint refuses** rather than accepting:
`RETENTION_MINIMUM_DAYS = 7`, because a horizon that short catches
players who are merely on holiday, and a control that deletes things
should not read a mistake as an instruction. The frontend shows the same
limit inline; the backend is the one that enforces it.

The card states what goes — name, SteamID, position, health, skills —
and that the ban list and moderation log do not.

**The tab strip became a vertical rail.** It had wrapped: a group label
fitting at the end of a row left its first tab stranded on the next one,
reading as a heading for the group above it. Two attempts made it worse
— shortening the label only hid it, and `w-full` on the label to force a
break broke the flex layout outright, overlapping the tabs onto the
card. A rail beside the cards solves the shape: groups read as sections,
a new category lengthens the list, and the labels can say what they mean
again. The save row moved inside the content column.

## Events into five categories — `46adf8c`

`group` → `category`, four values → five:

| Category | Holds |
|---|---|
| `weather` | startRain, stopRain, startStorm, stopWeather, setFog, setWind, setTemperature, setClouds |
| `sounds` | **thunder**, gunshot, alarm, soundAtPlayer, soundAtPoint |
| `actions` | **lightning**, chopper, broadcast |
| `zombies` | hordeNearPlayer, hordeAtPoint, removeZombies |
| `world` | setTime, setDate, setDaylight, setViewDistance |

**Thunder and lightning are placed by the game, not by taste.** Both
call `transmitServerTriggerLightning(x, y, doStrike, doLightning,
doRumble)`; the bytecode has thunder passing `(false, false, true)` — the
rolling sound alone — against lightning's `(false, true, true)`. So
thunder is a sound and lightning is a staged one-shot.

**Zombies is a fifth category rather than a fourth**, which lets
"actions" mean something: nothing there can wreck a server, everything
in zombies can. `EventCatalogueTest` pins that only the zombie actions
carry `destructive`.

**Rain is one action again.** `bridgeStartRain`/`bridgeStopRain` deleted;
`startRain`/`stopRain` gained `CHANNEL_PREFERRED`, handled by
`EventController::preferringTheBridge()`: the bridge first, RCON on
`BridgeCommandFailed` only. **Not** on `InvalidBridgeCommand` — that
means the panel built the request wrongly, which RCON would not fix and
which must not be hidden. The list shows a Bridge badge only for
bridge-*only* actions, since a preferred one always works.

`GROUP_ORDER` in the frontend is gone — it was the duplicate table the
plan wanted removed. `EVENT_CATEGORIES` replaces it and a drift guard in
`events.test.ts` asserts it against `EventAction::CATEGORIES` by reading
the PHP source, plus that every category has an icon and a label in both
locales. Proven to fail when the two lists are reordered apart.

**Two tests would have silently lost coverage**, and were corrected
rather than left:

- `EventDispatcherTest`'s catalogue walk skipped `!== CHANNEL_RCON`,
  which now also skips `CHANNEL_PREFERRED` — so the merged rain would
  never have been checked. It skips only `CHANNEL_BRIDGE`.
- `EventConsoleTest` gained `testRainFallsBackToRconWithoutABridge`,
  proving the fallback runs: the fixture has no bridge, and
  `startrain 70` lands in the moderation log.

## Verification

| | |
|---|---|
| Backend tests | **516, green** (was 498; +8 catalogue, +5 retention, +4 documentation, +1 rain fallback) |
| Frontend tests | **207, green** across 19 files |
| Linter | 0 errors, 29 warnings (all pre-existing) |
| Typecheck / build | clean |
| Pushed | `origin/main` |
| Checked in a browser | the grouped sidebar, the retention tab and its refusal, the settings rail, the five event categories, "Bridge, sonst RCON" |

## What is left of the events restructuring

The categories exist; the **pages** do not. Still open from plan phase 4:

- [ ] Sidebar children under Events — a `children` field on
      `SERVER_PAGES`, `isExactly` for exact matching on the children with
      the prefix match kept for the parent.
- [ ] The layout route with an index, a static `weather` child and a
      `:category` child. Static segments match before dynamic ones, so
      adding a category later is one line in the catalogue.
- [ ] The third breadcrumb level. `crumbsFor()` is extracted and tested,
      and now has a case proving an unlisted page falls back silently.
- [ ] The weather page itself: icon presets, `WorldStrip` at the top,
      slider rows, the day arc for the world page, actions as
      directly-actionable cards.

---

# 2026-09-06 (evening) — Event categories became pages — `a2a3243`

The categories existed as data since `46adf8c`; now each is a page.

## The sidebar sub-level

`SERVER_PAGES` gained `children?: ServerChildPage[]`, and `EVENT_CHILDREN`
lists the five with the catalogue's own labels
(`events.categories.<id>`), so **one string serves the nav entry, the
breadcrumb and the page heading**. A child carries no permission of its
own: there is one event permission and the parent's filter already gates
the subtree.

**Built from shadcn's own arrangement rather than by hand**, on the
user's prompt: `Collapsible` wrapping `SidebarMenuItem`, with
`SidebarMenuAction` as the chevron trigger. Two things that matters for:

- **The label still navigates and the chevron still toggles** — two jobs,
  two controls. A label that only opened a list would take the overview
  away.
- `SidebarMenuAction` already carries
  `group-data-[collapsible=icon]:hidden`, so the icon rail needed no
  special case. Same for `SidebarMenuSub`.

`defaultOpen={isActive(base)}` opens it on arrival when a child is
showing, so a deep link does not land with its own entry hidden.

A first attempt showed the children only while the parent was active,
with no chevron and no way to collapse — the user pointed out that shadcn
had already solved it.

## One page, not five

`EventsPage` takes an optional `only?: EventCategory`. The search, the
form, the trigger and the confirmation are identical across categories;
only the listed actions and the heading differ. `CategoryPage` reads the
`:category` segment and **redirects to the overview when it is not a
category** — an empty list would read as "this category has nothing"
rather than "there is no such category".

Route: `servers/:id/events/:category`. A static segment matches before a
dynamic one, so a category that later wants a page of its own (weather,
with presets and a live band) is added above this line without touching
the catalogue.

## Exact matching, and the third breadcrumb

`isExactly(path)` sits beside `isActive(path)`: a child needs exact
matching or `/events/weather` lights up `/events` too, while the parent
keeps its prefix match so it stays lit while a child is open.

`crumbsFor()` reads three levels and **refuses to grow a third from a
stray path** — a page with no children, or a fourth segment naming no
known child, still reads two. Both pinned.

## Tests

- `breadcrumbs.test.ts`: three levels on a category; a fourth segment
  ignored under a childless page; an unknown child ignored.
- `app-sidebar.test.ts`: every child labelled in both locales, and every
  page with children served by a route in `router.tsx` — so a child
  cannot be added to the nav without being reachable.
- `events.test.ts`: the category drift guard against
  `EventAction::CATEGORIES`, proven to fail when the two are reordered
  apart.

## Verification

| | |
|---|---|
| Backend tests | **516, green** |
| Frontend tests | **213, green** across 19 files |
| Linter | 0 errors, 29 warnings (all pre-existing) |
| Typecheck / build | clean |
| Pushed | `origin/main` at `a2a3243` |
| Checked in a browser | the chevron opening and closing, the five children, "Wetter" active, the breadcrumb reading Server › Events › Wetter, the page showing only its eight weather actions |

## Still open on the events work

- [ ] **The weather page proper**: icon presets (frontend bundles of
      existing actions fired sequentially with one toast), `WorldStrip`
      at the top for the live state, slider rows behind a disclosure for
      fog/clouds/wind/temperature. Icons verified present in lucide:
      `Sun`, `Cloudy`, `CloudDrizzle`, `CloudRain`, `CloudRainWind`,
      `CloudLightning`, `CloudFog`, `SunDim`.
- [ ] **The day arc** for the world page — chosen over an analogue clock,
      which is ambiguous across 24 hours.
- [ ] **Actions as large directly-actionable cards** rather than
      list-and-detail: with three entries, select-then-fill is friction.
- [ ] **Bridge 0.14** for fog, clouds, thunderstorm and precipitation in
      the world state. **Needs an upload and a game-server restart.**

---

# 2026-09-06 (late evening) — Weather page, connection lights, bridge 0.15

Written before a compact. Everything below is committed and pushed
(`origin/main` at `56082ec`).

## The weather page — `df3a03e`

`features/events/weather-page.tsx`, reached at
`servers/:id/events/weather` — a static route listed **above** the
generic `:category` one, which is why it takes precedence.

**Choosing a preset does not fire it.** It opens the steps it is about to
take with their values, so "rain at 100, clouds at 100, wind at 85" is
readable before anything happens and each number can be changed, then
one button fires them. Fired **sequentially**, because the game applies
these to one world and "stop the weather, then set the clouds" only
means what it says in that order.

Presets live in `weather-presets.ts` as **frontend bundles of existing
catalogue actions** — they add no capability and cannot drift from the
backend. Eight: clear, cloudy, drizzle, rain, downpour, thunderstorm,
fog, wind.

**Clear resets everything**, not only the precipitation: `stopWeather`
ends the rain and leaves the wind, clouds and fog where the last
downpour put them, which is not clear. Pinned by a test that checks all
three dials go to 0.

`WorldStrip` sits at the top as the live state — the reference panel has
neither icons nor any live reading, so it presses "start rain" and hopes.

A bounded number field gets a **slider beside its number**, here and in
the generic `event-form.tsx`. `components/ui/slider.tsx` is new, a
wrapper over `radix-ui`, which was already a dependency.

## Wind now speaks km/h — `56082ec`

The control set a 0..100 climate value while the strip displayed km/h,
so asking for 100 showed 120. Fixed properly rather than relabelled:

- `getMaxWindspeedKph()` exists in the game. Bridge 0.15 reports it as
  `maxWindSpeed`; it measured **120** on the live server.
- `EventCatalogue::MAX_WIND_KPH = 120`, and `setWind`'s field declares
  `(0, MAX_WIND_KPH, 40)`.
- `EventController::climateValue()` divides by that ceiling for
  `setWind`, by 100 for the other climate values, and not at all for
  temperature.
- **Measured: asked 95, read back 95.**

The preset wind values were converted with it (85 for downpour, 95 for
the wind preset), and `weather-presets.test.ts` learnt to resolve a
named bound (`self::MAX_WIND_KPH`) from its own declaration.

## Four connection lights — `56082ec`

`features/servers/connection-lights.tsx` in the header, over the new
read-only `GET /api/servers/{id}/connections`
(`ConnectionStatusEndpoint`).

| Light | How it is decided |
|---|---|
| **Server** | The bridge's own `generatedAt`, fresh within 35 s |
| **RCON** | `RconClientInterface::probe()` |
| **FTP** | `FileBrowserInterface::listDirectory()` |
| **Bridge** | `BridgeInstaller::status()`; installed-but-behind is `stale`, not `down` |

Decisions worth keeping:

- **A read-only endpoint, not the existing tests.** `ftp/test` and
  `rcon/test` are POST and record a verification on the entity; a light
  polling every fifteen seconds must not write to the database.
- **`unconfigured` is not red.** Nothing is broken, it was never set up,
  and a light that cannot tell those apart sends somebody hunting for a
  fault.
- **"Server running" comes from the bridge, not from RCON.** RCON
  answering proves the server is up, but the reverse does not follow and
  without RCON configured there would be nothing to go on. The bridge
  writes from inside the running game.
- **Polling is 15 s** because the endpoint measured **~860 ms**: it opens
  an FTP connection and an RCON socket.
- **Each light is a button** linking at its own settings —
  `settingsFor()` maps to `?tab=ftp`, `?tab=rcon`, `#bridge`. The server
  detail page's tab now lives in the URL (`useSearchParams`) so a link
  can address it. First attempt used underlined links, which the user
  rightly called ugly.
- Desktop shows all four labelled; below `md` one summary dot
  (`worstOf()`) opens a menu.

## Bridge 0.14 → 0.15

**0.14** split the slow write in two. Time and weather are two object
reads (`getGameTime`, `getClimateManager`) and were on the same
sixty-second interval as three list walks, one covering every vehicle in
the world:

- `SECONDS_BETWEEN_WORLD_WRITES = 10` — time and weather
- `SECONDS_BETWEEN_SLOW_WRITES = 60` — safehouses, vehicles, factions

**Measured after the restart: exactly 10 seconds between readings.**
`world-strip.tsx` polls at 15 s to match, and a drift guard in
`weather-presets.test.ts` asserts the page's mirrored constant against
the Lua's own.

**0.15** added what the climate page will need — all four verified
present in the game's API before writing them:

| Handler | Game call | Note |
|---|---|---|
| `setSnow` | `setPrecipitationIsSnow(boolean)` | **Reads back**: the game refuses snow outside a cold season, so it reports what it actually applied plus the temperature |
| `startBlizzard` | `triggerWinterIsComingStorm()` | The game's own winter storm, which sets precipitation, wind and temperature together |
| `stopWeather` | `stopWeatherAndThunder()` | RCON's `stopweather` leaves the thunder running |
| `readClimate` | `getClimateFloat(0..12)` | All thirteen values with `getFinalValue`, `isEnableAdmin`, `getAdminValue`, plus wind speed, its ceiling, and the raining/snowing flags |

`BridgeCommandCoverageTest` is new and asserts **both directions**: every
`BridgeCommand` has a `handlers.<name> = function` in the Lua, and every
handler has a command. The two are separate files uploaded by hand, so a
mismatch otherwise surfaces live as "unknown action". Proven to fail
against a command with no handler.

**Bridge 0.15.0 is uploaded and the server restarted** — verified:
`installedVersion: 0.15.0`, `upToDate: true`, all four lights green.

## Everything else this stretch

| Commit | What |
|---|---|
| `293e869` | Sidebar in five bands; event console renamed to **Events** |
| `8749baa` | Vehicle spawning out of the event catalogue; `VehicleScripts.php` deleted; `EventDispatcher::run()` added; injection guard moved to `VehicleSpawnTest` |
| `038f7b5` | CLAUDE.md 0b — write CONTEXT.md per block, and what is not possible; `representatives()` deleted |
| `d45c48e` | `llms.txt` written; `AGENTS.md` became a pointer; `DocumentationTest` |
| `b131fc5`, `a7b7079` | Retention horizon settable, minimum 7 days; settings tabs became a vertical rail |
| `46adf8c` | Events into five categories; rain merged onto `CHANNEL_PREFERRED` |
| `a2a3243` | Each category a page; sidebar children via `Collapsible` + `SidebarMenuAction` |

## Verification at the end of this stretch

| | |
|---|---|
| Backend tests | **519, green** |
| Frontend tests | **222, green** across 20 files |
| Lua checks | **25, green**; `luac -p` clean |
| Linter | 0 errors, 29 warnings (all pre-existing) |
| Typecheck / build | clean |
| Pushed | `origin/main` at `56082ec` |
| Bridge on the server | **0.15.0**, matching what ships |
| Verified live | bridge writes at 10 s; wind 95 asked → 95 read; all four lights green; the weather page, its presets and the step editor in a browser |
| Still not verified | a real vehicle spawn (needs a player online); Lighthouse |

---

## Snow that stays snow, the full climate surface, and a status bar (2026-09-06)

The user's order was snow and a blizzard first, then all the climate
settings. Both landed, plus five things they asked for while it ran.

### Snow: the setter was a lie, and the bytecode said so

**The symptom the user reported**: `-6 °C und es regnet. Aber eigentlich
sollte es jetzt schneien.` The panel had reported success.

**The cause, read from `javap -p -c` against the installation** at
`/Volumes/ESD-USB/ProjectZomboid/projectzomboid.jar`:

```
public void setPrecipitationIsSnow(boolean);
  0: aload_0
  1: getfield  precipitationIsSnow:ClimateManager$ClimateBool
  4: iload_1
  5: putfield  ClimateManager$ClimateBool.finalValue:Z   <-- and nothing else
  8: return

public boolean getPrecipitationIsSnow();
  ... getfield ClimateManager$ClimateBool.finalValue:Z   <-- the same field
```

So `setPrecipitationIsSnow` writes **only `finalValue`**, and
`getPrecipitationIsSnow` reads **that same field** back. The bridge's
read-back therefore only ever proved its own write had happened — never
that the game kept it. `calculate()` recomputes `finalValue` from the
season on its next tick and the snow becomes rain again.

**The fix**: the admin override on `ClimateBool[0]`, which is the route
the game's own admin console takes (`ISAdmPanelClimate.lua:418`).
`setEnableAdmin(true)` + `setAdminValue(snowing)`, then
`setPrecipitationIsSnow` as well so the *current* tick shows it. The
read-back is now `getAdminValue()`, which is a different field from the
one just written and therefore means something.

**Measured live**: `Schnee` at `-5,0 °C` on the world strip, where the
same temperature had read `Regen` before. Verified in a browser after
the user restarted with 0.17.

### The presets know what snow needs

- **Cool first.** The game refuses snow above freezing, so `setSnow`
  before a temperature step is a step asking to be refused. Order is
  declared and asserted: `cools below freezing before it asks for snow`.
- **The slider cannot undo its own preset.** The user: *"beim
  wetterevent schnee ist es quatsch wenn man die temperatur auf über
  0 °C stellen kann."* A `PresetStep` may now carry `min`/`max` that
  **narrow** the field's range, never widen it — the action still
  declares what the server accepts. Snow caps at `-1`.
- **Clear releases rather than sets.** Only the game knows what July is,
  so `releaseTemperature` lifts the pin (`ClimateFloat::setEnableAdmin(false)`)
  instead of guessing a number. Asserted, including that clear does
  **not** contain `setTemperature`.
- **A step can rename itself.** `startRain` reads wrongly under a snow
  preset, so a step may carry `label` → `events.stepLabels.startSnow`
  ("Schnee starten"). The user asked for exactly this wording.
- **A toggle step is stated, not offered.** On the snow preset the
  precipitation type *is* what "snow" means, so a switch able to turn it
  back to rain would undo the preset just chosen. The row reads
  "Niederschlag fällt als Schnee" via `events.toggleStates.setSnow.true`.
  The `toggle` field type stays in the catalogue — the climate page needs
  it — but `weather-page.tsx` renders a preset step as a statement.

### Bridge 0.17.0: everything the climate exposes

Erhebung per `javap` (subagent, `zombie/iso/weather/*`), not assumed.
What it corrected or added:

| Finding | Consequence |
|---|---|
| Temperature is **−80…80 °C**, not −30…40 | `CLIMATE_BOUNDS[4]`; the weather page keeps the narrower `WEATHER_MIN/MAX_CELSIUS` because a preset is a plausible day |
| `windAngle` is **−1…1**, `viewDistance` **0…100**, the rest 0…1 | `BridgeCommand::CLIMATE_BOUNDS`; `setAdminValue` **clamps silently**, so an out-of-range value would be applied as something else and reported as success |
| `getClimateFloat(int)` only — **no name lookup** | the name↔index pairing lives in `CLIMATE_FLOATS` (Lua) and `CLIMATE_VALUES` (PHP) |
| `FLOAT_MAX = 13` is a sentinel; indices are 0…12 | the project's assumed list was **correct** |
| One `ClimateBool`: `BOOL_IS_SNOW = 0` | `setSnow`, `releaseSnow` |
| Two `ClimateColor`: `COLOR_GLOBAL_LIGHT = 0`, `COLOR_NEW_FOG = 1` | recorded in `CLIMATE_COLOURS`, **not yet exposed** |
| `triggerCustomWeatherStage(stage, hours)` is what the game's own admin panel calls | `triggerWeatherStage`, 8 named stages, 1–240 h |
| `triggerCustomWeather(strength, warm)` lets the simulation decide | `generateWeather`, strength 10–100 %, warm/cold front |
| Every `ClimateFloat` has `getMin()`/`getMax()` | `readClimate` reports them, so the climate page needs no hard-coded table |

New Lua handlers in **0.17.0** (was 0.15.0):
`releaseClimate`, `resetClimate` (the game's own `resetAdmin()`),
`releaseSnow`, `triggerWeatherStage`, `generateWeather`; `readClimate`
extended with per-float `index`/`min`/`max`, the snow bool's own override
state, `thunderStorming`, `season`, `seasonProgression`, `airMass`,
`frontStrength`; `setSnow` rewritten as above.

**One load-time trap avoided**: `WeatherPeriod.STAGE_*` at module level
would run when the mod loads, and a missing class there takes the whole
bridge down rather than one handler. `stageNumber()` reads it inside a
`pcall` and falls back to the numbers `javap` reports (storm 3,
blizzard 7, tropical 8).

**`stopWeather` moved to `CHANNEL_PREFERRED`** — the bridge's
`stopWeatherAndThunder()` also stops the thunder, which RCON's
`stopweather` leaves running.

### Units come from the field

Section 10e's open item, closed. `EventField` gained `unit` plus
`UNIT_PERCENT/KPH/CELSIUS/HOURS/TILES/COUNT` and a `percent()`
shorthand; `EventCatalogue` states it once per field;
`frontend/src/features/events/units.ts` prints it.

Two corrections the user made while it landed:

- **"jetzt stehen die einheiten hier doppelt"** — it was on the range
  *and* the number. Now on the range only, which is the label that
  explains; the input carries it in its `aria-label`.
- **"Schreib bitte -30 °C - 40 °C"** — `-30–40` is unreadable, because
  the range dash and the minus are the same stroke. `formatRange` puts
  the unit on **both ends** when the minimum is negative, and keeps the
  compact form otherwise.

`events.range` is deleted: with the unit inside the formatted string the
key held only `{{range}}`.

### Weather presets in four groups

The user: *"können wir die wetterereignisse noch ein bisschen
kategorisieren?"* `PRESET_GROUPS = ['calm','rain','snow','air']`, each
preset declares one, `presetsOf()` selects. Asserted: every preset in a
declared group, no group empty, both locales label all four. The gap
between groups is wider than inside one (`pt-2 first:pt-0` over
`space-y-1.5`) after *"lass zwischen den gruppen ein klein wenig
abstand"*.

### A status bar under the page

The user's own diagnosis of where things sat: the version and the credits
link at the bottom of the sidebar, the four lights crowded into the top
bar. All three are facts, so all three moved to
`frontend/src/components/layout/app-footer.tsx` — `h-9`, deliberately
shorter than the `h-14` header, on the user's instruction (*"nicht so
hoch werden wie die top-bar"*). Left: `Panel v1.0.0`, a quiet divider
(`h-3 bg-border/50`), `Bridge v0.17.0`, another divider, Danksagungen as
a **button**. Right: the four lights at `h-6`.

- `PanelVersionLine` lost its sidebar-specific classes and gained
  `labelled`, so the label cannot outlive the value it names.
- `BridgeVersionLine` (new) reads the connections endpoint the lights
  already poll, so it costs no request.
- `CreditsContent` (new) is the attribution extracted once; the page and
  the new `CreditsDialog` both render it. **The page stays**: The Indie
  Stone's terms ask for a visible notice and a dialog cannot be linked
  to, so the dialog carries a link to `/credits`.

### The bridge light was lying, and the endpoint had no test

Spotted on the live page: the footer read `Bridge v0.17.0` while the
server was still running 0.15.0. `BridgeInstaller::status()` compares the
**file on disk** against what the panel ships — but the mod loads at
server start, so an upload changes the file and nothing else. The light
said "up to date" at exactly the moment it exists to warn.

`ServerInfoReader` was already discarding the `bridgeVersion` the running
bridge writes into its own output. It keeps it now, and
`BridgeVersionVerdict` (new, pure, 5 tests) decides between three
versions: running ≠ on disk → `bridge.restartNeeded`; otherwise current
vs. available. Proven by reverting to the old comparison: 1 failure.

**And the endpoint had no test at all.** A refactor deleted
`ConnectionStatusEndpoint::game()` and all 529 tests stayed green while
the browser got a 500 (`Attempted to call an undefined method named
"game"`). `ConnectionStatusTest` (new, 6 tests) asks for the response;
deleting that method again fails 4 of its 6.

Also cost 15 minutes: after adding a class to that controller the dev
environment kept returning 500 until `php bin/console cache:clear`.

### Files

| File | Change |
|---|---|
| `backend/resources/bridge/ZomboidControlBridge.lua` | **0.17.0**; `setSnow` rewritten, `releaseClimate`, `resetClimate`, `releaseSnow`, `triggerWeatherStage`, `generateWeather`, `readClimate` extended, `CLIMATE_FLOATS`, `stageNumber()` |
| `backend/src/Server/Bridge/BridgeCommand.php` | `ReleaseClimate`, `ResetClimate`, `ReleaseSnow`, `TriggerWeatherStage`, `GenerateWeather`; `CLIMATE_BOUNDS`, `CLIMATE_BOOL_IS_SNOW`, `CLIMATE_COLOURS`, `WEATHER_STAGES`, `MAX_STAGE_HOURS`; `climateValue()`, `stage()` |
| `backend/src/Server/Bridge/BridgeVersionVerdict.php` | **new** — running vs. on disk vs. shipped |
| `backend/src/Server/Bridge/ServerInfoReader.php` | keeps `bridgeVersion` |
| `backend/src/Controller/Api/ConnectionStatusEndpoint.php` | judges by the running bridge; reports `installedVersion` too |
| `backend/src/Server/Events/EventField.php` | `unit`, six unit constants, `percent()`, `toggle()` |
| `backend/src/Server/Events/EventCatalogue.php` | `setSnow`, `startBlizzard`, `releaseSnow`, `releaseTemperature`, `triggerWeatherStage`, `generateWeather`; units on every bounded number; `WEATHER_MIN/MAX_CELSIUS` |
| `backend/src/Controller/Api/EventController.php` | maps all six; `stopWeather` through the bridge |
| `frontend/src/features/events/units.ts` | **new** — `withUnit`, `formatRange` |
| `frontend/src/features/events/weather-presets.ts` | groups, `snow`, `blizzard`, `label`, `min`/`max` per step |
| `frontend/src/features/events/weather-page.tsx` | groups, per-step refusal, toggle as a statement, narrowed bounds, `w-fit min-w-full xl:min-w-3xl` |
| `frontend/src/features/events/event-form.tsx` | `toggle` branch, units |
| `frontend/src/components/layout/app-footer.tsx` | **new** |
| `frontend/src/components/layout/bridge-version-line.tsx` | **new** |
| `frontend/src/features/panel/credits-content.tsx`, `credits-dialog.tsx` | **new** |
| `backend/tests/Functional/ConnectionStatusTest.php` | **new**, 6 |
| `backend/tests/Unit/Server/Bridge/BridgeVersionVerdictTest.php` | **new**, 5 |

### Verification

| What | State |
|---|---|
| Backend | **535 tests, 5268 assertions green** |
| Frontend | **228 tests, 20 files green** |
| Lua | `luac -p` clean |
| Lint | 0 errors, 29 pre-existing warnings |
| Typecheck, build | clean |
| **Snow on the live server** | **verified — `Schnee` at `-5,0 °C`, bridge 0.17.0 running** |
| The footer, the groups, the units, the labels | **seen in a browser** |
| Guards proven to fail when reverted | unit guard, the verdict, `game()` deletion |
| Not verified | `triggerWeatherStage`, `generateWeather`, `resetClimate`, `releaseClimate`, `releaseSnow` — all shipped in 0.17.0 and **never fired**; the climate page they exist for is next |

---

## The remaining event pages, and a season nobody could read (2026-09-06)

### The climate page shipped with an untranslated season

`Early Summer` stood on the climate page while the world strip beside it
read `Frühsommer` — the same fact in two languages on one screen. Cause:
`seasonKey()` was private to `world-strip.tsx`, so the climate page had
nothing to call and printed the game's own wording.

Now `frontend/src/features/servers/seasons.ts`, used by both.
`seasons.test.ts` asserts all twelve seasons resolve in both locales
**and** that every page showing one calls `seasonKey` — restoring the raw
value fails it by filename. Verified live: `Frühsommer`.

### Actions as cards that do their own work

Three entries (lightning, chopper, broadcast), so the generic
list-and-detail page made each a two-step: pick it, then fill a form that
is usually empty. `actions-page.tsx` gives each its own card, shaped by
what it needs:

- **Lightning** — an optional player chooser, because the game picks one
  at random when nothing is named (placeholder: "Zufälliger Spieler").
- **Chopper** — a single click.
- **Broadcast** — its own field with a character counter, because that
  field *is* the action; Enter fires it, and an empty one disables the
  button.

No generic form on this page on purpose: three actions do not need one,
and the form is what made it a two-step. The channel is a footnote
(`RCON`) rather than a choice. Route is static above `:category`.

**Verified live**: the chopper answered `Chopper launched`.

### The day as a band, with the daylight measured

`world-page.tsx` plus `day-arc.tsx`. An hour field asks somebody to
translate "make it dusk" into a number; the band does not — midnight to
midnight, the server's own hour marked, four quick picks (dawn 06, noon
12, dusk 18, midnight 00). Chosen over a clock face, which cannot tell
noon from midnight, the one distinction that matters when setting a time.

**The shading is the honest part, and it cost a check.** There is **no
sunrise or sunset constant in the game** — grepped `media/lua/` (only
place names and voice lines) and `javap`'d `ClimateManager` (only
`FLOAT_DAYLIGHT_STRENGTH = 11` and `FLOAT_NIGHT_STRENGTH = 2`; daylight
is a simulated value that moves with the season). So a fixed dawn at
06:00 would picture a day the server is not having. The band takes the
**measured** daylight from `readClimate` when there is one, and shows the
hours plainly when there is not.

Daylight and view distance sit beneath the arc because the two interact —
set 03:00 and nothing is visible until daylight is raised — and each says
what the server runs **now** ("derzeit 88 %"), not only what it could be
set to.

`DAY_MARKS` lives in `day-marks.ts` rather than beside the component: a
file exporting both a component and a constant breaks fast refresh, which
oxlint caught.

**Verified live**: dusk → `time set` → the arc read `18:12` on the next
poll. The full round trip.

### The Claude Design attempt, abandoned — with one finding worth keeping

Asked for, started, then called off by the user; `design/` and its four
files were removed and nothing was committed. One finding survives and
will matter again:

**React ships no UMD build from 19 onwards.** cdnjs serves 18.3.1 and
404s on 19. Anything loading this panel's components outside the app must
therefore bundle React rather than expect `window.React` — otherwise
every `forwardRef` fails at load with "forwardRef is not a function".
Two further traps found the same way: a Vite **library** build sets no
`define` values, so a dependency reading `process.env.NODE_ENV` throws
and the whole UMD factory aborts *before assigning a single export*,
leaving an empty global that looks like a build problem; and
`export * from 'lucide-react'` pulled every one of its icons in, taking
the bundle from 345 kB to 1.18 MB.

### Files

| File | Change |
|---|---|
| `frontend/src/features/servers/seasons.ts` | **new** — shared `seasonKey` |
| `frontend/src/features/servers/seasons.test.ts` | **new**, 3 |
| `frontend/src/features/servers/world-strip.tsx` | imports it instead of owning it |
| `frontend/src/features/events/climate-page.tsx` | translates the season |
| `frontend/src/features/events/actions-page.tsx` | **new** |
| `frontend/src/features/events/world-page.tsx` | **new** |
| `frontend/src/features/events/day-arc.tsx` | **new** |
| `frontend/src/features/events/day-marks.ts` | **new** |
| `frontend/src/features/events/day-marks.test.ts` | **new**, 3 |
| `frontend/src/routes/router.tsx` | `events/actions` and `events/world` static above `:category` |

### Verification

| What | State |
|---|---|
| Backend | **542 tests green** (unchanged this stretch) |
| Frontend | **248 tests, 23 files green** |
| Lint | 0 errors, 29 warnings (unchanged) |
| Typecheck, build | clean |
| Live | chopper fired; the clock set to dusk and read back as 18:12; the season reads Frühsommer |

---

## Power, water, one moderation recorder, and the abilities (2026-09-06)

### Bridge 0.18.0 fired against the live server, and 0.18.1 fixes what it found

The habit from the snow bug held: every new handler was fired at the
running server before anything was built on it.

| Handler | Result |
|---|---|
| `readClimateColours`, `setClimateColour`, `releaseClimateColour` | **work** — RGBA indoors and out |
| `strikeLightning` | **works** — struck at 10778,9770 |
| `healPlayer`, `readPlayerStats` | refuse an absent player rather than throwing; the rest needs somebody online |
| `readUtilities`, `setUtility` | **failed** — see below |

**The failure**: `attempted index: getValueAsObject of non-table: null`.
`elecShutModifier` is a public *field* on `SandboxOptions`, and **Lua
cannot index a Java field** — it gets null. The methods work, so 0.18.1
goes through `getElecShutModifier()` to read and
`SandboxOptions::set(String, Object)` to write, with the option names
from the constant pool: **`ElecShutModifier`**, capital E, not the
field's spelling.

That is the fourth trap of this kind (after `ClimateBool::getFinalValue`,
`Color::getA` and `ORDERED_STATS`), and the pattern is now clear enough
to be a rule: **a public field is not reachable from Lua; only methods
are.** Recorded in CLAUDE.md.

### Power and water, which the game has no switch for

The operator asked for toggles. There is no on/off flag: from the game's
own code (`ISVehicleMenu.lua:1089`) a utility runs while

```
getWorldAgeHours() / 24 + (getTimeSinceApo() - 1) * 30  <  <its>ShutModifier
```

So **off** sets that day to today's floor and **on** sets `-1`, the
game's own "never shuts off" — and both read back. The panel says which
state it is in *and* how many days are left, because "on" cannot say
until when; a countdown appears only where there is one, never as
"−4 days remaining".

`UtilityReading` (+6 tests) owns that arithmetic, `UtilityEndpoint`
serves it read-only under `ViewServers`, and switching goes through the
event catalogue as `setPower`/`setWater` under `TriggerEvents` — looking
is not setting.

### One moderation recorder

Nine `new ModerationAction(...)` literals across eight files became one
`ModerationRecorder`, ahead of the dossier adding several more and a
notification feed later wanting to see every action go past.

**Two shapes, because the callers differ.** `record()` persists and
flushes, which is what a controller wants after acting. `add()` only
queues: `ExpiredBanLifter` and `RosterWatcher` flush **once at the end**
(`:70` and `:52`), and turning their single transaction into one per row
would have been a regression nobody notices until a busy server slows
down. That distinction was checked in the sources, not assumed.

`ModerationRecorderTest` walks `src/` and asserts nothing else builds one
directly — reverting a single call site fails it by filename.

Four new action types on the entity: `ABILITY`, `EXPERIENCE`, `HEAL`,
`STATISTIC`.

### The abilities, from the server's own help

`PlayerModerator` gained god mode, invisibility, noclip, the voice ban,
XP and the SteamID ban/unban. Every command name was read from the live
server's `help` rather than from documentation, which mattered:

- **`godmodplayer`**, not `godmodeplayer` — the server's own help text
  documents the second spelling in its *example* and accepts the first.
- `invisibleplayer`, `noclip`, `voiceban`, `addxp`, `banid`, `unbanid`.

Each ability takes `-true`/`-false`, so the panel sets a state rather
than toggling one nobody read.

**Both unquoted arguments are pinned to a shape.** The perk and the
SteamID appear without quotes in their commands, so a semicolon would
start a second command: letters only and digits only, refused at the
endpoint *and* in the moderator, with tests firing
`'Woodwork=1 -true; quit'` and `'7656119800000000; quit'` at both. XP is
capped at 100000 so a typo cannot max a skill.

Endpoints: `POST /players/{username}/ability` under `KickPlayers` —
somebody who may remove a player may also make them invincible — and
`POST /players/{username}/experience` under `GiveItems`, because granting
XP is giving something out. Seven new cases in
`PermissionEnforcementTest`.

### Files

| File | Change |
|---|---|
| `backend/resources/bridge/ZomboidControlBridge.lua` | **0.18.1**; utilities by method rather than field |
| `backend/src/Server/Bridge/UtilityReading.php` | **new** |
| `backend/src/Controller/Api/UtilityEndpoint.php` | **new** |
| `backend/src/Server/Players/ModerationRecorder.php` | **new** |
| `backend/src/Server/Players/PlayerModerator.php` | abilities, XP, SteamID bans |
| `backend/src/Entity/ModerationAction.php` | four new types |
| `backend/src/Controller/Api/PlayerController.php` | ability and experience endpoints |
| 6 controllers + `RosterWatcher` + `ExpiredBanLifter` | route through the recorder |
| `frontend/src/features/events/utilities.ts` | **new** |
| `frontend/src/features/events/world-page.tsx` | the utility section |
| `backend/tests/…/UtilityReadingTest.php` | **new**, 6 |
| `backend/tests/…/ModerationRecorderTest.php` | **new**, 3 |
| `backend/tests/…/PlayerModeratorTest.php` | +9 |
| `backend/tests/Functional/PermissionEnforcementTest.php` | +7 |

### Verification

| What | State |
|---|---|
| Backend | **562 tests, 5527 assertions green** |
| Frontend | **248 tests, 23 files green** |
| Container | `lint:container` clean |
| Lua | `luac -p` clean |
| Live | the colours, the lightning and the player refusals verified against 0.18.0 |
| **Waiting on an upload** | 0.18.1 — the utilities cannot work until it is up |
| Guards proven by reverting | the recorder bypass, the perk injection, the SteamID shape |

---

## The player dossier: a column, notes, and a log about one player (2026-09-06)

### The dossier is beside the list now, not on top of it

`PlayerDetail` was a `Dialog`, so inspecting somebody covered the list
and moving to the next one meant close, find, open — while comparing two
players is the job often enough to shape the page around.

- `players-page.tsx` is `grid xl:grid-cols-[1fr_26rem]`: the list keeps
  the room its five columns need, the dossier takes a fixed share beside
  it. Below `xl` they stack.
- `player-table.tsx` reports its selection upward (`onSelect`) instead of
  opening a dialog, and marks the chosen row — nothing else says which
  player the column is showing.
- `player-vitals.tsx` is the old dialog body, unchanged in content: it
  moved, it was not rewritten.
- The tabs stay visible with nothing selected, each naming the missing
  choice rather than rendering blank.

**Kick and ban are deliberately not repeated in the dossier header.**
They are one click away on the row already, and two places to ban
somebody from is one place too many to keep in step.

### The abilities have three states, not two

God mode, invisibility, noclip and the voice ban, all plain RCON — and
the command names came from the **live server's own `help`**, which
matters: it is **`godmodplayer`**, not `godmodeplayer`; the server's help
text documents the second spelling in its example and accepts the first.

The server keeps none of these anywhere the panel can read back, so a
switch showing "off" would claim something nobody checked — two admins
fighting over one flag is what that produces. Each row therefore starts
**unknown** and only claims a state once this panel set it, which is also
why it is two buttons rather than a switch: a switch has no third
position. The voice ban says plainly that it does not survive a
reconnect.

### Both unquoted arguments are pinned to a shape

The perk and the SteamID appear **without quotes** in their RCON
commands, so a semicolon would start a second command. Letters only and
digits only, refused at the endpoint *and* in `PlayerModerator`, with
tests firing `'Woodwork=1 -true; quit'` and `'7656119800000000; quit'` at
both. XP is capped at 100000 so a typo cannot max a skill.

**The XP chooser is built from the skills the bridge reports for this
character**, not from a table of the game's 47 perk names: it then offers
exactly what exists on this server, mods included, cannot drift, and
shows each skill's level so a grant is aimed rather than guessed.

### One moderation recorder

Nine `new ModerationAction(...)` literals across eight files became
`ModerationRecorder`. **Two shapes, because the callers differ**:
`record()` persists and flushes, `add()` only queues —
`ExpiredBanLifter:70` and `RosterWatcher:52` flush **once at the end**,
and turning their single transaction into one per row would have been a
regression nobody notices until a busy server slows down. That was
checked in the sources, not assumed.

`ModerationRecorderTest` walks `src/` and fails if anything constructs
one directly.

### Notes and tags: the one thing the dossier stores

`PlayerNote` + migration `Version20260906193959`. One row per player per
server, updated in place.

- **Tags are clicked, not typed**, from the fixed suggestions *and*
  whatever this server already uses (`tagsInUse`) — which is what stops
  "greifer" and "griefer" becoming two tags. Lower-cased and
  deduplicated for the same reason.
- Capped at ten of 24 characters, so a paste cannot turn a dossier into
  a wall of chips; commas and newlines inside a tag are flattened,
  because a comma means somebody meant several and typed one.
- **An emptied note leaves no row behind**: the absence is the state, and
  a dossier full of blank notes is one nobody trusts.
- Writing needs `KickPlayers` rather than a permission of its own —
  somebody trusted to remove a player is trusted to write down why, and
  a tenth permission for a text field would be governance nobody asked
  for.

The generated migration also wanted to **drop three
`messenger_messages` indexes**; they belong to the transport rather than
to this change and are what keep the queue fast, so they were left in
place and the omission is noted in the migration itself.

### The log, asked the right question

`ModerationActionRepository::forPlayer()` — one indexed lookup on
`idx_server_username`, which already existed. This is where the
reference panel goes wrong: it shows every player's activity inside a
view already scoped to one, and then needs a second search box to make
that usable.

### Two bugs found by looking at the result

- **Four action types had no label.** `ability`, `experience`, `heal`
  and `statistic` would have shown as raw identifiers, which reads as a
  bug rather than as a missing translation. `action-names.test.ts` reads
  the entity's own constants and fails when one is unnamed.
- **The reason column showed `players.joined` verbatim** — and that
  predated this work. The backend records some reasons as translation
  keys (`players.joined`, `banExpired`) and others as typed prose, in the
  same column. `reason.ts` tells them apart: a dotted lower-camel path is
  a key, and `banExpired` is named explicitly because a pattern loose
  enough to catch a lone word would also catch typed prose.
  `reason.test.ts` asserts both, plus that every key resolves in both
  locales, plus that a new bare reason in the backend is named here.

### Files

| File | Change |
|---|---|
| `frontend/src/features/players/players-page.tsx` | list and dossier side by side |
| `frontend/src/features/players/player-table.tsx` | reports selection instead of opening a dialog |
| `frontend/src/features/players/player-dossier.tsx` | **new** — four tabs |
| `frontend/src/features/players/player-vitals.tsx` | **new** — the old dialog body |
| `frontend/src/features/players/ability-rows.tsx` | **new** — three states |
| `frontend/src/features/players/experience-card.tsx` | **new** |
| `frontend/src/features/players/notes-card.tsx` | **new** |
| `frontend/src/features/players/player-history.tsx` | **new** |
| `frontend/src/features/players/reason.ts` + test | **new** |
| `frontend/src/features/players/action-names.test.ts` | **new** |
| `backend/src/Entity/PlayerNote.php` | **new** |
| `backend/src/Repository/PlayerNoteRepository.php` | **new** |
| `backend/src/Controller/Api/PlayerNoteController.php` | **new** — 3 routes |
| `backend/migrations/Version20260906193959.php` | **new** |
| `backend/src/Repository/ModerationActionRepository.php` | `forPlayer()` |

### Verification

| What | State |
|---|---|
| Backend | **576 tests, 5547 assertions green** |
| Frontend | **255 tests, 25 files green** |
| Lint | 0 errors, 29 warnings |
| Container, migration | clean; migration ran |
| Live | a note saved and re-read after a reload, tag kept, the per-player log showing real joins, an item and a teleport, no raw keys left on the page |
| Guards proven by reverting | the recorder bypass, a missing action label, the perk and SteamID injections |

### Still open on this item

- ~~`player-detail.tsx`~~ deleted: nothing imported it, and its content
  lives on in `player-vitals.tsx`.
- The **vitals sliders** need bridge 0.18.1 (`readPlayerStats`,
  `setPlayerStat`) and a player online; the heal button likewise.
- **Kill stays unbuilt** — no server-side Lua call exists anywhere in
  the game for a player, only for animals.

---

## Route-level permission guards (2026-09-06)

A real hole, not a tidy-up: the per-page permissions were enforced
**only in the sidebar**, so a moderator holding `players.view` could
reach `/servers/<id>/console` by typing the URL. The nav entry was
hidden; the route was not.

`RequirePagePermission` now sits inside the server branch and reads
**the same `SERVER_PAGES` table the navigation reads** — a second list of
route permissions would eventually disagree with the first, and the
disagreement would be the hole. A child page resolves through its parent
(`events/weather` → `events.trigger`), because there is one event
permission and the parent gates the subtree.

`permissionForPath` lives in `page-permission.ts` rather than beside the
components: a file exporting both a component and a function breaks fast
refresh, which oxlint caught — the same lesson as `DAY_MARKS`.

**Scope stated plainly**: this stops somebody landing on a page whose
every control would refuse. The endpoints behind it carry their own
`#[IsGranted]` and remain the real boundary; a route guard in a
JavaScript bundle is a courtesy, not a security control.

`page-permission.test.ts` (5) asserts every page resolves to the
permission the sidebar declares, that the console is gated behind
`console.use`, that all six event children resolve through their parent,
and that paths outside a server are left alone.

| What | State |
|---|---|
| Frontend | **260 tests, 26 files green** |
| Lint | 0 errors, 29 warnings |
| Live | the console still reachable with a full-permission account |

---

## What was asked for, and whether it worked (2026-09-06)

`ModerationAction`'s overloaded columns, from the deferred list. The
history could say "rain" but never **"rain at 70"**: the inputs were
dropped on the way in, so the log recorded which action ran and not what
it ran with. And a list that cannot tell a refusal from a success reads
as though everything worked.

Two nullable columns — `inputs` (json) and `failed` (bool) — plus
migration `Version20260906195157`. **Null is honest for every existing
row**: nobody recorded those things, so claiming `false` would be
inventing history. Non-scalars are dropped rather than serialised, since
the point is "rain at 70" and not a faithful copy of a request body.

The event history and the dossier log both show them: the inputs as
`value=80` in monospace, and a refused action as a destructive badge
rather than an outline one.

**Verified against the database**: firing the fog preset recorded
`{"value":80}` with `failed = f`.

### And a repeated annoyance ended

Every generated migration proposed dropping three `messenger_messages`
indexes — the ones that keep the queue fast — and they had to be removed
by hand **twice** (the `player_note` migration and this one).
`doctrine.yaml` now carries

```yaml
schema_filter: ~^(?!messenger_messages)~
```

because that table belongs to the transport rather than to any entity.
The next `migrations:diff` is clean by construction.

**One thing to remember**: the test database needs migrating too
(`--env=test`), which cost 21 errors before it was run.

| What | State |
|---|---|
| Backend | **576 tests green** |
| Frontend | **260 tests green** |
| Live | the fog preset's inputs read back out of the database |

---

## The wheels: one real cause found, the drawing still not working (2026-09-06)

Tried, and **partly** solved — recorded honestly because the next person
should not repeat the search.

### What was actually wrong, and is now fixed

`ModelStore::has('Vehicles_Wheel.txt')` returned **false**, so the
catalogue sent `wheelMesh: null` and `wheelsFor()` returned `[]` — with
no error anywhere, exactly as its docblock promises ("leaves a vehicle
sitting on nothing rather than failing to draw at all").

The reason: the game names the model `Vehicles_Wheel` (plural) in
`media/scripts/generated/vehicles/models_vehicles.txt`, and the file
lives at `media/models/Vehicles_Wheel.txt` — but the extraction had
saved it under its **internal** "Model Name" header, `Vehicle_Wheel`,
singular. `Vehicles_Wheel02/03/04` were all present; only 01 was
misnamed, because only its internal name differs from its file name.

**This is the same class of mistake that once left all 241 vehicles
undrawable** (the catalogue held model-*block* names instead of mesh file
names). The lesson repeats: a Zomboid model has three names — the block,
the mesh file, and the header inside the file — and they are not
interchangeable.

Copied to `backend/var/vehicle-models/Vehicles_Wheel.txt`, and
`wheel-mesh.test.ts` (3) now pins that it exists under the name the
catalogue asks for, parses into geometry with normals and UVs, and is
0.316 units across — metres, matching the script's radius of 0.15, which
confirms `setScalar(100)` beside offsets multiplied by 100 is consistent.

### What is verified working

| Link in the chain | State |
|---|---|
| The file exists and is served | `GET /api/vehicle-models/Vehicles_Wheel.txt` → 200, 5799 bytes |
| The catalogue reports it | `wheelMesh: "Vehicles_Wheel.txt"` plus four offsets, confirmed in the browser |
| The hook passes it through | `use-vehicle-renderer.ts` hands catalogue entries straight to the renderer |
| The parser reads it | 52 vertices, normals, UVs — asserted |
| The size is right | 0.316 in metres, so the scaling is consistent |

### What still does not work

**No wheels appear in the preview**, after clearing the service-worker
cache, `localStorage`, `sessionStorage` and reloading. Every link above
checks out individually, so the fault is in the scene assembly —
`bodyFrame()`, the `frame.add(wheel)`, or the interaction with the
`upright` group's rotation.

**What has been ruled out**: a missing file, a 404, a null in the
catalogue, a parser failure, a wrong unit, and a stale render cache.

**What to try next**, in order:
1. Instrument `draw()` to log `frame.children.length` before and after
   the loop, and the wheels' world positions after the rotations — the
   camera framing uses the *body's* bounds, so a wheel placed correctly
   but outside them would be off-screen rather than invisible.
2. Check whether `bodyFrame()` returns a node that the later
   `upright`/heading rotations move differently from the body: it walks
   to the last mesh's parent, which for a multi-mesh FBX may not be the
   node the body geometry hangs on.
3. Render one wheel alone, with no body, to see whether it draws at all.

Screenshots cannot settle any of those, which is why this stops here
rather than guessing at a fourth fix.

---

## The last two bridge capabilities reach the panel (2026-09-06)

Bridge 0.18 shipped more than the panel could use. Both gaps are closed.

### Weather stages, run for a duration

A preset sets values and lets them stand; a **stage** hands the weather
to the simulation for a number of game hours and lets it run its own
course — which is how the world makes weather when nobody interferes,
and the route the game's own admin console takes
(`ISAdmPanelWeather.lua:174`).

**Eight stages rather than the three the plan named**: the game declares
twelve and four are its own bookkeeping (`START`, `INTERMEZZO`,
`MODDED`, `KATEBOB_STORM`), so showers, clearing, moderate and drizzle
came along for free.

`weather-stages.ts` mirrors `BridgeCommand::WEATHER_STAGES` and a test
asserts the two lists are identical — the bridge refuses anything else,
so a name that drifted here would be a control that always fails.

**Verified live**: a four-hour storm answered `weather stage triggered`.

### The two climate colours

One colour picker each for the global light and the fog, set for indoors
and out **together**. The game holds eight channels — four in, four out —
but "the light is too blue" is one thought, and a panel offering eight
sliders for it would be a panel nobody uses. Anyone who needs them apart
has the console.

Each row says who decided it and offers to hand it back, exactly as the
thirteen values above it do. The channels stay 0..1 end to end; hex
conversion happens at the interface's own edge, where a colour input
needs it.

**The unit guard earned its keep here**: it refused `r`/`g`/`b` for
having no unit, which was the right question — a channel is shown as a
swatch and never as a figure, so they are exempt with that reason
recorded beside the exemption.

**Verified live**: the global light reads `#958f90` and the fog
`#e6e6f2`, both from the running game.

| What | State |
|---|---|
| Backend | **576 tests, 5553 assertions green** |
| Frontend | **265 tests, 27 files green** |
| Live | a storm triggered; both colours read from the game |

---

## The setState-in-effect warnings, and two bugs behind them (2026-09-06)

29 warnings down to **21**, and the interesting part is that two of the
five effects were hiding real faults.

### The pattern, and why it is a bug and not a style question

An effect copying server data into state renders twice — once with the
old value, once with the new — and, worse, **overwrites whatever
somebody was typing** whenever the query refetches. The replacement is
always the same shape: hold the edit against the thing it belongs to, and
derive the shown value during render.

```ts
const [edit, setEdit] = useState<{from: X, ...} | null>(null)
const shown = edit !== null && edit.from === current ? edit.value : current
```

Applied to `role-list.tsx`, `events-page.tsx`, `items-page.tsx`, and
earlier to `climate-page.tsx` and `notes-card.tsx`.

### The two real bugs

**The events page landed in the wrong category.** Its fallback took
`matches[0]`, and `matches` filters only by the **search term** — the
category was applied later, when rendering the list. So the sounds page
opened on a weather action and the zombies page on whatever came first in
the catalogue. Now narrowed to the page's own category, and verified: the
sounds page lands on *Donner* with its optional player chooser, not on
"start rain" with an intensity field.

**The items page could stop paging.** Its observer effect depended only
on `matches.length`, so the callback kept whatever `visible` it had
closed over and added to a stale count. Now an updater form and a
`useCallback`, so the effect can name it as a dependency honestly.
Verified: 400 tiles → 800 → 1200 as the sentinel comes into view.

### And one flicker

`browserSupportsWebAuthn()` was read in an effect on both the login page
and the passkey manager, so the passkey button appeared on the *second*
render. It is a fact about the browser, not state: `useState(fn)` reads
it once at mount.

### What is left, deliberately

| Kind | Count | Why it stays |
|---|---|---|
| `only-export-components` | 13 | A provider beside its own hook, and tables beside the component that uses them. The three that were genuinely a pattern (`DAY_MARKS`, `permissionForPath`, `WEATHER_STAGES`) moved to their own files. |
| `set-state-in-effect` | 3 | All in vendored components (`reui/badge`, `ui/button`) — they stay as they came. |
| `refs`, `exhaustive-deps`, `purity` | 5 | One each in the map and vendored code; none is a data-into-state copy. |

---

## The vitals, with a player online at last (2026-09-07)

The dossier's last piece, and the two handlers that had never been fired.

### Three broken uploads before it worked, and what each taught

| Version | Fault | Why the tooling missed it |
|---|---|---|
| 0.18.0 | `readUtilities` indexed `SandboxOptions.elecShutModifier`, a public **field** | Lua returns null for a Java field; nothing local knew |
| 0.18.1 | a copy of `readUtilities` sat **above** `local handlers = {}` | `luac -p` checks syntax, not scope — it passed every time |
| 0.18.2 | `readUtilities` was still the by-field version; the scripted edit had moved the *other* copy | no guard existed for either shape |
| 0.18.3 | **works** | |

Three guards now, each proven by reverting: no handler above its table,
none defined twice, and neither sandbox field indexed anywhere. All three
run before an upload rather than surfacing in a server log.

**And the test tool had its own fault.** The shell hands every argument
over as a string while `BridgeCommand` checks `=== true`, so
`-a on=true` validated to **false**: asking to switch the power on
switched it off, and the handler looked broken when it was not.
`BridgeSendArgumentsTest` (10) pins the types now.

### What the live server actually reports

**The bounds are wildly uneven**, which is the whole justification for
reading them rather than tabulating them:

```
Anger              0..1        Boredom          0..100
Endurance          0..1        Fitness         -1..1
NicotineWithdrawal 0..0.51     Temperature     20..40
Thirst             0..1        ZombieFever      0..100
```

A single assumed range would have been wrong for most of them, and
`Stats::set` **clamps** rather than refusing — so being wrong would have
applied something else and reported success.

Plus `weight: 80` and `profession: "burglar"` from the descriptor.

### Verified from the panel, with a player online

| What | Result |
|---|---|
| `readPlayerStats` | all 24 with their own min, max and default |
| `setPlayerStat` | Panic set to 2, read back as 2 |
| Out-of-bounds | refused by name: "Temperature must be between 20.00 and 40.00" |
| `healPlayer` | **17 body parts** restored, health 1.0, not infected |
| The heal button in the dossier | same, through the endpoint |
| Power and water switches | both directions, badge changing from "Läuft dauerhaft" to "Abgeschaltet" |
| `releaseSnow` | works — it failed in 0.18.0 on `ClimateBool::getFinalValue` |

### One real bug the live test found

**`Stats::set` returns false when the value did not *change*.** Setting
Boredom to 0 while it was already 0 came back as "the server refused the
statistic", which is not a refusal — it is nothing to do. **0.18.4**
reads the stat back and compares, which is what the docblock already
claimed the code did.

### And one of mine, caught by looking

`players.condition` was the player table's column header. I had
overwritten it with "Zustand anpassen", so the table's column changed
its name. The new section uses `players.adjustCondition`.

### Files

| File | Change |
|---|---|
| `backend/resources/bridge/ZomboidControlBridge.lua` | **0.18.4** |
| `backend/src/Controller/Api/PlayerVitalsController.php` | **new** — 4 routes |
| `backend/src/Command/BridgeSendCommand.php` | sends real types |
| `backend/tests/Unit/Command/BridgeSendArgumentsTest.php` | **new**, 10 |
| `backend/tests/…/BridgeCommandCoverageTest.php` | +2 guards |
| `backend/tests/…/BridgeClimateCallsTest.php` | +1 guard |
| `frontend/src/features/players/vitals-card.tsx` | **new** |
| `frontend/src/features/players/stats.test.ts` | **new**, 4 |

### Verification

| What | State |
|---|---|
| Backend | **589 tests, 5626 assertions green** |
| Frontend | **269 tests, 28 files green** |
| Lint | 0 errors, 21 warnings |
| Live | every bridge handler now fired against the running server |

**Needs one more upload**: 0.18.3 is live, 0.18.4 fixes the unchanged-value
refusal. Nothing else waits on it.

---

# TODO — the current list (supersedes every earlier one)

## The players page rebuilt, and the skills that were never there (2026-09-07)

The user's verdict on the dossier was blunt and correct: "Die
Spielerübersicht hat ja nicht so viel mit dem zu tun was ich dir
geschickt habe. Wo sind denn die fähigkeiten? Die Spalte für die Spieler
(links) ist viel zu breit und die rechts viel zu schmal." Plus a
screenshot with a red arrow at an empty skills panel: "hier geht gar
nichts".

Four separate causes, each found by measuring rather than guessing.

### 1. The skills were never read at all — the fifth field trap

`describeSkills` walked `player:getPerkList()` and tested
`info.perk ~= nil`. **`PerkInfo.perk` is a public Java field and
`PerkInfo` has no `getPerk()` at all** (`javap -p
'zombie.characters.IsoGameCharacter$PerkInfo'`: two methods, the
constructor and `getLevel`). A field reads nil from Lua, so the test was
always false, the loop inserted nothing, and `skills` arrived as `{}`.
**Nothing threw and nothing was logged** — the panel drew an empty list
and reported success.

Proof it was live: `SELECT skills::text FROM player_snapshot` returned
`[]` while `traits` held six entries. Same player, same payload, one
read broken.

This is the **fifth** trap of one family, after `ClimateBool::getFinalValue`,
`Color::getA`, the `ORDERED_STATS` array and `SandboxOptions`' own
fields. So the guard is now general rather than per-case.

**The fix is the game's own route**, from
`media/lua/shared/Logs/ISPerkLog.lua:10-16`:

```lua
for index = 0, Perks.getMaxIndex() - 1 do
    local id = Perks.fromIndex(index)
    local perk = PerkFactory.getPerk(id)
    local level = player:getPerkLevel(id)
```

Three statics and one instance method, no field anywhere.
`Perks.fromIndex` returns an **id**, not the perk — `PerkFactory.getPerk`
is the second step, which I would have missed. The category filter is
`perk:getParent()`, compared **by id against "None"** rather than against
`Perks.None`, because that constant is itself a static field and a nil
there would have discarded every skill.

### 2. Two names and one parent that could not be guessed

From `javap -p -c zombie.characters.skills.PerkFactory`'s constant pool:

| id | displayed as |
|---|---|
| `Woodwork` | **Carpentry** |
| `PlantScavenging` | **Foraging** |
| `Lightfoot` | Lightfooted |
| `Sneak` | Sneaking |

And **`Doctor` hangs under `Survivalist`**, not under Crafting. Six
categories, 35 skills, all in `frontend/src/features/players/skills.ts`.
`skills.test.ts` pins each of these; moving Doctor to Crafting fails it.

### 3. The layout: a table wanted the whole width and got a third

`player-table.tsx` was a five-column `<Table>` inside
`xl:grid-cols-[1fr_26rem]`. Rule 7 says full width is for a table — it
had a third of it, and its columns (condition, position, access level)
duplicated what the dossier shows anyway.

Measured in the browser afterwards: **list 320 px, dossier 785 px**.

- **New** `player-list.tsx` — name, state dot, one line of context, with
  Online/Alle/Gebannt tabs, a search box and a manual-target field.
- **New** `dossier-header.tsx` — name, state, SteamID, kick and ban
  always reachable, plus hours survived and the kill tallies.
- **New** `moderation-tab.tsx` — access level and teleport as cards;
  they were behind a `…` row menu, which is right for a list of thirty
  rows and wrong once a player is already open.
- **New** `use-moderation.ts` — the four commands in one place, so the
  dossier and the list cannot drift into two ways of banning somebody.
- **Deleted** `player-table.tsx` and `ban-list.tsx`: both were dead once
  the list replaced them.

Seven tabs, one job each: Zustand, Charakter, Fertigkeiten, Moderation,
Fähigkeiten, Vergeben, Notizen. The skills had been buried at the bottom
of the condition tab; the abilities had shared a tab with granting XP.

### 4. The kills fell between the bridge and the panel

The bridge has reported `zombieKills`/`survivorKills` since 0.18, and
**nothing read them** — `PlayerSnapshot::update()` did not take them.
Added as two nullable columns with their own `recordKills()` rather than
as arguments fourteen and fifteen of a thirteen-argument method, where a
transposition would be silent. Migration `Version20260907072720`, run on
dev **and test**.

### The professions and traits, with the game's own artwork

Asked for mid-session with a screenshot of the character creator: "was
ich auch toll finden würde wenn wir eine übersicht für die berufe bauen
könnten mit den icons aus dem spiel … Es gibt gute Fähigkeiten und
schlechte."

**Changing a profession: proven impossible, and not built.** The chain,
each step checked:

| Question | Answer |
|---|---|
| Setter exists? | `SurvivorDesc::setCharacterProfession` — yes |
| Called server-side? | Only `client/ISUI/PlayerStats/ISPlayerStatsUI.lua:603`; **never in `server/`** |
| The sync beside it? | `sendPlayerStatsChange` → `getstatic GameClient.client; ifeq 13` — **immediate return on a server** |
| A server-side counterpart? | `GameServer::receiveChangePlayerStats` — the server **receives**, it does not send |
| The reference bridge? | **9132 lines, zero mentions of profession** — its author read the class files by hand and left it out |
| The one real broadcast? | `sendPlayerExtraInfo` (global, one argument, **no client guard**) sends `ExtraInfoPacket`, which carries roles and 24 cheat flags — **no profession, no traits** |

Building a control there would repeat the snow bug exactly: write, read
back your own write, report success, watch the game overwrite it.

**Traits: built, with the limit stated on screen.**
`media/lua/server/XpSystem/XpUpdate.lua:209-242` adds and removes traits
on a live character, server-side, which is the precedent. The game's own
sequence is three steps (`ISPlayerStatsUI.lua:591-597`): `traits:add`,
then **`modifyTraitXPBoost`** — without it the trait is listed but
inert, since the XP boosts live on the character — then the broadcast.
The client's own sheet only catches up on reconnect, and
`character.reconnectNotice` says so.

`CharacterTrait.get(ResourceLocation.of(name))` is the name-based
lookup, from `shared/Foraging/forageSystem.lua:1718`. Both are static
methods; the 97 `CharacterTrait` constants are fields and unreachable.

**The data comes from the installation's own generated scripts**,
`media/scripts/generated/characters/{character_professions,character_traits}.txt`
→ `backend/src/Server/Players/Character/CharacterDefinitions.php`: 25
professions, 97 traits, with cost, UI name, icon, XP boosts, granted
traits and mutual exclusions.

**The sign of `cost` means two different things**, and this is the one
thing here that could have coloured the whole page backwards:

- **A trait's cost is a rating**: `athletic` +10, `strong` +10 are
  advantages; `weak` −10, `deaf` −12 are drawbacks.
- **A profession's cost is a price**, so the sign inverts:
  `veteran` −8 is the dearest job, `unemployed` **+8** refunds points.

My first reading had it backwards. Hence `isTraitAdvantage` takes a
trait's cost and nothing else, and the docblock says why a profession
has no good-or-bad axis.

**The artwork**: `UI2.pack` holds 24 profession and 116 trait sprites —
found with `strings | grep -c`, since a full grep of the install times
out on the USB drive. Traits carry **no `IconPathName`**, so the name is
derived (`athletic` → `trait_athletic`); 14 more live as loose PNGs in
`media/ui/Traits/`, and **two have no icon in the game at all** ("out of
shape", "very underweight"), which is why `CharacterIcon` falls back to
a letter. 152 files in `backend/var/character-icons`, **verified
gitignored**, extracted with `app:icons:extract --characters`.
`Sprite::isItemIcon()` became `hasAnyPrefix(array)` so one extractor
serves both stores.

**The names are the game's own**, 122 keys pulled from its `DE/UI.json`
and `EN/UI.json` into `character.profession.*` and `character.trait.*` —
"Einbrecher", "Langsam-Lerner", "Magenleiden", exactly the words in the
user's screenshot. Before this the dossier showed bare `slowlearner`,
`weakstomach`, `burglar`.

One collision found and fixed: `character.profession` is the namespace
of 25 jobs, so it could not also be the section heading —
`professionTitle` and `traitsTitle` are separate keys now.

The profession is read from its **granted trait** rather than the
descriptor: the roster does not carry the profession, but each job's own
trait is in the trait list and belongs to exactly one job.

### New guards, each proven by reverting

- **`testNoPublicJavaFieldIsIndexed`** — every public field of eleven
  game classes against the Lua, scoped to the variables in `HOLDS` plus
  the perk locals, because a bare `.name` is usually a Lua table key.
  1386 assertions. Reverting `describeSkills` fails it with *"perk is a
  public Java field on PerkInfo and reads nil from Lua"*.
- **`skills.test.ts`** (10) — the three renamed ids, Doctor's parent, no
  category listed as a skill, a German name for all 35.
- **`character.test.ts`** (12) — the cost sign against the generated PHP,
  profession traits kept apart, exclusions never offered, and **every XP
  boost resolvable through `skillLabel`** (the boosts name `Lightfoot`
  while the locale is keyed on `Lightfooted`; three showed untranslated
  on screen before this).
- `action-names.test.ts` caught the missing `trait` label by itself,
  which is what it was written for.
- `locales.test.ts` caught 35 German skill names with no English
  counterpart — filled with the display name, which *is* the English
  word.

### Verified in the browser, as asked

Playwright against the built app at 1440×900, logged in as a temporary
`uitest@localhost.test` (created with `app:user:create`, **the user's own
account untouched**):

- Split measured at **320 px / 785 px**.
- Seven tabs render, all German, no raw keys.
- `GET /api/character` → 25 professions, 97 traits, `iconsAvailable:
  true`, `iconCount: 152`.
- `GET /api/character/icons/*.png` → real PNG bytes; a missing name
  gives **404**, not a broken image.
- The Charakter tab drew **7 of 7 icons** with real dimensions
  (`profession_burglar2` 35×38, `trait_strong` 18×18 …), the profession
  as **Einbrecher** with "Leichtfüßig +2 / Beweglichkeit +2 /
  Schleichen +2", **Vorteile 1** (Stark +10) and **Nachteile 4**
  (Langsam-Lerner −6, Messie −6, Langsam-Heiler −3, Magenleiden −2),
  and the profession's own trait in its own row without a remove button.

### Not yet verified

**The skills themselves.** The server restarted on 0.19.0 and its
handlers answer (`readUtilities` returned power and water), but
`describePlayer` only runs for online players and nobody was on, so
`skills` is still `[]` in the database. **The one open check is: log in,
then confirm the pips fill and `skills::text` is no longer `[]`.**

### Housekeeping

- `llms.txt` names 0.19.0 (`DocumentationTest` demanded it, as intended).
- `vehicles-page.tsx` now reads `?player=`, so the dossier's spawn card
  does not promise a hand-off it never made. Its own `search` identifier
  clashed with `useSearchParams` and became `query`.
- The temporary `uitest@localhost.test` account is **still present** —
  say the word and it goes.

**Verification: 591 backend tests / 6838 assertions green, 291 frontend
tests across 30 files green, 0 lint errors (21 deliberate warnings),
`luac -p` clean, build clean, no game art in git.**

## The skill levels became the control (2026-09-07)

Asked for with a screenshot and an arrow at the pip row: "es ist auch
wichtig das man die fertigkeiten auch anpassen kann. es wäre praktisch
wenn man einfach auf die einzelnen stufen klicken könnte. Unten drunter
sollte man einer Fertigkeit auch manuell XP geben können." Then, in the
same breath: "für jede Fertigkeitsstufe benötigt man eine bestimmte
anzahl an XP … Vielleicht könnte man auch anzeigen wie viel man noch bis
zur nächsten stufe braucht wenn man über die aktuelle stufe hovert. Was
auch geil wäre sind die Fertigkeitsmultiplikatoren die beim lesen von
büchern steigen."

### Which write route actually reaches the player

Three candidates, and the bytecode picked the winner:

| Route | Server-side | Client told |
|---|---|---|
| `setPerkLevelDebug(perk, int)` | yes | **no** — writes `PerkInfo.level`, then `GameClient.sendPerks` **only if `GameClient.client`** |
| `level0` + `LevelPerk(perk, false)` + `setXPToLevel` | yes | via the level machinery |
| **`addXpNoMultiplier(player, perk, float)`** | yes | **yes** — tests `GameServer.server`, hands to `GameServer.addXp`, which resolves the connection and calls `NetworkPlayerAI.updateXpChecker()` |

That last row is the qualitative difference from the profession: there,
every path ended in a `GameClient.client` guard. Here the *opposite*
guard exists, and `updateXpChecker` is the mechanism that pushes it out.
So skills are settable and professions are not, for a reason that is in
the bytecode rather than in a preference.

**`LevelPerk` has two overloads and they are not equivalent.** The
one-argument form **spends one of the player's real unspent skill
points per call** — so filling a skill to 10 would silently cost ten
points. The two-argument `LevelPerk(perk, false)` does not. That came
from the reference bridge's own notes
(`PanelBridge.lua:4306-4312`) and is confirmed by both overloads
existing on `IsoGameCharacter`. Getting this wrong would have quietly
robbed players.

**`xp:setXP` does not exist**, despite the obvious name — also from the
reference bridge, also confirmed: `IsoGameCharacter$XP` has `AddXP` in
six overloads, `AddXPNoMultiplier`, `getXP`, `setTotalXP` and
**`setXPToLevel`**, which is what lands the within-level XP exactly on a
boundary instead of wherever the level loop stopped.

### The three numbers a level cannot give

`handlers.readSkillDetail` (bridge 0.20.0) reports per skill:

- **`xp`, `levelFloor`, `nextLevel`** — `getTotalXpForLevel(n)` is
  **cumulative**: its bytecode sums `getXpForLevel(1..n)`, so it is
  comparable against `getXP`, which is also a total. Verified in the
  bytecode rather than assumed, because a per-level reading would have
  made every remainder wrong.
- **`boost`** (0..3) from `getPerkBoost` — the game colours a skill name
  by it and golds a 3 (`ISCharacterInfo.lua:135-151`), so the panel golds
  it too.
- **`multiplier`** from `getMultiplier` — above zero only while a read
  book still applies. The game shows this **only** as three animating
  arrows beside the name (`ISCharacterInfo.lua:155-168`) and nowhere in
  text, so an operator had no way to know one was running. Now there is
  a band listing them plus a still arrow per row.

`XPMultiplier` itself is fields-only (`multiplier`, `minLevel`,
`maxLevel`, no getters) — the same trap again — which is why the float is
asked for through `getMultiplier(perk)` instead of the object.

### The interaction

- **Clicking pip *n* sets level *n*.** Hovering previews it: pips beyond
  the target dim rather than vanish.
- **Clicking the pip that is already the level sets zero** — the only
  reading left for that click, and it removes the need for a reset
  control.
- **Hovering the current level reports the remainder**: "1 250 von
  4 000 XP — 2 750 bis Stufe 7". A percentage sits at the row's end so a
  skill at 6-nearly-7 does not read like one that just reached 6.
- **Offline the pips are inert `<span>`s**, not disabled buttons, with
  "Nur lesbar — Spieler ist offline" beside the count. Verified in the
  browser: `editablePips: 0`, `role="img"`, label "Axt, Stufe 0 von 10".
- The XP row sits **beneath the grid** as asked, grouped by the same six
  categories, with the current level beside each option and the reply
  naming the level the XP landed on.

### Two browser-reported bugs, both real

**"wenn man den verlauf öffnet wird die ganze seite breiter."** A grid
`1fr` is `minmax(auto, 1fr)`, and `auto` is the *content's own minimum* —
so the wide history table pushed the column open instead of scrolling
inside it. `lg:grid-cols-[20rem_minmax(0,1fr)]` fixes it. Measured
before and after: `mainScrollWidth` 1154 = `clientWidth` 1154 with the
history open, grid steady at 1106 px.

**"die texte im verlauf sollten automatisch umbrechen."** shadcn's
`TableCell` carries `whitespace-nowrap`. Only the reason column is free
text, so only it gets `whitespace-normal break-words max-w-md` —
the timestamp and the name should not wrap. Measured: `whiteSpace:
normal`, `overflowWrap: break-word`, cells two lines high.

**"die top-bar und die footer-bar sollten fixed sein."** Done without
`position: fixed`, which would take them out of the flow and lose their
knowledge of the sidebar's width: `SidebarInset` is capped at `h-svh
overflow-hidden` and the scrolling moved to `<main>`. Measured across a
scroll: header top **0 → 0**, footer bottom **900 → 900** (the exact
viewport height), `documentScrolls: false`, inner scroller live.

### The guard that caught my own mistake

**`BridgeSkillListTest`** parses `skills.ts`'s own category arrays and
compares them with `BridgeCommand::SKILLS`. It immediately failed with
36 against 35: **`Crafting` had landed among the skills**, because it is
a *category* and my generator's regex caught its `id:`. A category has
no level, so `setSkillLevel` on one is a command the game cannot answer.
Reverting it fails two cases with both messages naming the cause.

**`BridgeClimateCallsTest` now walks the whole inheritance chain.** It
failed on `getX`, which is **not** on `IsoPlayer` or
`IsoGameCharacter` — the chain is `IsoPlayer → IsoLivingCharacter →
IsoGameCharacter → IsoMovingObject → IsoObject → GameEntity`, and `getX`
lives four classes up. Following one level of `extends` was not enough.
The fixture now carries 2342 methods and 168 fields for `IsoPlayer`, and
the guard went from 1386 to **6430 assertions** — it was weaker than it
looked.

### Files

| What | Where |
|---|---|
| Handlers | `resources/bridge/ZomboidControlBridge.lua` — `setSkillLevel`, `addSkillXp`, `readSkillDetail`, plus `perkById` |
| Commands | `BridgeCommand.php` — `SKILLS` (35), `MAX_SKILL_LEVEL`, `MAX_SKILL_XP`, `skill()` |
| Routes | `PlayerVitalsController.php` — `POST /skill`, `POST /skill/xp`, `GET /skills` |
| Interface | `features/players/skill-grid.tsx` (pips, hover, books, XP row) |
| Helper | `players.ts` — `levelProgress`, `readSkillDetail` |
| Guards | `BridgeSkillListTest.php`, `skills.test.ts` (+5 progress cases) |

### Verified against the running server

`admin` is offline, so the read path could not be exercised — but the
write path proved itself through its refusals:

| Request | Answer |
|---|---|
| `Axe` → 5 | **502 "unknown action: setSkillLevel"** — everything up to the bridge works; only the upload is missing |
| `Crafting` → 5 | **422** — the enum refuses a category before the bridge sees it |
| `Axe` → 11 | **422** — refused rather than silently clamped |

The failed attempt appears in the history as `Axe=5`, which is
`ModerationRecorder` keeping failures as designed.

**Still to check with a player online**: the pips fill, hovering reports
the remainder, a click lands, and the books band appears if a book is
running.

**Verification: 594 backend tests / 11909 assertions green, 296 frontend
tests across 30 files green, 0 lint errors (21 deliberate warnings),
`luac -p` clean, build clean.**

## Everything verified with a player online, and the bug that hid (2026-09-07)

Bridge 0.20.0 restarted with the user in the game, so the whole chain
could finally be exercised. **And the most important lesson of the day
came from the user, not the code**: "Du musst bitte IMMER alles mit dem
playwright browser mcp testen. Über die Konsole reicht es grundsätzlich
nie aus." Written into CLAUDE.md as rule 6b — on Coolify the operator
has barely any console access, so a feature that works only from the CLI
is not a feature.

### The skills reparation, proven

`readSkillDetail` against the live server, with real data where 0.18
returned `{}`:

| Skill | Level | XP | Boost |
|---|---|---|---|
| Strength | 9 | 337 527 / 487 500 | 3 → 125 % |
| Fitness | 5 | 37 503 / 67 500 | 3 → 125 % |
| Lightfoot, Nimble, Sneak | 2 | 225 / 525 | 2 → 100 % |

Those last three are exactly what the **Burglar** grants
(`Lightfoot +2, Nimble +2, Sneak +2`), so the chain profession → boost →
skill holds end to end.

Setting a level: `Woodwork 0 → 3`, read back as level 3 with **xp 525 ==
levelFloor 525** — `setXPToLevel` landing it exactly on the boundary
rather than wherever the loop stopped. Then 400 XP → 925, still level 3
(threshold 1275); 400 more → 1325, **level 3 → 4** with the bridge
reporting `levelBefore: 3`.

**The user confirmed it in the game**: "Ich habe jetzt Tischlerei auf
stufe 3." That is the answer to the question that decided the whole
design — the change reaches the client, unlike a profession.

### The bug the console had hidden

Then: "Wenn ich selbst versuche eine Fertigkeit zu setzen bekomme ich nur
eine kurze Fehlermeldung und es geht nicht." Both `setSkillLevel` and
`setTrait` worked from `app:bridge:send` and **failed from the button**.

Found by clicking the pip in Playwright and reading the request:

```
raw:     "{\"skill\":\"Cooking\",\"level\":5}"   ← a string, quoted
payload: []                                        ← empty
```

**`apiFetch` stringifies the body itself**
(`api.ts:58`), and three new callers did it again, so Symfony received a
JSON *string* holding JSON. `toArray()` read nothing, every field came
back null, and the 422 blamed `skill` — pointing at the payload's
*contents* while the fault was its *shape*. I spent several minutes
suspecting the enum, an opcache and the route before probing the actual
body.

Three call sites, all mine: `setSkillLevel`, `addSkillXp`, `setTrait`.
`frontend/src/lib/api.test.ts` now reads the client and every feature
module and fails if any caller double-encodes; reverting one fails it
with the file named.

### Then the whole flow, by clicking

| Action | Result |
|---|---|
| Pip "Kochen level 5" | row reads **Kochen 5** |
| Trait "Dickhäutig +8" | appears among the held, offers drop 64 → 60 |
| Trait "Kurzsichtig −2" | added from the drawbacks column |
| Remove both | back to six traits |

One bug found on the way: the offer list kept offering what had just
been added, because `invalidateQueries` was fired and not awaited, so it
rendered from the previous roster.

### The pips became the loading indicator

The user's idea, and better than a spinner: "Könnte man es nicht so
machen das der balken beim klick darauf so grün animiert ist bis ein
fehler gemeldet wird oder die Bridge den wert zurück gibt."

`asked` holds `{skill, level}` while a request is in flight; the row
renders `hovered ?? asked ?? server` and adds `.pz-pending` (a 900 ms
opacity breath) to the filled pips and the count. Cleared **after** the
refetch, or the pips would drop to the old value for one render. On
error it clears at once, so the pips snap back to the truth rather than
showing a level the server refused.

Measured mid-flight in the browser:

| | filled | pulsing | count |
|---|---|---|---|
| before | 0 | 0 | 0 |
| **30 ms after the click** | **6** | **6** | **6, pulsing** |
| 4 s later | 6 | 0 | 6 |

`prefers-reduced-motion` already covers it globally, so the value still
shows and only the breathing stops.

### The trait descriptions

Asked for as a tooltip. `UIDescription` exists on **84 of 97** traits,
resolved in both languages from the game's `DE/UI.json` and `EN/UI.json`
with no misses. The other thirteen (`brawler`, `hunter`, `fit`,
`tailor` …) have no prose at all but **all carry XP boosts** — Brawler is
`Axe=1;Blunt=1` — so the boosts are shown and stand in as the
description. The tooltip also names the clashing traits, which the page
previously only mentioned in general.

**Twenty of those strings contain the game's own `<br>`.** Stored as
real newlines in the locale and rendered with `whitespace-pre-line`, so
nothing reaches the DOM as markup — checked for both an escaped and a
real `<br>` in the document.

### Reported from the screen, all fixed

| Report | Cause |
|---|---|
| "Ich kann auch keine Eigenschaften hinzufügen" | the double-encoded body above |
| "hier fehlen die deutschen übersetzungen" (condition tab) | that tab printed the raw ids; both it and `Beruf: burglar` now go through the game's own names |
| "das klebt zu nah zusammen" | two `SectionMark`s had no gap below them |
| "einmal deutsch und einmal englisch" | every toast repeated the bridge's English reply under a translated heading. The bridge's replies are English restatements of the same sentence, so they are dropped; **RCON's replies are real server prose and stay** |
| "eine ganz klare trennung zwischen Vorteilen und Nachteilen" | the offer was two columns of one mixed list; now a column each, with its own heading and count |
| "der aktualisieren button ist überflüssig" | the list refetches every 3 s; the button went, the timestamp stayed |
| "was sind das für angaben" (the `7 %`) | the partial level, now shown only from level 1 up — "1 %" beside an untrained skill read as noise |
| "die noch benötigten xp beim hovern fehlen" | the remainder was on the *pip*, not the name, which is where a pointer lands |

Also: the profession boost now reads as the game's own XP rate
(50/75/100/125 %, from `ISPlayerStatsUI.lua:729-737`) rather than
"3 of 3".

### Where the trait work stands

Adding and removing works by clicking, both directions, verified. The
chooser is always present — hidden behind `player.online &&` it was
*absent* rather than disabled, and I wrongly told the user it was "there,
just collapsed" when it was not rendered at all. Two sections had that
bug; the second only surfaced because the user asked.

**Professions stay read-only**, and the evidence is in the earlier entry:
every write path ends in a `GameClient.client` guard, `ExtraInfoPacket`
carries no professions, and the reference bridge's 9132 lines never
mention them.

### Still open

- **Multiple professions.** The user can grant several in-game; the
  panel shows one. `SurvivorDesc.characterProfession` is a single field,
  so what the user sees is most likely the **profession traits**
  (`burglar`, `cook2`, `axeman` …). Those are deliberately withheld from
  the chooser, since setting one without its job leaves the sheet
  inconsistent. **Awaiting the user's decision** on whether to offer
  them separately.
- **`brave` is still on the character** from a CLI test, plus whatever
  the browser runs left; the two test traits were removed.
- The temporary `uitest@localhost.test` account still exists.

**Verification: 594 backend tests / 11909 assertions green, 298 frontend
tests across 31 files green, 0 lint errors (21 deliberate warnings),
build clean, and every interaction above exercised by clicking in a
browser rather than by command.**

## Sieben Vorhaben durchgeplant, Reihenfolge steht (2026-09-07)

**Der Plan liegt vollständig in
`docs/superpowers/plans/03-seven-features.md`** (1800 Zeilen). Dieser
Eintrag ist der Wegweiser dorthin; die Einzelheiten stehen nicht doppelt.

Der Nutzer wollte die sieben aufgeschobenen Bereiche „ganz genau
durchplanen, eins nach dem anderen", mit Rückfragen zu jeder Idee. Vier
Erkundungen liefen dafür: drei über den eigenen Code, eine über die
Referenz. Dazu `javap` gegen `projectzomboid.jar` und die
Spielinstallation.

### Die Reihenfolge, und warum

| # | Schritt | hängt ab von |
|---|---|---|
| **0** | **Bridge ohne Serverneustart laden** | — |
| 1 | Statistiken sammeln + Diagramme | — |
| 2 | Server-Konfigurationseditor | 0 (Messmethode) |
| 3 | Steam Workshop / Mods | **2** (INI-Zugriff) |
| 4 | Ereignisstrom | — |
| 5 | Benachrichtigungsglocke | **4** |
| 6 | Discord | **4** |
| 7 | Zeitplaner | 1 (ddev-Zeitgeber) |
| 8 | Avatare | — |

**Fundament zuerst**, vom Nutzer gewählt: Discord und die Glocke brauchen
denselben Ereignisstrom (zweimal gebaut wäre zweimal gepflegt), die
Mod-Verwaltung braucht den INI-Zugriff des Editors, und die
Statistiktabelle sollte so früh wie möglich anfangen zu sammeln.

### Entscheidungen des Nutzers

| Frage | Entscheidung |
|---|---|
| Reihenfolge | Fundament zuerst |
| Sandbox-Bedienung | gruppiert mit Suche, kein Rohtext als Standard |
| Sandbox-Gruppierung | aus dem Spiel (`getPageName()`) |
| **INI-Gruppierung** | **bewusste Handarbeit** — „was zusammengehört, bleibt zusammen" |
| Konfiguration schreiben | Sicherung + Prüflesen + **fünf Versionen**, wiederherstellbar |
| Mod-Werte | **müssen erhalten bleiben und angezeigt werden** |
| Startitems | Modal über den Item-Auswähler, **plus Vorlagen** |
| Discord-Bot | **voller Bot**, PHP im Panel-Container (fünfter supervisord-Eintrag) |
| Discord-Umfang | **deutlich mehr als die Referenz** |
| Discord-Befehle | **alles, was Panel und Spiel können**, geordnet wie das Panel |
| Discord-Kanäle | **Bot-Token, Kanal je Ereignisart** |
| Statistiken | **so viel wie möglich** sammeln |
| Aufbewahrung | ein Jahr, einstellbar |
| Diagramme | **sofort** mitbauen |
| **`reloadlua`** | **nur bauen, wenn absolut sicher** — sonst Neustart |

### Die wichtigsten belegten Funde

**Der Sandbox-Editor ist vollständig ableitbar.** Das entschied seinen
Aufwand. `zombie.SandboxOptions` führt jede Option typisiert
(`EnumSandboxOption`, `BooleanSandboxOption`, `DoubleSandboxOption`,
`IntegerSandboxOption`) mit `getMin()`, `getMax()`, `getNumValues()`,
`getTranslatedName()`, `getTooltip()` und **`getPageName()`** (der
Gruppe). Dazu `Translate/DE/Sandbox.json` mit **1082 Einträgen**,
inklusive jeder Auswahloption (`Sandbox_ActiveOnly_option1` = „Beides")
und **266 Erklärungen**. **274 Werte** in `Apocalypse.lua`, davon 183 im
Hauptbereich.

**Die INI ebenso: 144 Optionen** in `zombie.network.ServerOptions` (73
Boolean, 29 Integer, 22 String, 11 Enum, 7 Double, 2 Text) mit
`getTooltip()` — **aber ohne Gruppierung.** Daher Handarbeit.

**`reloadoptions` fasst die Sandbox nicht an** — im Bytecode geprüft: es
ruft `ServerOptions.init()`, `sendOptionsToClients()`,
`ZombiePopulationManager.onConfigReloaded()`,
`SafetySystemManager.updateOptions()` und `SetServerPassword()`. Für die
**INI** also genau richtig, für die Sandbox wirkungslos.

**`reloadlua` ist der Weg für Lua — und für unsere Bridge.**
Bytecode von `ReloadLuaCommand`: durchsucht `LuaManager.loaded` per
`String.endsWith` (ein Teilstring genügt!), entfernt den Pfad und ruft
`RunLua(gefundenerPfad, true)`. **`RunLuaInternal` trägt den Pfad wieder
ein** (Anweisung 367–371), das Neuladen ist also **wiederholbar**.

**Der Haken, den ich dabei fand**: die Bridge registriert zwei Ereignisse
beim Laden (`:2854`, `:2858`), und `Events.X.Add()` **ersetzt nicht**.
Ohne Wächter liefe `onTick` doppelt. Und **`OnServerStarted` feuert beim
Neuladen nicht** — der Erstschreibvorgang, die Kataloge und vor allem
**`readCursor()`** müssten nachgeholt werden, sonst fängt die Bridge bei
Befehl 1 an (bei uns über 600). `Events.X.Remove` existiert und wird vom
Spiel selbst genutzt (`ISCampingMenu.lua:452` u. a.).

**Slash-Befehle brauchen kein Gateway.** Discord ruft **uns** per HTTP
auf, mit Ed25519-Signatur — und `ext-sodium` ist bei uns Pflicht. Nur
der **Chat-Empfang** braucht einen Dauerprozess. Daher die Dreiteilung:
fällt das Gateway aus, laufen Meldungen und Befehle weiter.

**Discord-Unterbefehle und Autovervollständigung** lösen das
Mengenproblem: 35 Ereignisse + 22 Spielerrouten passen in **acht**
Befehle, und die Autovervollständigung schlägt echte Spieler, Items und
Fertigkeiten vor (Auswahllisten sind auf 25 begrenzt, sie nicht).

**Ein Webhook kann nur einen Kanal** — daher der Bot-Token für „Kanal je
Ereignisart".

**Eine Zeitreihe existiert schon**, die ich früher verneint hatte:
`RosterWatcher.php:41,45` schreibt bei **jedem** Beitritt und Abgang eine
Zeile mit `performedAt`. „Spieler über Zeit" ist heute zeichenbar. Nur
**Kills im Verlauf** braucht eine neue Tabelle.
**`moderation_action` hat keinen Index auf `performed_at`** — der gehört
in Schritt 1.

**Der Zeitplaner läuft produktiv, aber nicht lokal**:
`docker/supervisord.conf:44-51` konsumiert `scheduler_main`,
`.ddev/config.yaml:313-317` **nicht**. Geplante Aufgaben feuern lokal
also nie. **Wird in Schritt 1 behoben**, sonst ist nichts prüfbar.
`src/Schedule.php` ist toter Flex-Rumpf.

**Avatardaten werden geholt und weggeworfen**:
`SteamProfileFetcher.php:55` liest nur `personaname` aus einer Antwort,
die `avatarfull` im selben Objekt trägt. `GoogleAuthenticator.php:103`
ignoriert `getAvatar()`. Kein Skalieren im Backend (nur `imagecopy`),
**kein WebP/AVIF, keine Formatprüfung**, GD in `composer.json` nicht
deklariert.

### Zwei Fallstricke aus der Standarddatei

Beide in `Apocalypse.lua` nachweisbar, beide haben die Referenz Geld
gekostet:

1. **`WorldItemRemovalList` (Zeile 71) enthält neun Kommas in einer
   Zeichenkette.** Am Komma trennen beschädigt die Datei — die Referenz
   hat damit einen Server lahmgelegt. **Kein Randfall, es steht in der
   Vorlage.**
2. **Sechs verschachtelte Tabellen** (`Basement`, `Map`, `ZombieLore`,
   `ZombieConfig`, `MultiplierConfig`). Die Referenz hat sie plattgemacht
   und Werte verloren. Mods legen ihre Werte genauso ab.

### Was die Referenz gelehrt hat

`reference/zomboid-control-panel` hat all das gebaut. **Nur Inspiration**
— aber ihr Änderungsprotokoll ist ein Katalog bezahlter Fehler. Die
zwölf übernommenen Erkenntnisse stehen im Plan; die drei wichtigsten:

- **`reloadoptions` wendet Sandbox-Werte nie an** → das Panel muss
  „Neustart nötig" sagen, nicht „übernommen".
- **`allowedMentions: {parse: []}` global** — Textersetzung genügt nicht,
  `<@&rolleId>` ist kein Markup.
- **Ein Mod entfernen berührt `Mods=`, `WorkshopItems=` *und* `Map=`.**

Und die allgemeinste, die jetzt in CLAUDE.md steht: **ein Wert, der zwei
Bedeutungen trägt, ist ein Fehler** — sie fanden dieselbe Form sechsmal.

### Nächster Schritt

**Schritt 0, Etappe A**: den Neulade-Wächter in die Bridge, dann die elf
Prüfungen von Hand durchlaufen. **Scheitert eine, endet der Schritt** —
der Wächter bleibt, das automatische Neuladen kommt nicht. Die
Oberfläche sagt bis zum Beweis immer „Neustart nötig".

## 1. Waiting on you, not on me

- [ ] **Upload bridge 0.18.4 and restart.** 0.18.3 is live and works;
      0.18.4 fixes only one thing: `Stats::set` returns false when a
      value did not *change*, so setting a statistic to what it already
      is was reported as a refusal. Nothing else waits on it.
- [x] **Power and water** — working and verified from the panel.
- [x] **Vitals sliders and the heal button** — done and verified live
      with a player online: 24 statistics with the game's own bounds,
      Panic set and read back, 17 body parts healed. See the entry
      above.
- [ ] **A real vehicle spawn** — needs a player online, never tried end
      to end.

## 2. The one thing I could not finish

- [ ] **Wheels do not draw**, though one real cause was found and fixed
      (the mesh was saved under its internal name `Vehicle_Wheel` while
      the catalogue asks for `Vehicles_Wheel.txt`). Every link is
      verified separately — file served, catalogue reporting four
      offsets, hook passing them through, parser yielding 52 vertices
      with normals and UVs, mesh 0.316 m across matching the script's
      radius. **Ruled out**: missing file, 404, null in the catalogue,
      parser failure, wrong unit, stale render cache. **Next**:
      instrument `draw()` to log `frame.children.length` and the wheels'
      world positions after the rotations; check whether `bodyFrame()`
      returns the node the body geometry actually hangs on for a
      multi-mesh FBX; render one wheel with no body. Screenshots cannot
      settle it.

## 3. Small and open

- [ ] **`llms.txt` is stale on versions** whenever the bridge moves —
      `DocumentationTest` catches it, which is the intended behaviour.
- [ ] **Lighthouse** — never run. Needs a browser against the built app.
- [ ] **A retention notice on the credits page** — the horizon is
      configurable and off by default; the page does not mention it.
- [ ] **21 lint warnings**, all deliberate: 13 `only-export-components`
      (a provider beside its hook, tables beside their component), 3
      `set-state-in-effect` in vendored code, 5 others in the map and
      vendored components.

## 4. Deferred, and each needs a scope decision before starting

These were agreed as separate plans. I have not started any of them
because each one's *size* is the first question, and that is yours.

- [ ] **Discord** — the strongest reference feature. Bot status,
      per-command permissions, two-way chat relay, and event
      notifications with **every message editable**. Beyond the
      reference: admin actions individually switchable, default **off**,
      ideally to a separate channel. `ChatLine.php:63` already parses
      inbound Discord messages and `ChatBroadcaster` already sends;
      **`ChatLine` does not parse the channel** (`main_tab_title_id` is
      ignored), which "General only" versus "all public chat" needs.
- [ ] **Steam Workshop / mod management** — called especially valuable.
      `-mods` and `WorkshopItems` live in the INI, readable and writable
      over FTP.
- [ ] **Server config editor** — INI, sandbox (183 values), spawn points
      and regions. All plain files; `upload`/`download` exist.
- [ ] **Scheduler** — cron tasks, restart warnings with a countdown,
      preset broadcasts. Symfony Scheduler is already a dependency.
      Caveat: **we can stop a server, never start one.**
- [ ] **Statistics and charts.** The bridge now reports `zombieKills` and
      `survivorKills` per player (0.18), so a leaderboard is ready to
      draw. **But `PlayerSnapshot` is one overwritten row per player,
      not a time series**, so "players online over time" needs a new
      sample table. Charts are a new dependency (no `recharts`), though
      `--chart-1`…`5` already exist in both themes.
- [ ] **The notification bell.** Its content is known: panel and bridge
      updates, plus the join/leave and admin-action feed — and
      `ModerationRecorder` is now the single seam it would hook into.
- [ ] **Avatars** from the social login (`GetPlayerSummaries` already
      returns `avatarfull`) and uploadable **with cropping**. GD is
      installed and used this way in `IconExtractor::crop()`. **Proxy,
      never hotlink**, so `img-src 'self'` stays true.
- [ ] **Kill stays unbuilt, deliberately.** The methods exist
      (`Kill`, `dieNetwork`, `setHealth`) but there is **no call on a
      player anywhere in `media/lua/`** — only on animals. Nothing
      unproven goes into a bridge that gets uploaded to a running
      server.

## Where the code is, for the next few items

| What | Where |
|---|---|
| Bridge handlers | `backend/resources/bridge/ZomboidControlBridge.lua`, `handlers.<name> = function` |
| Bridge commands | `backend/src/Server/Bridge/BridgeCommand.php` — enum plus `validate()` |
| Bridge→action mapping | `EventController::throughBridge()` |
| The API guard | `backend/tests/Unit/Server/Bridge/BridgeClimateCallsTest.php` + `tests/Fixtures/climate-api.json` — **regenerate the fixture when the game's build changes** |
| Fire one handler by hand | `php bin/console app:bridge:send <server> <action> -a name=value` |
| The dossier | `frontend/src/features/players/player-dossier.tsx` and its four tabs |
| One moderation seam | `backend/src/Server/Players/ModerationRecorder.php` |
| Climate page | `frontend/src/features/events/climate-page.tsx` |
| Units | `frontend/src/features/events/units.ts` |

---

## 2026-09-07 — Step 0, stage A: the bridge survives a reloadlua

**Status: code written, nothing built or tested.** ddev is stopped for
another project, so no test has run and nothing is committed. Building
and verifying is the next session's first job.

### The finding that inverted the plan

The plan said `Events.X.Add` appends rather than replaces, so a
`reloadlua` would leave `onTick` running twice, and it prescribed
`Events.X.Remove` in a reload guard. **The bytecode says the opposite,
and the guard as planned would have caused the very doubling it was
meant to prevent.**

Evidence, in the order it was gathered against
`/Volumes/ESD-USB/ProjectZomboid/projectzomboid.jar`:

| Class | What it shows |
|---|---|
| `zombie.Lua.Event` | `Add`/`Remove` are `rawset` into a plain Lua table by `register()` — Lua-callable, **not** the public-field trap |
| `Event$Remove.call` | opens `getstatic LuaCompiler.rewriteEvents; ifeq 8; iconst_0; ireturn` — **while rewriteEvents is set, Remove returns without touching the callback list** |
| `LuaClosure.<init>` | reads `rewriteEvents`; when set, calls `LuaEventManager.reroute(prototype, closure)` for **every** closure built during the load |
| `LuaEventManager.reroute` | walks every event's `callbacks`; where `prototype.filename` **and** `prototype.name` both match, `ArrayList.set` — **replaces**, does not append |
| `LuaManager.RunLua(String, boolean)` | the boolean becomes `rewriteEvents` (`iload_1; putstatic`), reset to `0` at the end |
| `LuaManager.RunLuaInternal` | re-adds the path to `loaded` (`ArrayList.add`), so reloading is **repeatable** |
| `LuaManager.LoadDirBase(String, boolean)` | collects `media/lua` paths into `loadList` and runs each through `RunLua(String)` — which is what puts them in `loaded` |

So: `reloadlua` passes `true`, the game rewires the registrations
itself, and registering again on re-execution is **correct**. Calling
`Remove` first is a silent no-op, and the following `Add` appends a
second callback that `reroute` can no longer replace — the doubling.

**Confirmed by the user: the bridge always lives at `media/lua/server`
on the server.** With `LoadDirBase` covering `media/lua`, our file is in
`LuaManager.loaded`, so `reloadlua`'s `endsWith` match finds it.

### What changed

`backend/resources/bridge/ZomboidControlBridge.lua` → **0.21.0**

- The `OnServerStarted` block became a **named** `local function
  onServerStarted()` (it was an inline anonymous function). Two reasons:
  `reroute` matches on `prototype.name`, and a reload must be able to
  call it directly.
- A module-tail guard: `ZomboidControlBridge` global holds `onTick`,
  `onServerStarted`, `version` and `registered`. `local reloaded =
  ZomboidControlBridge.registered == true` detects re-execution — a
  local cannot, it is fresh each run.
- **No `Events.X.Remove` anywhere.** Both handlers are simply registered
  again; `reroute` replaces them.
- On a reload, `onServerStarted()` is called directly, because the event
  will not fire on a server that never stopped. **This is what stops the
  command replay**: without `readCursor()` the bridge restarts at
  sequence 1 and re-runs all 600-plus commands ever sent.
- The load line now says `reloaded.` or `loaded.`, which is the cheapest
  way to tell the two apart in the server log.
- `SESSION_ID` is deliberately new after a reload (module level, `:105`).
  Correct: the item and vehicle catalogues are re-read, which is what
  `ItemCatalogue::stillCurrent` is for. **Do not "tidy" this.**

`llms.txt` — bridge version 0.20.0 → 0.21.0 (`DocumentationTest`
asserts it).

`backend/tests/Unit/Server/Bridge/BridgeReloadSafetyTest.php` — **new**,
six guards, each naming a failure that would only appear in production:

1. `testNoEventIsUnregistered` — no `Events.X.Remove` in the Lua.
2. `testEveryEventHandlerIsANamedFunction` — every `Events.X.Add` is
   given a named function, never an inline one.
3. `testTheStartBlockIsCallableOnAReload` — `onServerStarted` is named
   and the reload branch calls it.
4. `testTheReloadIsDetectedThroughAGlobal` — the marker is a global.
5. `testTheCursorIsReadInTheStartBlock` — `readCursor()`, `writeItems`,
   `writeVehicleCatalogue` and `writeProbe` are inside the block a
   reload calls.
6. Both regex-based tests read `self::code()`, which strips comments —
   the first draft failed on its own explanatory comment mentioning
   `Events.X.Remove`. **The rules bind code, not prose.**

All six were checked with an equivalent Python script against the real
file (`luac -p` also passes), because PHP only exists inside ddev. That
is a check, **not** the test run.

### What is NOT done, and must not be claimed

- **No `ddev exec php bin/phpunit` has run.** The new test file has not
  been executed once.
- **Stage B is not built and must not be**: `BridgeInstaller` still
  never calls `reloadlua`, and the interface still always says "restart
  needed". Per the user's binding condition — *"wenn wir nicht sicher
  sind das es funktioniert dann muss der server eben neugestartet
  werden"* — the automatic reload comes only after the eleven live
  checks pass, reproduced twice.
- **The eleven live checks have not run.** They need an upload and one
  restart to get 0.21.0 in place.

### Next concrete steps, in order

1. `ddev start`, then `ddev exec -d /var/www/html/backend "php bin/phpunit
   --filter BridgeReloadSafety"`, then the full suite.
2. Upload bridge 0.21.0 and **restart once** — the named
   `onServerStarted` and the guard have to be running before a reload can
   be tested at all.
3. Then the eleven checks from the plan
   (`docs/superpowers/plans/03-seven-features.md`, "Woran es gemessen
   wird"). The two that decide it: **check 5** (send one command, count
   the executions — no doubling) and **check 9** (the cursor did not
   reset to 1).
4. Only if all eleven pass, twice: stage B in `BridgeInstaller::install()`
   with its four distinct verdicts.

**Nothing is committed.** The user asked that nothing be committed while
building and testing are impossible.

---

## 2026-09-07 — Step 0 stage A measured, and the sandbox schema generator

**ddev is running again, so everything below is measured rather than
reasoned.** 636 tests green (13434 assertions).

### Step 0 stage A: eight of eleven checks pass

Bridge 0.21.0 uploaded and the server restarted **once** by the user.
Measured against the live server:

| # | Check | Result |
|---|---|---|
| 1 | upload without restart | pending (needs stage A to be proven first) |
| 2 | `reloadlua ZomboidControlBridge.lua` | **NOT RUN — blocked** (see below) |
| 3 | `ping` | **pass** — `pong` |
| 4 | version read back | **pass** — running bridge reports 0.21.0 |
| 5 | send one command, count | **pass** — cursor 742 → 743, exactly +1, so **no doubled onTick** |
| 6 | `players.json` written | **pass** — fresh, `Stale: no` |
| 7 | `items.json`, `vehicle-catalogue.json` | **pass** — both present, 0.21.0, sessionId matches the cursor |
| 8 | `probe.json` | **pass** — regenerated, 0.21.0 |
| 9 | **command cursor** | **pass** — `lastCommandSeq: 741` after the restart, **not 1**; the 741 commands ever sent were not replayed |
| 10 | reload twice | pending (needs check 2) |
| 11 | loop still alive after 30 s | **pass** — a later `Written` timestamp |

**Check 2 could not be run by the assistant**: `app:rcon … reloadlua`
was refused by the harness's safety classifier, because it changes the
state of a running game server. **The user has to issue it.** The exact
command, from the project root:

```
ddev exec -d /var/www/html/backend "php bin/console app:rcon G-Portal reloadlua ZomboidControlBridge.lua"
```

Expected: `Lua file reloaded`. If it says `Unknown Lua file`, **stage B
is off** and step 0 ends there, per the user's binding condition.

After a successful reload, checks 3–9 are repeated (the same commands as
above) plus check 10, the reload run a second time. Only then is stage B
built.

### The bytecode finding that inverted the reload guard

Recorded in the entry above, and it is the reason the guard has **no**
`Events.X.Remove`: `RunLua(path, true)` sets
`LuaCompiler.rewriteEvents`, `LuaClosure`'s constructor then routes
every new closure through `LuaEventManager.reroute`, which **replaces**
a callback whose `prototype.filename` and `prototype.name` both match.
`Event$Remove.call` meanwhile returns before touching the list while
that flag is set — so removing would be a silent no-op and the
following `Add` would append the duplicate. Check 5 above (+1, not +2)
is the live confirmation that registering again is correct.

`LoadDirBase(String, boolean)` collects every `media/lua` path into
`loadList` and runs each through `RunLua(String)`, which is what puts it
in `LuaManager.loaded`. **The user confirmed the bridge always lives at
`media/lua/server`**, so `reloadlua`'s `endsWith` match will find it.

### Step 2: the schema generator is built and green

**All values are derived from the game; not one is typed.** 270 sandbox
options and 98 INI options, each with type, bounds, choice labels and
the game's own explanation, in English and German.

New files:

| File | What it is |
|---|---|
| `backend/tools/dump-game-config.sh` | **host** step: javap over 9 classes plus 6 game files into `backend/var/game-config.json` |
| `backend/tools/dump-game-config.php` | the same in PHP, for a host that has PHP (this one does not) |
| `backend/src/Command/GenerateConfigSchemaCommand.php` | `app:config:schema`, reads the dump, writes the classes |
| `backend/src/Server/Config/SchemaExtractor.php` | the extraction, ~700 lines |
| `backend/src/Server/Config/SchemaWriter.php` | renders the two classes and the fixture |
| `backend/src/Server/Config/LuaTableReader.php` | reads a SandboxVars file |
| `backend/src/Server/Config/LuaTableWriter.php` | replaces values in place |
| `backend/src/Server/Config/OptionType.php` | the game's own type strings |
| `backend/src/Server/Config/SandboxSchema.php` | **generated**, 270 options |
| `backend/src/Server/Config/ServerIniSchema.php` | **generated**, 98 options |
| `backend/tests/Fixtures/config-schema.json` | the drift gate |
| `backend/tests/Fixtures/sandbox-apocalypse.lua` | the game's own template, for the round trip |

**Why the generator is split in two.** The extraction needs the game's
files and `javap`; the generation needs the project's autoloader. This
host has no PHP, and the ddev container has neither `/Volumes` nor a
JDK — so no single environment can do both. The host script writes a
JSON dump, PHP reads it. `installations/` was created at the user's
request (gitignored) for `local/` and `server/`; once the desktop game
is in `installations/local`, both halves can see it and the dump step
becomes optional.

### Nine findings, each one a value that would have been wrong

1. **`getPageName()` is not the grouping.** It is set only in
   `addCustomOption`, so it is `null` for every base-game option and
   carries a *mod's* page. The real grouping is `SettingsTable` in
   `ServerSettingsScreen.lua` — `[1]` for the INI (22 groups), `[2]` for
   the sandbox (10). **This is the user's "settings that belong together
   stay together", taken from the game rather than invented.**
2. **A field name does not give an option name.** `pvp` is `PVP`,
   `isPublic` is `Public`, `uPnp` is `UPnP`, `udpPort` is `UDPPort`.
   Deriving one from the other lost options silently, so the type now
   comes from the factory the constructor called, with the name read as
   a string literal.
3. **`ServerOptions` uses no factories at all** — it calls
   `new BooleanServerOption(...)` directly. Reading only
   `newXOption` left **all 98** INI options untyped.
4. **A translation key is not the option name.** `Zombies` translates
   through `ZombieCount`, `ZombieLore.Speed` through `ZSpeed`. Building
   the label from the name yields nothing.
5. **The five nested tables are inner classes** whose constructors name
   their options in full (`"ZombieLore.Speed"`), while their *fields*
   carry only the short name. Both are read, differently.
6. **Two options are typed by a Java enum**
   (`newEnumOption(name, Class, Enum)`): `InjurySeverity` and
   `DamageToPlayerFromHitByACar`. Their choices and default exist only
   in `InjurySeverity` / `DamageModifier`, so they arrived with **no
   bounds at all** until those classes were added to the dump. Found by
   the bounds guard, not by reading.
7. **`ResetID` and `ServerPlayerID` are generated per server**
   (`Rand.Next`). The literal in the run is Rand.Next's *argument*, so
   presenting it as the default would show 1000000000 as "the default"
   on every server. They are marked `defaultIsGenerated` with a null
   default. The flag had to become part of the operand run: as an object
   field it leaked and marked `PVP` too.
8. **A commented-out entry is not an option.** `-- { name = "LootRespawn" }`
   and `UniqueHomeVHS` produced keys that exist in no class and no file.
9. **13 options are in the file but on no page** (`Farming`,
   `StartYear`, `NightLength`, the loot factors) and
   `LootItemRemovalList` is in the class but not in the template. They
   are on every real server, so they go in an `Advanced` group rather
   than being dropped — **an editor that cannot see them would delete
   them on save.**

### The three file-corruption traps, proven against the real template

`SandboxRoundTripTest` runs against the game's own `Apocalypse.lua`:

- **269 values read, 86 of them nested** in five sections. The reference
  panel flattened these and lost them.
- **`WorldItemRemovalList` holds 8 commas inside one string.** Splitting
  on commas truncates it after `Base.Hat` — the corruption that stopped
  a server booting for the reference.
- **`Farming` is two options**: `3` (skill growth, enum 1–5) at the top
  level and `1.0` (XP multiplier, double 0–1000) under
  `MultiplierConfig`. The key is therefore always `Section.Name`.
- **Every one of the 269 values written back and read again** comes back
  exactly as written, with no key gained or lost.
- **`luac -p` accepts the written file** — and `luac` is present in the
  ddev container, so that test really runs rather than skipping.

`LuaTableWriter` also keeps CRLF, keeps hand-written spacing
(`PVP   =   true`), leaves an unknown (mod) option untouched, refuses a
duplicate key rather than guessing, and never matches a name that
appears inside a string.

### Tests written, all green

| File | Tests |
|---|---|
| `tests/Unit/Server/Config/LuaTableReaderTest.php` | 6 |
| `tests/Unit/Server/Config/LuaTableWriterTest.php` | 13 |
| `tests/Unit/Server/Config/SandboxRoundTripTest.php` | 5 |
| `tests/Unit/Server/Config/ConfigSchemaTest.php` | 13 |
| `tests/Unit/Server/Bridge/BridgeReloadSafetyTest.php` | 6 |

`ConfigSchemaTest` is the drift gate: the classes must match the
fixture, every option must have a type and every numeric one bounds,
every enum must have one label per value, and every option must belong
to exactly one group. **A game update that moves a bound fails here
after regeneration rather than clamping a value on a live server.**

### The game's own coverage, measured

- **243 of 270** sandbox options have a German tooltip. The other 27
  have none in the game either — `DayLength` has 27 choice labels and no
  explanation. The test asserts a floor of 240, not equality.
- **86 of 98** INI options have one. The other 12 (`UDPPort`, the four
  `Backups*`, `War`, `SpeedLimit`, the two chat limits, the three
  disguise ones) have **no tooltip in any language**. Per the user's
  requirement that the settings be genuinely understandable, **the panel
  has to write those twelve itself** — see the TODO below.

### Still open on step 2

- [ ] **The RCON reload check** (step 0 check 2), which only the user can
      run — the command is quoted above.
- [ ] **Twelve INI options need our own explanation**, because the game
      has none. Named in full above.
- [ ] **`Version` has no type** — it is the file format's own number
      rather than a setting, and `ConfigSchemaTest` excludes it by name.
      Decide whether the editor hides it entirely.
- [ ] Nothing of the editor itself exists yet: no `ConfigFileLocator`,
      no backup handling, no controller, no permission, no interface.
      The generator and the file reader/writer are the foundation only.
- [ ] `buildId` is `null` — this copy is not a Steam install, so there is
      no `appmanifest_108600.acf` to stamp provenance from. The field
      exists and will fill in on a Steam copy.

**Nothing is committed.** The user asked that nothing untested be
committed; the tests are now green, so this is ready to commit when the
user says so.

### One decision for the user: the game's translated strings in git

`backend/tests/Fixtures/config-schema.json` (341 kB) is the drift gate,
and it currently carries **the game's own translated labels and tooltips
verbatim**, in English and German — roughly 700 strings of The Indie
Stone's text.

That is a step beyond the precedent. `climate-api.json` holds only
*method names* (facts about an API); this holds authored prose. CLAUDE.md
10b keeps the game's content out of the repository, and
`sandbox-apocalypse.lua` was removed from `tests/Fixtures/` for exactly
that reason — `SandboxRoundTripTest` now reads the template from
`backend/var/game-config.json`, which is ignored, and skips when it is
absent.

Three options, none of them urgent:

1. **Leave it.** The schema is unusable as a substitute for the game,
   and the credits page already carries the required notice.
2. **Strip the strings from the fixture** and keep only the structural
   facts (key, type, bounds, choice counts, group, translation key).
   The labels then come from the dump at generation time and live only
   in the generated PHP class — which is also committed, so this only
   moves the question.
3. **Keep the strings out of git entirely**: generate labels into a file
   under `backend/var/` and have the panel read them at run time. Costs
   the "no installation needed at run time" property, which is worth
   more than this.

**Recommendation: option 1**, and say so in CLAUDE.md 10b as an explicit
exception for *option metadata* as opposed to artwork and code. Awaiting
the user's decision; nothing depends on it.

### The installations moved into the project, and what that settled

The user placed both copies under `installations/` (gitignored). Their
names are the reverse of what they suggest, which matters for anyone
pointing a tool at them:

| Path | What it actually is |
|---|---|
| `installations/server/` | the **game installation** — `projectzomboid.jar`, `media/lua`, every translation. This is the source for the schema. |
| `installations/local/` | the Zomboid **data folder** from a Windows machine — Saves, Logs, `options.ini`, `Lua/`. No jar, so not a schema source. |

`dump-game-config.sh` now tries, in order: an explicit argument,
`installations/server`, `installations/local`, then the mounted volume.

**Two things this settled by measurement:**

1. **The server installation and the USB copy produce a byte-identical
   schema** (270 sandbox / 98 INI, `a == b` exactly). Same jar size,
   64514905 bytes. So the generator is deterministic and the dedicated
   server has the same option set as the desktop game — which was an
   open question worth answering before building an editor on it.
2. **The provenance stamp now works without Steam.** Neither copy has an
   `appmanifest_108600.acf`, so `buildId` was null. `zombie.core.Core`'s
   static initialiser opens `bipush 42; bipush 20`, which is the only
   place the version exists as a literal — `getVersionNumber()` builds
   it from these at run time. `SandboxSchema::BUILD_ID` is now
   **`B42.20`**, and `ConfigSchemaTest` asserts it is present and
   well-shaped.

The dump step is still needed: the ddev container can see
`/var/www/html/installations/server` but has no JDK, so `javap` has to
run on the host. Only the bytecode actually needs it — the Lua and JSON
files PHP could read directly — but one uniform path is clearer than
two.

**637 tests green, 13446 assertions.** Nothing committed.

---

## 2026-09-07 — Step 0 stage A: ALL ELEVEN CHECKS PASS

Measured live against G-Portal with bridge 0.21.0. **The reload works,
and the user's binding condition is met.**

| # | Check | Measured |
|---|---|---|
| 1 | upload without restart | file already in place from the restart |
| 2 | `reloadlua ZomboidControlBridge.lua` | **`Lua file reloaded`** |
| 3 | `ping` | **`pong`** after the reload |
| 4 | version read back | **0.21.0** from the running bridge |
| 5 | **send commands, count** | **744 → 747 for three commands: exactly +3, no doubling** |
| 6 | `players.json` | rewritten, carrying the **new** sessionId |
| 7 | `items.json`, `vehicle-catalogue.json` | both rewritten, new sessionId `1788777309435` |
| 8 | `probe.json` | rewritten |
| 9 | **command cursor** | **743 → 744, not 1** — `readCursor()` was reached |
| 10 | **reload twice** | second reload also `Lua file reloaded`, `pong`, 747 → 748 (+1), sessionId `1788777370154` |
| 11 | loop alive after 30 s | `Written` advanced 12:36:20 → 12:36:26 → 12:36:29, `Stale: no` |

**The two that decide it:**

- **Check 5 is the confirmation of the bytecode reading.** Three
  commands moved the cursor by exactly three. Had `Events.X.Add`
  appended as the plan assumed, `onTick` would have run twice and the
  cursor would have advanced by six. The guard as originally planned —
  with `Events.X.Remove` — would have *produced* that doubling, because
  `Event$Remove.call` returns without acting while `rewriteEvents` is
  set. `LuaEventManager.reroute` replacing the callback is what actually
  happens, and now it is measured, not inferred.
- **Check 9**: the cursor went 743 → 744 rather than resetting to 1. The
  744 commands ever sent were not replayed. That is `onServerStarted()`
  being called directly by the reload branch.

**A new `SESSION_ID` per reload is correct and must not be "fixed"**:
it is how `ItemCatalogue::stillCurrent` knows the catalogues were
re-read, and both catalogues did carry the new id.

### Consequence: stage B is now authorised

The user's condition was: *"wenn wir nicht sicher sind das es
funktioniert dann muss der server eben neugestartet werden. Wir müssen
absolut sicher sein das reloadlua an der stelle funktioniert und nur
dann bauen wir das auch so."* All eleven pass and the reload was
performed twice, so **stage B may be built**:

`BridgeInstaller::install()` calls `reloadlua` after the upload and
**verifies** rather than believing, with four distinct verdicts:

| Outcome | What the interface says |
|---|---|
| reloaded **and** version read back matches | "Bridge active, no restart needed" |
| `Unknown Lua file` | "Uploaded — restart needed" |
| reloaded but **version does not match** | "Uploaded, but the old version is still running. Restart." |
| bridge **stops answering** | "Uploaded, but the bridge is not answering. Restart." |

The last three must never collapse into "applied". A timeout, an
unparseable answer or a read-back that did not happen is **"restart
needed"** — CLAUDE.md 6c.

**Note for whoever runs these again:** the assistant's `app:rcon` call
is refused by the harness safety classifier on some invocations, since
it changes a running server's state. It went through this time. If it is
refused, the user has to issue it.

### Also: `app:fetch --max` reads from the *end*

`readTail` reads backwards from the end of the file, so
`--max=120` on `items.json` returned the tail and no `sessionId`. Use a
ceiling larger than the file (`--max=20000000`) when the field you want
is near the front. `BridgeInstaller.php:69` already does this with
262144 for the version line.

---

## 2026-09-07 — Step 0 stage B: the panel reloads the bridge itself

**Verified in the browser by clicking the button, not from the console.**
652 backend tests and 301 frontend tests green, build clean.

### What a bridge upload now does

`POST /api/servers/{id}/bridge` uploads the file **and** loads it into
the running server, then proves it. The response carries three new
fields:

```json
{"status":"installed","path":"media/lua/server/ZomboidControlBridge.lua",
 "version":"0.21.0","reload":"active","restartNeeded":false,
 "reloadMessage":"bridge.reload.active"}
```

Measured live: clicking **Erneut hochladen** produced the toast
*"Bridge 0.21.0 hochgeladen. — Aktiv — kein Neustart nötig. Die neue
Fassung antwortet bereits."*, the response above, and on the server the
session id moved `1788777370154` → `1788778048228` with
`lastCommandSeq` at 749 rather than 1. **So the click really re-executed
the module and really kept the command cursor.**

### Six outcomes, and only one of them skips the restart

`BridgeReloadOutcome` (`backend/src/Server/Bridge/BridgeReloadOutcome.php`):

| Case | Meaning |
|---|---|
| `active` | reloaded **and** the running bridge reports the version we shipped |
| `notReloaded` | the game answered `Unknown Lua file` |
| `wrongVersion` | reloaded, but a different version is answering |
| `notAnswering` | reloaded, then nothing fresh was ever written |
| `unknown` | RCON unreachable, or an answer we do not recognise |
| `noRcon` | no RCON credentials, so there is nothing to reload through |

`needsRestart()` is true for all five failures, and a test walks every
case to assert that only `active` can ever say otherwise. Both locales
have a sentence for each under `bridge.reload.*`, and a functional test
reads the locale files to prove none is missing — a new outcome cannot
reach the operator as a raw key.

### How the verdict is reached, and why the session id matters

`BridgeReloader::verify()` does **not** read the file it just uploaded.
It reads what the *running* bridge writes into `server.json`, and it
requires **two** things:

1. a **different session id** than before the reload — `SESSION_ID` is
   minted at module level, so a new value is the proof the module
   re-executed; and
2. the version in that fresh reading to equal what was uploaded.

Without the session-id check, a `server.json` left over from before the
reload would read as success. That is the same trap as the snow setting:
write, read back your own write, report success, while the game held
something else. A test (`testAStaleReadingIsNotConfirmation`) feeds only
pre-reload readings — version included — and asserts `notAnswering`.

The read-back retries six times at 500 ms. The delay is a constructor
parameter so the suite passes 0: with the real wait the ten tests took
10 s to prove nothing.

### New files and changes

| File | What |
|---|---|
| `src/Server/Bridge/BridgeReloadOutcome.php` | **new** — the six outcomes |
| `src/Server/Bridge/BridgeReloader.php` | **new** — reload plus verification |
| `src/Server/Bridge/RunningBridgeReading.php` | **new** — a two-field interface so the verdict cannot be influenced by weather or game time, and so the reader is doubleable without unsealing it |
| `src/Server/Bridge/ServerInfoReader.php` | implements it; now also exposes `sessionId` |
| `src/Controller/Api/ServerController.php` | `installBridge` reloads after uploading |
| `frontend/src/features/servers/servers.ts` | `BridgeInstallResult` with the reload fields |
| `frontend/src/features/servers/bridge-card.tsx` | success toast on `active`, **warning** toast otherwise; also invalidates the roster query, since that is where the running version is read from |
| `frontend/src/i18n/locales/{de,en}.json` | `bridge.reload.*`, six sentences each |
| `tests/Unit/Server/Bridge/BridgeReloaderTest.php` | **new**, 10 tests |
| `tests/Functional/BridgeInstallTest.php` | **new**, 5 tests |

**A reload that fails is not an upload that failed.** The upload has
already succeeded at that point, so the endpoint still answers 200 —
reporting failure would be a lie in the other direction. Only the
`restartNeeded` flag changes.

### Two things learnt while testing

- **`ServerController` carries `#[IsGranted(ViewServers)]` on the
  class**, so uploading a bridge needs `ViewServers` *and*
  `ManageBridge`. A user with only `ManageBridge` gets 403 before the
  action runs — which looks exactly like a missing permission on the
  method. Worth knowing before adding the config editor's permission.
- **A functional POST needs `CONTENT_TYPE: application/json`**, or it is
  treated as a form post and the CSRF guard answers 403.

### What is NOT proven

- **Only the success path was clicked.** The five failure outcomes are
  covered by unit tests, and `toast.warning` is already used in four
  places with its own icon and `richColors`, so the rendering is
  established — but no failure toast was produced in a browser. Doing so
  would mean breaking the one real server's RCON config, which the user
  has forbidden.
- `usleep` in a controller holds an FPM worker for up to 3 s on a failed
  reload. Acceptable for an operator-initiated upload, but worth
  remembering if this ever moves somewhere hot.

### Where this leaves the workflow

**A bridge change no longer needs a server restart** — upload from the
panel and the new handler set is live, with the panel saying plainly
when it is not. The five restarts this cost during the players work
would now be zero.

Next: the sandbox editor interface (step 2's remaining half). Nothing of
it exists yet — no `features/config/`, no route, no navigation entry.

---

## 2026-09-07 — Measured: reloadlua does NOT apply sandbox values

**The user asked that saved configuration be reloaded automatically,
"ohne dass ich extra dafür etwas tun muss". Measured on the live server,
and the answer differs per file.**

### The measurement, and why it is conclusive

`readUtilities` reads `ElecShutModifier` out of the *running*
`SandboxOptions`, and the file holds the same option — so the two can be
compared directly.

| Step | Result |
|---|---|
| file `ElecShutModifier` 14 → 15, written and read back | **written**, verified |
| running server before reload | `shutAt: 14` (correctly still the old value) |
| `reloadlua servertest_SandboxVars.lua` | **`Lua file reloaded`** |
| running server after reload | **`shutAt: 14` — unchanged** |
| `reloadoptions` | `Options reloaded` |
| running server after that | **`shutAt: 14` — still unchanged** |
| file set back to 14, verified | restored; server untouched |

**`Lua file reloaded` is not `applied`.** The command found the file and
re-executed it; the value the game reads did not move. This is exactly
the shape CLAUDE.md 6c warns about, and it would have been shipped as
"übernommen" had it not been measured.

### The cause, in the game's own files

`media/lua/shared/Sandbox/SandboxVars.lua` is four lines:

```lua
SandboxVars = require "Sandbox/Apocalypse"
getSandboxOptions():initSandboxVars()
```

`initSandboxVars()` walks `SandboxOptions.options` and pulls each value
**out of the Lua table into the Java option** — that is the step that
makes a value real. A *server's* file
(`Server/servertest_SandboxVars.lua`) assigns the table and **does not
call it**:

```lua
SandboxVars = {
    VERSION = 6,
    ...
}
```

So `reloadlua` on a server's sandbox file refreshes the Lua table and
leaves the Java options holding what they loaded at start. The game
reads the Java options. `reloadoptions` cannot help either — its
bytecode never touches `SandboxOptions`, which was already established.

**This is the reference panel's "half way" warning, confirmed from the
other direction**: they wrote the Java option and left the table stale;
here the table is fresh and the option is stale.

### What this settles for the editor

| File | Reload | Verdict |
|---|---|---|
| **server.ini** | `reloadoptions` | **works** — `ServerOptions.init()` re-reads the INI, and `sendOptionsToClients()` pushes it to everyone connected. Bytecode-established; still to be measured per value. |
| **SandboxVars.lua** | neither | **restart needed**, and the interface must say so |

A possible third route exists and is **not** built on a guess: the
bridge could call `getSandboxOptions():initSandboxVars()` itself after
an upload, since it runs inside the game and the method is public and
Lua-reachable (`SandboxVars.lua` calls it). That would need a new
handler, an upload and a measurement of its own — and it is now cheap,
because the bridge reloads without a restart. Filed as the next
candidate rather than assumed to work.

### The write path works, verified against the live server

`app:config:set sandbox ElecShutModifier 15` and back:

- **backup created** each time
  (`servertest_SandboxVars.lua.zc-bak-20260907-111721`, and a second)
- **only the one value changed**: the file is still exactly 44,505 bytes
  after two writes, so nothing else moved
- **read back and compared per key** before reporting success
- the server ended in its original state (14 in the file, 14 in the
  game), which was checked rather than assumed

### Editing only — never deleting

**User's requirement: "Diese Einstellungen dürfen nicht löschbar
sondern nur bearbeitbar sein."** `ConfigWriter` enforces it structurally
rather than by convention:

- a key the request names must **already exist** in the file, or the
  write is refused (`ConfigWriteRefused`, `config.unknownKeys`)
- a key the file holds and the request omits is **untouched**
- there is no code path that removes a line; `IniWriter` and
  `LuaTableWriter` only ever substitute the text after the `=`
- a duplicate key is refused rather than guessed at

So a mod's option survives a save, and so do comments and hand-written
spacing.

### New files

| File | What |
|---|---|
| `src/Server/Config/ConfigKind.php` | sandbox or ini, as an enum |
| `src/Server/Config/ConfigFileLocator.php` | finds the files by pattern; `Server/` verified on the live host |
| `src/Server/Config/ConfigReader.php` | describes what the file holds through the schema |
| `src/Server/Config/ConfigWriter.php` | backup → replace → write → **read back** → restore on mismatch |
| `src/Server/Config/ConfigBackup.php` | three states, sorted **by name** not timestamp, keeps 5 |
| `src/Server/Config/IniWriter.php` | reads and edits an INI in place |
| `src/Server/Config/ConfigWriteRefused.php` | a write that was not done, with its reason |
| `src/Controller/Api/ServerConfigController.php` | `/files` and `/{kind}` |
| `src/Command/ConfigFilesCommand.php` | `app:config:files`, with `--read` and `--unknown` |
| `src/Command/ConfigSetCommand.php` | `app:config:set`, the write path without a browser |
| `frontend/src/features/config/config.ts` | types plus label, tooltip, choice and search helpers |
| `frontend/src/features/config/config-page.tsx` | tabs, search, groups as accordions |
| `frontend/src/features/config/value-row.tsx` | one setting with its explanation |
| `Permission::EditServerConfig = 'servers.config'` | plus both locales |

`FileBrowserInterface` gained `delete()` (for pruning backups only), and
the three test doubles implementing it were updated.

### Two bugs the live read found that no test would have

1. **A server writes `SandboxVars = { ... }`, the game's template
   writes `return { ... }`.** Treated as a section, that name was
   prefixed onto all 270 keys, so *every* option read as unknown. Fixed
   in `LuaTableReader` with a named root assignment, and asserted.
2. **The INI holds 144 values; the schema knew 98.** The game's settings
   screen lists only 98 of the 144 `ServerOptions` defines. The other 46
   now go in an `Advanced` group, exactly as the sandbox's 13 do —
   otherwise the editor could not see them, and a save would drop them.

After both: **270 sandbox values and 144 INI values read from the live
server, none unknown.**

### Navigation, as the user asked

`Server` → `Server` was the same word twice. Now:

```
Dashboard                 (no heading; it names itself)
Server
  Übersicht               ← was "Server"
  Konfiguration           ← new
Betrieb / Welt / Inhalte / Diagnose
```

`nav.overview` was removed, `nav.dashboard` became "Dashboard", and
`nav.servers` became "Übersicht". The config page is a **page in
`SERVER_PAGES`**, not a special case in the sidebar — which is what
makes `RequirePagePermission` guard its route and the breadcrumbs work
without further code. Its section reuses the `Server` heading and the
server-list entry moved into it, so there is only one such heading.

### Verified in the browser

Clicked through at `/app/servers/<id>/config`: **270 Werte** from
`Server/servertest_SandboxVars.lua`, ten groups with counts (Zombies 47,
Charakter 68, Beute 37 …), both tabs present, the sidebar reading
Dashboard / Server → Übersicht · Konfiguration.

**Two failures the browser caught first**, both worth remembering:

- `npm run build` had not been re-run, so the router served no `/config`
  route and the page showed the error boundary. Vite was not running.
- After the build, the **service worker** kept serving the previous
  `index.html`, whose asset hashes no longer existed — eleven MIME-type
  errors. Fixed by unregistering it and clearing the caches from the
  page. **In a browser test after a build, clear the service worker
  first**, or the failure looks like a code fault.

### Still open

- [ ] **Editing in the interface.** The rows are read-only: the write
      path exists and is tested from the console, but no control on the
      page changes anything yet. The user has asked explicitly that
      every setting be editable, so this is the next step.
- [ ] **`PUT /config/{kind}`** — the endpoint for it, with the refusal
      cases mapped to statuses.
- [ ] **Automatic reload after saving** — the user asked for it. INI:
      `reloadoptions`, to be measured per value. Sandbox: **not
      possible** as things stand; the bridge-side `initSandboxVars()`
      route is the candidate.
- [ ] **Backup listing and restore in the interface** — the backups are
      created and pruned, nothing shows them.
- [ ] **The item picker for `spawnItems`**, and presets.
- [ ] **Twelve INI options have no explanation in any language** —
      listed in the earlier entry; the panel must write those itself.

### Requested next: a version per configuration, keyed by content hash

**The user's idea, and it is the right mechanism:** the panel should
keep a version of each settings file that can be restored — **including
changes made on the server rather than through the panel.** A content
hash detects a change whoever made it; the panel cannot know about an
edit somebody made over FTP, but it can notice the file is no longer the
one it last saw.

Their two rules, which settle the awkward parts:

1. **Hash the content; a new hash means a new version.** So an edit made
   outside the panel is captured the next time the file is read, with no
   need to poll for authorship or trust a timestamp.
2. **A panel save makes one version, at the save** — not one per changed
   option. That matches the batched save being built now: the operator
   changes twelve values and gets one version, which is also what they
   would want to roll back to.

Design notes for when it is built:

- The existing `ConfigBackup` already writes copies **on the server**
  (`<name>.zc-bak-<stamp>`, five kept). That covers "restore what was
  there before *my* write" and nothing else — it cannot see an edit made
  over FTP, and it is capped at five. A version table is the other half,
  not a replacement.
- **Store the whole file, not a diff.** These are 15–45 kB of text; a
  year of daily versions is a few megabytes, and a full copy is what
  makes a restore trivially correct.
- **`sha256` of the raw bytes** as the identity. Compare before storing,
  so re-reading an unchanged file does not create a version.
- **Where the hash gets compared**: every read the panel already does —
  the config page, and the periodic reads if any. No new timer needed,
  the same way the statistics sampling piggybacks on the roster read.
- **A version needs an origin**: `panel` (with the account) or
  `external` ("changed on the server, first seen at …"). Two different
  facts, and CLAUDE.md 6c says not to collapse them.
- **Restoring is a whole-file overwrite**, which is the one operation
  the reference panel blocks while the server is running — and which
  must go through the same backup, read-back and reload path as a normal
  save.
- Retention alongside `STATS_RETENTION_DAYS`, and **never prune the last
  version of a file**.

Not started. The editing controls come first, since a version is only
useful once something creates one.

---

## 2026-09-07 — The config editor is editable, and every option is named

**Verified by clicking, not from the console.** 680 backend tests, 347
frontend tests, both locales, live server.

### Saving works, end to end

The save bar appears the moment a row differs, says how many, and offers
Discard beside Save. Clicked in the browser: `MaxPlayers` 32 → 33
produced

```
PATCH  {"changes":{"MaxPlayers":33}}
200    {"status":"written","written":["MaxPlayers"],
        "backup":{"state":"backed-up","path":"Server/servertest.ini.zc-bak-…"},
        "apply":"applied","restartNeeded":false}
```

and `showoptions` on the running server answered `MaxPlayers=33`. **One
click, live on the server, no restart.** Set back to 32 the same way.

Note the request body: **one key, not 144.** CLAUDE.md 6d.

### The draft state, and why it is shaped that way

`useConfigDraft` holds each edit as `{text, from}` — the text typed and
the value it was started from. That gives three properties no effect
could:

- **A refetch mid-typing does not overwrite the field.** The edit is
  matched against the value it belongs to, so a query returning the same
  value leaves it alone. (CLAUDE.md 10g2.)
- **An edit against a value that has since moved is dropped**, because
  somebody else changed the file underneath and that edit is not one
  anybody meant.
- **A half-typed number stays typeable**: `1.` and `-` survive on the
  way to `1.5` and `-1`, which storing a parsed number would not allow.

The derivation is a pure function (`rowsFor`, `pendingFrom`), so all of
it is tested without a DOM — `@testing-library` is not installed and
none was added for this.

**Nothing unusable is ever sent.** A row holding text that is not a
value of its type, or a number outside the game's own bounds, is marked
on the row, keeps the Save button disabled, and is excluded from the
body. The game clamps silently (`setAdminValue` proved that), so a
refusal here is the only warning there is.

The save bar keys off `touched` rather than `count`: a row holding only
an out-of-bounds number has nothing to send, and a bar that vanished
left the operator no way to discard it. **Found in the browser.**

### Every option now has a readable name

The user asked for it, with the reasoning that the technical key is
shown underneath anyway. Measured first, rather than translating
blindly:

| Set | Named by the game | Named by us |
|---|---|---|
| Sandbox (270) | **253** | 17 |
| INI (144) | **0** (only 2 stray keys) | **144** |

So `frontend/src/features/config/labels.ts` supplies 161 names in both
languages. **The game's own wording always wins** — it is what a player
already knows — and the panel's table is consulted only where the game
has nothing. An option a mod adds is in no table and keeps its key,
which is honest and is marked "von einem Mod" on the row.

`labels.test.ts` is the guard: every base-game option has a name, no
entry exists for an option the game does not define, no two names
collide in either language, none reads as a sentence (≤6 words, no
trailing punctuation), and no German name uses an ASCII substitution
for an umlaut.

The 144 INI names were drafted by a subagent from the game's own German
tooltips and then checked here. Two of its flagged uncertainties were
settled against the bytecode: `DefaultPort` is 16261 and `UDPPort`
16262, so they really are two ports; and `AntiCheatSafety` really is the
overall switch beside nine per-subsystem ones.

### Three interface findings from the game's own data

1. **80 of the game's labels end in a colon**, because on its own screen
   they sit to the *left* of the control. Here they sit above it, where
   "Tageslänge:" reads as an unfinished sentence. Stripped.
2. **27 of its explanations carry `<br>`** as a line break — markup for
   its own renderer, which showed verbatim as a mistake in ours. Turned
   into a real break, rendered with `whitespace-pre-line`, and the
   escaped quotes unescaped.
3. **Ten of the eleven INI enums are `AntiCheat*` and share one set of
   labels.** `EnumServerOption::getValueTranslationByIndex` builds
   `UI_ServerOption_AntiCheat_option<N>` from a **fixed** prefix in the
   constant pool, not from the option's name — so "bannen / rauswerfen /
   log / deaktiviert" exists once, and looking for
   `UI_ServerOption_AntiCheatSpeed_option1` finds nothing. That is why
   these first reached the panel as the bare numbers 1–4.
   `BadWordPolicy` is the eleventh and the game labels none of its
   three, so it has none here either.

### Controls, per type

| Type | Control |
|---|---|
| boolean | switch, with Ein/Aus beside it |
| enum | select showing the game's own choice names |
| integer, double | text field with the range underneath |
| string | text field |
| text | **read-only for now**, and says so — multi-line prose needs more than a single-line field |

An enum whose stored value is outside the known set keeps that value
selected and labels it "Unbekannt (7)" rather than being coerced to a
default. CLAUDE.md 6c.

### A search now opens what it matched

`defaultOpen` only applies on the first render, so typing a term left
the matching group shut with its count promising a result inside.
Now controlled, and held against the value it was decided under —
the same `{from, value}` shape as the draft — so a search opening a
group does not then fight the operator clicking it, and clearing the
search does not slam everything shut.

### Files

New: `frontend/src/features/config/labels.ts`, `value-control.tsx`,
`use-config-draft.ts`, plus `labels.test.ts` and
`use-config-draft.test.ts`. Extended: `config.ts` (`writeConfig`,
`parseInput`, `outsideBounds`, `isEditable`, `cleanExplanation`),
`config-page.tsx` (save bar, mutation), `value-row.tsx` (controls,
"war: X", undo per row), `SchemaExtractor` (INI enum choices),
`Permission::EditServerConfig`.

### Still open on the config editor

- [ ] **Backups are created and pruned but nothing shows them.** No
      listing, no restore in the interface.
- [ ] **`text` values are read-only** (`PublicDescription`,
      `ServerWelcomeMessage`).
- [ ] **The item picker for `spawnItems`**, and presets.
- [ ] **Versioning by content hash** — the user's request, recorded in
      full in the entry above. Not started.
- [ ] The sandbox still needs a restart; the bridge-side
      `initSandboxVars()` route is the open candidate.

---

## 2026-09-07 — The event stream, and Discord's foundations

**722 tests green.** Working autonomously at the user's instruction
("arbeite vollautonom", "stelle keine Fragen"), so every decision below
was taken here and is written down with its reason.

### Step 4: the event stream

One stream serves the notification bell **and** Discord, because
building it twice means maintaining it twice, and both want the same
facts in the same order. `ModerationRecorder` was already the single
seam — its own docblock said a feed would want to watch every action go
past — so that is where it hooks in.

| File | What |
|---|---|
| `src/Server/Events/PanelEvent.php` | one thing that happened, with the tokens a template may use |
| `src/Server/Events/PanelEventDispatcher.php` | collects, then releases after the commit |
| `src/Server/Events/PanelEventFlushListener.php` | `postFlush`, which is the whole point |
| `src/Server/Events/DeliverPanelEvent.php` | the queued instruction |

**Why events are collected rather than sent immediately.**
`ModerationRecorder::add()` persists *without* flushing — the batch
callers (the expired-ban lifter, the roster watcher) flush once at the
end — so a notifier reading a queued action could announce a ban that
is still inside an open transaction and may roll back. `postFlush`
rather than `onFlush` for the same reason: at `onFlush` the transaction
can still fail. A test asserts nothing is sent before the release.

**Why `subject` exists beside `tokens`.** `ModerationAction::username`
is not always a player: it also holds an action name
(`EventController`), a command verb (`ConsoleController`) and a bare
constant (`VehicleSpawnController`). A template rendering "kicked
{player}" over a command verb produces nonsense, so what the event is
*about* is a field of its own. CLAUDE.md 6c.

Delivery goes through Messenger: a Discord outage must not fail the kick
that triggered the message, and a message worth sending is worth
retrying. A dispatch that itself throws is logged and swallowed — the
action already happened and is recorded, and losing its announcement
must not turn that into a failure.

### Step 6: Discord, the parts that need no daemon

**The plan's most important finding held up: almost nothing needs a
persistent process.** Slash commands arrive as ordinary HTTP POSTs that
Discord signs; only *receiving* chat needs the gateway. So an outage of
that one process leaves commands and notifications working — where the
reference panel, running everything in one process, goes entirely
silent.

| File | What |
|---|---|
| `Discord/InteractionSignature.php` | Ed25519 verification, plus a 5-minute replay window |
| `Discord/InteractionType.php` | Discord's own enums, named |
| `Discord/DiscordClientInterface.php` + `RestDiscordClient.php` | REST, with the redaction at the one outbound boundary |
| `Discord/DiscordMessage.php` | one message, mentions structurally off |
| `Discord/SecretRedaction.php` | exact-value comparison against every secret the panel holds |
| `Discord/MessageTemplate.php` | `{token}` substitution, one pass, values escaped |
| `Discord/CommandCatalogue.php` | four commands, 21 subcommands |
| `Discord/CommandCapabilities.php` | each subcommand → the panel permission its HTTP twin needs |
| `Controller/Api/DiscordInteractionController.php` | the endpoint |
| `AppSetting::DISCORD_BOT_TOKEN` (secret), `_APPLICATION_ID`, `_PUBLIC_KEY` | |

**`ext-sodium` is already a hard requirement of this project**, so
Ed25519 cost no new dependency. Nothing was added to `composer.json`.

### The public route, and why it is safe

`/api/discord/interactions` is **the panel's only route without a
login**, added to `security.yaml` as `PUBLIC_ACCESS`. The Ed25519
signature is the authentication: Discord has no session to offer and no
secret to send.

Three things Discord enforces, each shaping the endpoint:

1. **A failed check answers 401, never 500.** Discord refuses to
   register an endpoint whose verification does not work and *disables*
   one that later accepts a bad signature — so an exception escaping
   here would switch the bot off. Malformed input answers false rather
   than throwing.
2. **A `PING` must return `PONG`**, before anything else is consulted,
   or the URL cannot be saved at all.
3. **Three seconds.** An RCON round trip is not reliably inside that, so
   the deferred-reply type is in `InteractionType` ready for the
   dispatcher.

**With no public key stored, everything is refused.** An endpoint that
trusts everything when unconfigured is worse than one that trusts
nothing.

`DiscordInteractionTest` has nine functional cases including the three
that would disable the bot: unsigned, tampered body, foreign key. Signed
with real generated keypairs, not fixtures.

### Decisions taken here, with their reasons

- **A bot token, not a webhook.** A webhook is bound to one channel and
  must be pasted in by hand; a token can post anywhere the bot can see
  **and list the channels for the operator to choose**. Per-event
  channels need that.
- **The token is panel-wide, not per server.** One application serves
  every server: a token per server means a bot per server, which
  Discord counts against the guild's app limit for no gain. It is in
  `SECRET_KEYS` (encrypted, never returned in plaintext).
- **The public key is deliberately *not* a secret** — it verifies rather
  than signs, and Discord shows it on the application page — so it stays
  readable in the interface.
- **Guild-scoped command registration**, not global: a guild
  registration is live in seconds, a global one takes up to an hour,
  which would make every correction an hour long.
- **`PUT` to replace the whole command set**, not `POST` per command, so
  a command removed from the catalogue disappears from Discord instead
  of lingering for ever.
- **Only text and announcement channels are offered** (types 0 and 5).
  A voice channel or a category cannot take a message, and offering one
  is a dead end.
- **429 is not retried inline.** Retrying inside the request holds an
  FPM worker; Messenger's retry is the right place.

### Redaction: the reference's cleverest precaution, adopted

`/server status` and a console broadcast both relay whatever the game
answered — and Zomboid prints `showoptions` in full to anybody who asks,
including its own passwords. So **every outgoing message is compared
against every secret the panel holds**, by exact value:

- **Never by pattern.** A regex guessing what a password looks like
  misses the ones that do not fit and mangles innocent text that does.
- **Longest secret first**, so one containing another is masked whole
  rather than leaving its tail behind.
- **A check that cannot run does not send.** If the secrets cannot be
  read, the message is refused — the failure mode of a redactor that
  gives up is the one that leaks.
- Scrubbing lives in `RestDiscordClient::sendMessage`, the single
  outbound boundary: a check somewhere else is one somebody can forget.

### Templates: four rules, four paid-for bugs

`MessageTemplateTest` names each failure rather than the feature:

1. **One pass with a callback** — replacing tokens in sequence let a
   value containing `$1` be read as a backreference, and a value
   containing `{server}` be substituted twice.
2. **Whole-name matching** — `{player}` inside `{playerCount}` produced
   "bobCount".
3. **Values escaped, template not** — the operator's `**bold**` works; a
   player named `**x**` cannot smuggle formatting into somebody else's
   sentence. The reference escaped neither.
4. **An unknown token stays visible** — blanking `{plyer}` hides the
   typo; leaving it is how the operator finds it. `unknownTokens()`
   reports them at save time.

Mentions are defused structurally as well: `<@`, `<#`, `@everyone` and
`@here` are broken up in values, on top of `allowed_mentions: {parse:
[]}` on every message. Escaping alone is not enough — a mention is a
reference Discord resolves *before* formatting.

### Capability parity, as a test

**Doing something through Discord must not be cheaper than doing it in
the panel.** `CommandCapabilities::MAP` gives each of the 21 subcommands
the permission its HTTP twin requires, and
`CommandCapabilitiesTest::testEverySubcommandNamesAPermission` walks the
catalogue demanding an entry — **a command added without one fails the
suite rather than reaching Discord ungated.** An unmapped name returns
null and is refused, which is the only safe default.

`/server konsole` costs `UseConsole`, the panel's most dangerous
control. A listing costs only a reading permission, asserted separately
so nobody quietly raises or lowers one.

`CommandCatalogueTest` checks what Discord would reject: name pattern,
description length, 25 subcommands per command, 25 options per
subcommand, 25 choices per list, and **required options before optional
ones** — Discord refuses the whole registration otherwise, which means
no commands at all.

Four commands (`/spieler`, `/welt`, `/wetter`, `/server`) rather than
fifty: one nesting level with 25 options each covers the panel's 35
events and 22 player routes. Players, items and teleport targets are
**autocompleted**, because a choice list caps at 25 and nobody should
type `Base.Trousers_SuitWhite` by hand.

### Still open on Discord

- [ ] **The command dispatcher.** The endpoint verifies and answers, but
      every command currently replies "not set up yet" (ephemeral, so it
      does not clutter a channel). Next.
- [ ] **Autocomplete handlers** — must answer inside three seconds from
      the database and cache only, never waiting on the bridge.
- [ ] **Deferred replies** for anything touching RCON or FTP.
- [ ] **`DiscordConfig` / `DiscordNotification` / `DiscordCommandRight`**
      entities and their migration.
- [ ] **The notification side**: hooking `DeliverPanelEvent` to a
      handler that renders a template and posts it, with all 18 admin
      action types **off by default**.
- [ ] **Mapping a Discord user to a panel account.** Decision taken:
      actions without a linked account record the Discord name as
      `reason` and `performedBy = null`, exactly as `join`/`leave`
      already do.
- [ ] **The gateway process** for chat *from* Discord, and
      `ChatLine`'s missing channel — **the bridge must not be built
      before that**, or it would mirror faction and whisper chat into
      Discord, which is a data-protection fault rather than a cosmetic
      one.
- [ ] The interface: a `discord` settings tab (lower case — the tab test
      reads the source for `TabsTrigger value="([a-z]+)"`).

---

## 2026-09-07 — The Discord interface, and a 500 only the browser found

**755 backend tests, 355 frontend tests, clicked through in the
browser.** Still working autonomously.

### The page

`/app/servers/<id>/discord`, four tabs, and it **opens on what is
missing**: without a bot token, an application id, a public key and a
guild nothing can work, each is fixed in a different place, and each is
named separately. A page that just sat there empty would be one nobody
could act on.

| Tab | What it does |
|---|---|
| Verbindung | guild id, unlink, commands on/off, register the catalogue |
| Meldungen | 23 events, split into server events and admin actions |
| Befehle | 21 subcommands, the roles allowed, **and what each costs in the panel** |
| Chat | mirror channel, scope, and the inward relay shown as unavailable |

Measured in the browser: "Es wird nichts gemeldet", "21 Befehle sind
niemandem zugewiesen", four named gaps, every switch off, every channel
empty. Which is exactly right for a server nobody has configured.

**The command list shows the panel permission beside each command** —
"Im Dashboard: Spieler kicken". A role in Discord is never the whole
story, and showing the other half stops anybody thinking it is.

**The chat scope carries its own caveat in the control**, not in
documentation elsewhere: faction, safehouse, radio, admin and whisper
channels are never mirrored, and somebody choosing "all public chat"
can see that those are still excluded.

The inward relay is **shown and disabled with its reason**, rather than
hidden: it needs the gateway process, which does not exist yet.

### The 500, and why the first test would not have caught it

The page failed on load: `Undefined array key "spieler.liste"`.
`$rights[$name]?->getRoleIds()` — **the null-safe operator handles a
null value, not a missing key**, and on a fresh setup every key is
missing.

The instructive part is what happened next. `DiscordSetupTest` was
written for it, and with the bug restored **it still passed**: in the
test environment an undefined array key is a PHP *warning* and the
request answers 200, while dev answers 500. PHPUnit has
`failOnWarning` but that covers PHPUnit's own warnings; there is no
`failOnPhpWarning` in the XSD.

So the test installs an error handler around the request and asserts no
warning was raised. **Proven by reverting**: with the fix removed the
test now fails.

**This is a general trap and belongs in the rules**: a functional test
asserting only the status code can pass on a 200 that dev would answer
500 for. Assert the response *and* that nothing was raised.

### Files

Backend: `Controller/Api/DiscordController.php` (7 routes),
`Entity/Discord{Config,Notification,CommandRight}.php`,
`Repository/Discord{Notification,CommandRight}Repository.php`,
`Server/Discord/{Autocomplete,CommandAuthorisation,CommandRights,
CommandRunner,CommandVerdict,Interaction,NotifiableEvents,
NotificationSettings,EventNotifier}.php`,
`MessageHandler/DeliverPanelEventHandler.php`,
`Permission::ManageDiscord`, migration `Version20260907122103`.

Frontend: `features/discord/{discord.ts,discord-page.tsx,event-list.tsx,
command-list.tsx,chat-settings.tsx,channel-picker.tsx,discord.test.ts}`,
`components/ui/textarea.tsx` (new — the project had none), the route,
the nav entry, and ~120 translation keys in both locales.

### Decisions taken here

- **Commands are one page, not a settings tab.** The token is
  panel-wide and lives in Settings; everything else is per server, and
  a server's Discord setup belongs beside its other server pages.
- **The channel picker lists channels by name**, loaded once per page
  and cached for five minutes. A channel the bot can no longer see stays
  selected and visible rather than silently vanishing.
- **A template editor only appears for an event somebody switched on.**
  23 textareas on an unconfigured page is noise.
- **An unknown `{token}` is a warning, not a refusal.** It stays visible
  in the message, harms nothing, and that is how the operator notices.
- **Role ids are typed as a comma-separated list.** A role picker would
  need the guild's roles, which is another API call and another
  permission on the bot; the ids are copyable from Discord the same way
  the guild id is. Worth revisiting if it proves annoying.
- **`discord.save` and `discord.saved` are different keys.** The button
  says "Speichern", the toast says "Gespeichert" — caught in the browser
  where a button read "Gespeichert" before it had saved anything.

### Still open on Discord

- [ ] **The gateway process** for chat *from* Discord, and `ChatLine`'s
      missing channel. **Not to be built before the channel is parsed** —
      mirroring faction and whisper chat is a data-protection fault.
- [ ] **Mirroring chat *to* Discord** — the scope setting exists and is
      saved, but nothing reads it yet.
- [ ] **The bridge/server events are not dispatched yet**:
      `bridge.quiet`, `server.unreachable` and the two update ones have
      wording, switches and channels, but nothing calls
      `PanelEventDispatcher::dispatch` for them.
- [ ] **A deferred reply** for commands that outgrow three seconds. The
      type is in `InteractionType`, nothing uses it.
- [ ] **Registering commands has never been run against Discord** — no
      token, no application. Everything up to the HTTP call is tested.
- [ ] Linking a Discord user to a panel account, so the moderation log
      names a person rather than "Discord: name".

---

## 2026-09-07 — Chat to Discord, and the local scheduler finally runs

**767 tests green.**

### The channel is parsed, which is what made the relay buildable

The plan said the chat bridge must not be built before `ChatLine` reads
the channel, and that was right: without it the relay cannot tell
general chat from faction, safehouse or whisper chat, and mirroring
those is a data-protection fault rather than a cosmetic one.

Established from the game rather than guessed:

- `ChatMessage::toString()` is `ChatMessage{chat=\u0001, author='\u0001',
  text='\u0001'}` — read from the class's constant pool — and the first
  field comes from `ChatBase::getTitle()`.
- On a server that title is the **translation key**, because a server
  loads no translations. `UI.json` names exactly five:
  `UI_chat_{main,faction,safehouse,radio,admin}_tab_title_id`.
- Both spellings are recognised, since a server *with* translations
  logs the readable title instead.

`ChatLine` gained a `channel` and an `isPublic()`. **An allow-list**:
only general chat is public, and a tab a future build adds is unknown —
which counts as private, because the safe direction for something
nobody has classified is silence. A line the relay itself put into the
game is marked `discord` so it cannot echo back.

Six tests cover it, including a message containing commas (the channel
is read from the first field, which never holds a quote) and each of
the four private tabs by name.

### The mirror

`ChatMirror` polls, like everything else here — the log is read over
FTP, and a long-lived connection would hold an FPM worker
(`docker/apache-vhost.conf:5`).

- **Position by file and offset**, the same reading the chat page uses:
  a different file means the server restarted and the old offset means
  nothing.
- **A first run sends nothing.** It remembers where the log is and
  stops; starting up should not replay an hour of chat into a channel.
- **At most 20 lines per run**, so a backlog cannot flood a channel.
- **The scope only narrows.** `isPublic()` is checked first and the
  setting second, so no setting can widen the relay onto a private
  channel. The three scopes are the same set today because the log does
  not distinguish saying from shouting; they are kept apart so the
  distinction can be made later without a migration.
- One server failing does not stop the others.

### The local scheduler now runs

**A gap the plan flagged at step 1 and nothing had needed until now.**
`docker/supervisord.conf:45` consumes `scheduler_main` in production;
`.ddev/config.yaml` consumed only `async`, so **no scheduled task ever
fired locally** and anything resting on one could not be tested in a
browser at all.

Both consumers now run in ddev, matching production. Verified:
`debug:scheduler` lists all three recurring messages with their next
run, and `messenger:stats` shows an empty queue with nothing failed —
so the 20-second mirror really is doing nothing while no server has a
Discord channel.

### Files

`Server/Discord/ChatMirror.php`, `Message/MirrorChatToDiscord.php`,
`MessageHandler/MirrorChatToDiscordHandler.php`, the schedule entry,
`.ddev/config.yaml`, `Server/Chat/ChatLine.php` (channel + allow-list),
and two test files.

### Still open on Discord

- [ ] **Discord → game** still needs the gateway process. The setting is
      saved and the interface shows it as unavailable.
- [ ] **The server events are still not dispatched**: `bridge.quiet`,
      `server.unreachable` and the two update events have wording,
      switches and channels, but nothing calls the dispatcher for them.
- [ ] A deferred reply for commands that outgrow three seconds.
- [ ] Nothing has been run against a real Discord application.

### The server events now fire

`BridgeWatcher` notices when the bridge stops answering and when it
comes back, on a 60-second schedule — longer than the bridge's own
120-second staleness window, so a single slow write cannot look like an
outage.

**The transition is the event, not the state.** Announcing "the bridge
is quiet" on every poll teaches the operator to ignore the channel,
which is worse than silence — the reference panel's alert fatigue,
learnt from a status that flipped on every reconnect. So the last state
is remembered and only a change is dispatched, and **a first
observation is remembered without announcing**: the panel starting up
is not news about the server.

Three states, not two: "not read yet" is neither up nor down.

`BridgeLiveness` is another narrow interface per CLAUDE.md 10i — one
question, `isStale()`, so a player count or a version cannot sway a
decision about liveness. `BridgeStatusReader` implements it and keeps
its `final`.

Six tests, including the one that matters: four steady readings produce
no events at all.

---

## 2026-09-07 — Discord is live: real token, real roles, real messages

**The user configured a Discord application, so everything up to now
tested only in isolation has been run against Discord itself.** 775
backend tests, 358 frontend tests.

### What was proved against the real API

| Step | Result |
|---|---|
| `POST /settings/discord/test` | `{"status":"ok","bot":"Zomboid Bot","id":"1540899820892590201"}` |
| `GET /discord/roles` | one role, `Zomboid Bot`, correctly flagged `managed: true` |
| `GET /discord/channels` | `wohnzimmer`, `logs`, `admin` — text channels only, as filtered |
| `POST /discord/events/moderation.kick/test` | **`sent`** — the message arrived in `#logs` |
| `POST /discord/register` | **`registered`, 4 commands** — Discord accepted the whole catalogue |

**The registration passing on the first attempt is the meaningful
part**: Discord rejects the entire set for a bad name, an over-long
description, or an optional option placed before a required one, and
`CommandCatalogueTest` had asserted all three. Those assertions were
guesses about Discord's rules until now.

### A bug the real channel showed

The test message read **"Beispiel\-Admin"**: the escape list included
`-`, `>` and `#`, which are markdown only at the *start* of a line — a
list item, a quote, a heading. Escaping them everywhere turned an
ordinary hyphenated name into something uglier than the problem it
solved.

Now those three are escaped only where the value itself begins a line
(`/^(\s*)([-#>])/mu`), and the always-escaped set is `\ * _ ~ \` | [ ] (
)`. Two tests: a hyphen mid-word survives, a leading `- item` is still
defused.

**This is exactly what the browser rule is for.** The unit tests were
green, the escaping was "correct", and it looked wrong the moment a real
Discord client rendered it.

### The settings tab

Three fields on the Settings page under `discord` — the tab name is
lower case because `settings-page.test` reads the source for
`TabsTrigger value="([a-z]+)"`.

- **Application id** and **public key** are shown in plaintext: the key
  verifies rather than signs, and Discord displays it on its own
  application page. Only the **bot token** is in `SECRET_KEYS`.
- **The interaction URL is offered as a `Copyable`**, built from
  `APP_PUBLIC_URL` rather than from the request — behind a proxy the
  request host is the container's, which Discord could never reach.
- **The order of the steps is stated**, because it is not reversible:
  Discord probes the URL the moment it is saved and refuses it unless
  the public key is already stored here. So save here first, then paste
  into Discord.
- **`POST /settings/discord/test`** asks Discord who the token belongs
  to. The bot's *name* coming back is the useful half — a valid token
  from another application would otherwise look fine until the first
  command failed.

### Roles are picked, not typed

The user asked for it and it was the right call: an id is nineteen
digits that all look alike, and a mistyped one grants a command to
nothing at all, silently.

`GET /guilds/{id}/roles` gives names, colours and positions.
`RolePicker` shows them in Discord's own order (highest first, which is
how Discord lists them), with the colour dot Discord uses, and marks a
role an integration manages — granting a command to one of those is
usually a mistake.

Two decisions worth keeping:

- **`@everyone` is not offered.** It is every member by definition, so
  offering it as a permission would mean "anybody", which an operator
  can express more honestly by saying so.
- **A role the bot can no longer see stays selected**, shown by its id.
  Dropping it would quietly revoke a permission somebody set.
- **Saved as chosen**, without a button: picking from a list is already
  deliberate, and 21 rows each with their own unsaved state is a page
  nobody can keep track of.

### Still open

- [ ] **A slash command has never been *run* from Discord.** The
      catalogue is registered and the endpoint verified, but no role is
      assigned to any command yet — `CommandAuthorisation` refuses
      everything until one is, which is correct and means the command
      path is still untested end to end.
- [ ] **Discord → game** needs the gateway process.
- [ ] The user's guild has only the bot's own role, so a real
      permission test needs a role created there first.

---

## 2026-09-07 (session end) — Discord end to end, and why commands wait

**784 backend tests, 358 frontend tests, all green.** This entry is
written for a `/compact`: everything below is the state, not a summary.

### What is proven against the real Discord API

The user configured a Discord application (`Zomboid Bot`, application
`1540899820892590201`, guild `1040342836366811218`), so these are
measured rather than reasoned:

| Step | Result |
|---|---|
| `POST /api/settings/discord/test` | `{"status":"ok","bot":"Zomboid Bot"}` |
| `GET …/discord/roles` | real: `Zomboid Bot` (managed), `Admin` |
| `GET …/discord/channels` | real: `wohnzimmer`, `logs`, `admin` |
| `POST …/discord/events/moderation.kick/test` | **message arrived in `#logs`** |
| `POST …/discord/register` | **4 commands, 21 subcommands, accepted** |
| `app:discord:commands` | reads them back from Discord, all four present |

**The registration passing first time matters**: Discord rejects the
whole set for a bad name, an over-long description, or an optional
option before a required one — all three asserted by
`CommandCatalogueTest` as guesses about its rules until now.

### THE ONE THING THAT DOES NOT WORK LOCALLY, AND WHY

**Slash commands answer "Die Anwendung reagiert nicht" in Discord, and
this is not a bug.** Apache's access log shows Discord never reached
the panel at all: `APP_PUBLIC_URL` is `https://zomboidcontrol.ddev.site`,
which resolves only on this machine, and **Discord calls the panel from
its own servers**.

The user has decided **not** to test via ngrok, so this stays open
until the panel is deployed to a public address — at which point it
resolves itself with no code change.

Everything else already works, because for those the panel calls
Discord: notifications, the chat mirror, channel and role listing,
command registration.

So the panel now reports **two separate capabilities**:

- `discordReachable` on `/api/settings`
- `commandsReachable` on `/api/servers/{id}/discord`

Both computed by `isPubliclyReachable()`: a host with no dot, or ending
`.localhost .local .test .internal .ddev.site .example`, or a
private/reserved IP, is not reachable. **Deliberately duplicated** in
the two controllers rather than shared — they answer different
questions of the same fact, and a shared helper would have to live
somewhere neither owns. Extract it if a third caller appears.

The interface says so in three places: an amber alert on the Discord
page (`discord.commandsUnreachable`), a line on the settings card, and
the first line of the setup instructions. All three state that
notifications and chat work regardless.

`DiscordReachabilityTest` (3 cases) asserts the verdict against the
environment the suite runs in — **it would have caught this before
Discord did.** Written up as CLAUDE.md 10j.

### A permission trap fixed, worth remembering

The user assigned all 21 commands to role `1540902304436326483` — which
was **the bot's own role**, the only one their guild had. Every command
would have been refused.

The deeper fault was mine: **requiring a role locked the guild owner out
of their own bot**, because a fresh guild has no roles to assign. Now
`Interaction::administersGuild()` reads Discord's own computed
permission bitfield from `member.permissions` and allows `ADMINISTRATOR`
(1 << 3) or `MANAGE_GUILD` (1 << 5).

Guarded carefully, with six tests:

- an ordinary member's permissions (Send Messages, Read History, Add
  Reactions) grant **nothing**
- a malformed bitfield (`''`, `'administrator'`, `'-8'`, `'8.5'`) grants
  nothing
- an administrator still cannot run an **unmapped** command, cannot
  command **another guild**, and is still stopped when commands are
  **switched off**

The bitfield is a decimal *string* because it exceeds 32 bits; it is
validated with `ctype_digit` before casting.

The 21 wrong assignments were deleted. The user has since created an
`Admin` role, assigned it to all 21 commands and to themselves — so both
paths (role and administrator) are now in place.

### `/server status` answers the question it is named for

It counted players, which cannot distinguish an empty server from a dead
one. Now it reads the bridge's own last writing time, with **three
states**:

- 🟢 running, with the in-game clock and who is online
- 🔴 offline, with how long ago the last reading was, in readable units
- ❔ **no reading at all** — "whether the server is running cannot be
  said from here", which is not the same as offline

Uses **35 seconds**, the same window as
`ConnectionStatusEndpoint::RUNNING_WITHIN_SECONDS` — two thresholds
would let the panel and the bot disagree about whether the server is up,
and nobody could tell which was right.

### A bug only the real channel showed

The first test message read **"Beispiel\\-Admin"**. The escape list
included `-`, `>` and `#`, which are markdown only at the *start* of a
line. Those three are now escaped only where the value itself begins one
(`/^(\\s*)([-#>])/mu`); the always-escaped set is `\\ * _ ~ ` | [ ] ( )`.
Two tests: a hyphen mid-word survives, a leading `- item` is defused.

**The unit tests were green and the escaping was "correct".** It looked
wrong the moment a Discord client rendered it — which is what the
browser rule is for.

### Roles are picked, not typed

The user asked for it, and the reasoning holds: an id is nineteen digits
that all look alike, and a mistyped one grants a command to nothing at
all, silently.

`GET /guilds/{id}/roles` → `RolePicker`, in Discord's own order (highest
position first) with its colour dots, marking integration-managed roles.

- **`@everyone` is not offered.** It is every member by definition, so
  offering it as a permission means "anybody", which an operator can say
  more honestly.
- **A role the bot can no longer see stays selected**, shown by its id.
  Dropping it would quietly revoke a permission somebody set.
- **Saved as chosen**, no button: picking from a list is already
  deliberate, and 21 rows with their own unsaved state is unmanageable.

The header badge is now `secondary`, not amber, and reads "N Befehle
ohne Rollenzuweisung" — an unassigned command is still usable by whoever
administers the guild, so it counts rather than alarms. The list says
so explicitly (`commandList.adminsAlwaysAllowed`).

### The settings tab

Three fields under the `discord` tab (lower case: the tab test reads the
source for `TabsTrigger value="([a-z]+)"`).

- **Application id** and **public key** in plaintext — the key verifies
  rather than signs, and Discord shows it on its own page. Only the
  **bot token** is in `AppSetting::SECRET_KEYS`.
- **The interaction URL as a `Copyable`**, built from `APP_PUBLIC_URL`.
- **The order of steps is stated**, because it is not reversible:
  Discord probes the URL the moment it is saved and refuses it unless
  the public key is already stored here.
- **`POST /api/settings/discord/test`** asks Discord whose token it is.
  The bot's *name* is the useful half — a valid token from another
  application would look fine until the first command failed.

### Full file inventory for Discord

Backend, `src/Server/Discord/`: `Autocomplete`, `ChatMirror`,
`CommandAuthorisation`, `CommandCapabilities`, `CommandCatalogue`,
`CommandRights` (interface), `CommandRunner`, `CommandVerdict`,
`DiscordClientInterface`, `DiscordException`, `DiscordMessage`,
`EventNotifier`, `Interaction`, `InteractionSignature`,
`InteractionType`, `MessageTemplate`, `NotifiableEvents`,
`NotificationSettings` (interface), `RestDiscordClient`,
`SecretRedaction`.

Backend elsewhere: `src/Server/Events/{PanelEvent,PanelEventDispatcher,
PanelEventFlushListener,DeliverPanelEvent,BridgeWatcher,BridgeLiveness}`,
`Controller/Api/{DiscordController,DiscordInteractionController}`,
`Entity/{DiscordConfig,DiscordNotification,DiscordCommandRight}`,
`Repository/{DiscordNotificationRepository,DiscordCommandRightRepository}`,
`Message/{MirrorChatToDiscord,CheckBridgeState}` + handlers,
`MessageHandler/DeliverPanelEventHandler`,
`Command/DiscordCommandsCommand`, migration `Version20260907122103`,
`Permission::ManageDiscord`, three `AppSetting` constants.

Frontend, `src/features/discord/`: `discord.ts`, `discord-page.tsx`,
`event-list.tsx`, `command-list.tsx`, `chat-settings.tsx`,
`channel-picker.tsx`, `role-picker.tsx`, `discord.test.ts`. Plus
`components/ui/textarea.tsx` (new), the route, the nav entry, and about
150 translation keys in both locales.

Tests: `tests/Unit/Server/Discord/` (7 files),
`tests/Unit/Server/Events/BridgeWatcherTest`,
`tests/Functional/{DiscordInteractionTest,DiscordSetupTest,
DiscordReachabilityTest}`.

### Open, in the order it should be picked up

1. **Slash commands end to end** — blocked on a public address only. No
   code change expected; try `/server status` after deploying.
2. **Discord → game** needs the gateway process (a fifth supervisord
   entry, `discord-php/DiscordPHP`). The setting is saved and the
   control is shown disabled with its reason.
3. **A deferred reply** for commands that outgrow Discord's three
   seconds. `InteractionType::DEFERRED_MESSAGE` exists, nothing uses it.
4. **Linking a Discord user to a panel account**, so the moderation log
   names a person instead of "Discord: name".
5. **One flaky test run** was seen — 781 tests, one failure, not
   reproducible in two further runs and the failing case never
   identified. Worth watching; if it returns, capture the name before
   re-running.
6. **`uitest@localhost.test` exists again** (admin, created for the
   browser tests). Remove it when convenient — the user has been asked.

### Still open elsewhere (unchanged from earlier entries)

- Config editor: backup listing and restore in the interface, `text`
  values read-only, the item picker for `spawnItems`, **versioning by
  content hash** (the user's request, specified in full in its own entry
  above), and the sandbox still needing a restart — the bridge-side
  `initSandboxVars()` route is the open candidate.
- Twelve INI options have no explanation in any language and need ours.
- Step 0 stage B is done; steps 1 (statistics), 3 (Workshop/mods),
  5 (notification bell), 7 (scheduler) and 8 (avatars) of
  `docs/superpowers/plans/03-seven-features.md` are untouched.
- The vehicle wheels still do not draw. Lighthouse never run.

---

## 2026-09-07 (later) — Deployment: one variable, a green pipeline, and the 1.0.0 release

### What was asked

A user-facing README (English, plus a German `README_DE.md`), a finished
`Dockerfile` and `docker-compose.yml` that can be used directly, an image
published to GHCR automatically, a `1.0.0` tag and release, and the Lua
bridge downloadable from that release. Plus, mid-way: *"sorge dafür das
so wenig wie möglich in coolify herumkonfiguriert werden muss"* and
*"Jeder sollte die Readme auch ohne technisches verständnis verstehen"*.

### The two READMEs

`README.md` (English, the repository's own language) and `README_DE.md`
(German, the user-facing exception, like the locale files). Both cover
the same ground and cross-link at the top. Written for somebody who has
never set up a server: what the panel does, what RCON and FTP are *for*
before asking for their credentials, Coolify and plain Docker as two
separate paths, the bridge and why it needs a restart, Discord's three
capabilities and which one needs a public address, backups, and a
troubleshooting list keyed by the message the operator actually sees.

The Coolify section assumes Coolify is already running — the user asked
for that explicitly: *"wir wollen keine anleitung schreiben wie man
coolify installiert"*.

### One variable, and how each of the others was removed

`APP_PUBLIC_URL` is the only required value. What went, and by what
mechanism:

| Value | Before | Now |
|---|---|---|
| `APP_SECRET` | `openssl rand -hex 16`, typed | generated into `/app/var/secrets/app-secret` on first start |
| `CREDENTIALS_ENCRYPTION_KEY` | `openssl rand -hex 32`, typed | generated into `credentials-key`, **shown in the panel** |
| `POSTGRES_PASSWORD` | invented, typed | generated by the database container into a volume both mount |
| `SERVER_NAME` | the domain, again | derived from the host of `APP_PUBLIC_URL` |
| `WEBAUTHN_RELYING_PARTY_ID` | the domain, a third time | likewise |
| `MAILER_DSN`, `MAIL_FROM_*`, `OAUTH_GOOGLE_*`, `STEAM_WEB_API_KEY` | six compose variables | removed: they were only `$envDefaults` (`services.yaml:47-55`) the database overrides, and all are configured in the panel with a test button |

**Every one stays settable.** An explicit value always wins — that is
what an external database, or moving an installation to a new host,
needs. `POSTGRES_PASSWORD` in particular was briefly *not* settable,
which the user caught; the database entrypoint now writes an explicit
value into the shared file and generates only when there is none.

The shared-volume trick is the part worth remembering: `secrets:` is
mounted at `/run/secrets` in the database container and
`/app/var/secrets` in the panel. The database's entrypoint writes the
password before `docker-entrypoint.sh` runs (it reads
`POSTGRES_PASSWORD_FILE` immediately), and the panel's entrypoint reads
the same file to build `DATABASE_URL`. Neither container knows anything
about the other's configuration.

The host in that URL is `${POSTGRES_HOST:-database}` —
**hard-coding `database` was a real bug**, found only by running the new
CI step locally against differently-named containers.

### The encryption key had to become visible

A generated `CREDENTIALS_ENCRYPTION_KEY` nobody has ever seen cannot be
backed up, and losing it costs every stored FTP and RCON password while
the database restores perfectly. That is the "one value, two meanings"
rule pointed at a deployment decision: *generated* and *safe* are not
the same state.

So: `GET /api/settings/generated-secrets`
(`backend/src/Controller/Api/GeneratedSecretsEndpoint.php`, gated on
`Permission::EditSettings`), reading
`%env(default:app.default_secrets_path:GENERATED_SECRETS_PATH)%`
(`services.yaml:14,35-37`). It returns the key **only when the container
generated one**; a key the operator set is never echoed back, because
they already hold it and returning it only widens where it exists.

Interface: a **Security** tab (`settings-page.tsx:195,206`) holding
`frontend/src/features/settings/encryption-key-card.tsx`. Deliberately
*not* in `SAVABLE_TABS` — it saves nothing, and `settings-tabs.test.ts`
checks that list against the tabs that exist.

Its first version had three faults the user spotted in a screenshot:
64 bullet characters in an `overflow-x-auto` box produced a **scrollbar
with nothing to scroll**, the reveal button sat *beside* the field
instead of inside it, and the row looked shifted. Rebuilt as a real
`Input` with `type={revealed ? 'text' : 'password'}` and the eye button
absolutely positioned within it — the browser draws the dots, so nothing
overflows. Measured in the browser afterwards rather than eyeballed:
`anyOverflowScrollbar: false`, `eyeInsideInput: true`,
`verticallyCentred: true`, all three controls exactly 36 px on one line.

Guarded by `backend/tests/Functional/GeneratedSecretsTest.php` (4 cases):
hands over a generated key, says nothing was generated when the operator
set one, **an empty file is not a key**, and refuses without the
settings permission. Proven by reintroducing the empty-file bug and
watching it fail. It also carries the `set_error_handler` block from
rule 10h2 — which earned its place immediately, catching a
`file_get_contents` warning that `@` had hidden and that would have been
a 500 in dev. The endpoint now checks `is_file`/`is_readable` instead of
suppressing.

### The pipeline had been red for days — three separate faults

Nobody had looked, and every run on `main` failed. Each job failed for
its own reason:

1. **`frontend/.gitignore` listed `logs`**, unanchored, which also
   matched `src/features/logs/`. Three source files had **never been
   committed**, so the CI build failed on a module that exists locally.
   Fixed to `/logs`; the files are now in the repository.
2. **`db_test_test` does not exist.** Doctrine appends `_test` in the
   test environment (`doctrine.yaml:35`), so a `DATABASE_URL` naming
   `db_test` resolves to `db_test_test`. The backend suite had therefore
   **never run in CI**. The URL now names `db`; the service still creates
   `db_test`, which is what the suite opens.
3. **`wheel-mesh.test.ts` reads `backend/var/`**, which is deliberately
   never committed (game art). It checks an *installation*, not the
   parser, so its three cases now `skipIf` the file is absent.
   `zomboid-mesh.test.ts` covers the parser from a committed fixture and
   is unaffected. Verified by hiding the file and watching 3 skip while
   8 still ran.

Also added: the frontend **lint and test steps, which the CI never ran**
— only `npm run build`.

### Two faults in the deployment files themselves

- `image:` named `ghcr.io/andreasgerhardt/zomboidcontrol`, which does not
  exist. `docker compose pull` answered `denied`. The repository is
  `zone1987/zomboid-control-panel`.
- An `image:` + `build:` pair without `pull_policy: missing` **rebuilds
  from source** instead of pulling — a fifteen-minute surprise for
  anybody following the README.
- The published image is **amd64 only**, and stays that way. It was
  briefly switched to `linux/amd64,linux/arm64` because
  `docker compose pull` on Apple Silicon answers "no matching manifest
  for linux/arm64/v8" — but building arm64 on an amd64 runner means QEMU
  emulation, which ran past twenty minutes and the user cancelled it.
  The target here is Coolify on a Hetzner Cloud server, which is amd64
  unless it is a CAX (Ampere Altra) instance. **If an ARM host is ever
  needed, add a native arm64 runner rather than turning emulation back
  on** — the emulated build is what made it slow, not the second
  architecture as such. The arm64 image does build and run correctly;
  that was verified locally (`aarch64`, healthy, database connected)
  before the change was reverted.

### What the CI now proves about the image

The existing path passes every secret explicitly — the least likely way
anybody deploys. A second start was added with **only `APP_PUBLIC_URL`**,
asserting: healthy, `/api/health` 200 with `"database":"ok"`, both
secrets present at 32 and 64 characters, and **a restart keeping them**
rather than making new ones. Writing that step is what exposed the
hard-coded database host.

### Verified, and how

Everything below was run, not reasoned about:

| Claim | Evidence |
|---|---|
| Compose pulls rather than builds | `docker compose pull` failed with `denied` before, resolves after |
| Start with nothing but a domain | full stack up, 14 migrations, `/api/health` 200 |
| Migrations on redeploy | `--force-recreate`: "Already at the latest version" |
| Secrets survive a redeploy | md5 of all three unchanged; the setup account still there |
| Operator-set database password | `MyOwnChosenPassword123` accepted, `database: ok` |
| Generated database password | 48 characters, `database: ok`, survives restart |
| Host derivation | six URL shapes through the `sed`, including port, path, credentials and empty |
| arm64 image | built and run: `aarch64`, healthy, database connected |
| The documented `pg_dump` | still works without a password (local socket trust) |
| Security tab, both states | browser: own-key alert, and generated-key with working reveal |

Test counts: **788 backend** (4 new), **358 frontend**, lint 0 errors.

### Files

| What | Where |
|---|---|
| Guides | `README.md`, `README_DE.md` |
| Compose | `docker-compose.yml` — image name, `pull_policy`, `expose`, secrets volume, generated database password |
| Entrypoint | `docker/entrypoint.sh` — host derivation, secret generation, `POSTGRES_HOST` |
| Sample env | `.env.example` — one required value, the rest explained as optional |
| Endpoint | `backend/src/Controller/Api/GeneratedSecretsEndpoint.php` |
| Wiring | `backend/config/services.yaml:14,35-37` |
| Test | `backend/tests/Functional/GeneratedSecretsTest.php` |
| Card | `frontend/src/features/settings/encryption-key-card.tsx` |
| Tab | `frontend/src/features/settings/settings-page.tsx:195,206` |
| Data | `frontend/src/features/settings/settings.ts` — `readGeneratedSecrets` |
| Translations | `de.json` / `en.json`, `settings.encryptionKey*` and `settings.securityTab` |
| Pipeline | `.github/workflows/ci.yml` — lint, test, database name, unconfigured start, arm64, release job |
| Restored | `frontend/src/features/logs/` (3 files), `frontend/.gitignore` |

### The release job

Runs on a `v*` tag, after the image job. It **refuses to publish when the
tag does not match `app.version`** in `services.yaml` — a release naming
1.0.0 while the panel reports 0.9.0 would make the update banner
permanently wrong and nobody would see it from outside. It attaches
`ZomboidControlBridge.lua` and its `.sha256`, so an operator whose FTP
the panel cannot reach can install the bridge by hand.

`PanelUpdateChecker` already points at `zone1987/zomboid-control-panel`
(`services.yaml:12-13`), so the tag and the in-panel update notice agree.

### Open after this entry

1. **Coolify itself is untested by us.** Everything was verified with
   plain `docker compose`, which is what Coolify runs — but no deployment
   to a real Coolify instance has happened. The `coolify` MCP server was
   configured this session and failed to connect (401).
2. ~~GHCR visibility~~ — checked: the package is **public**. An
   anonymous token fetches the manifest with 200, so `docker compose
   pull` works with no login.
3. `uitest@localhost.test` still exists (unchanged from the entry above).

---

## 2026-09-07 (later still) — 1.0.0 shipped, and the fault the release itself found

### The release went out

Tag `v1.0.0` on `ad95795`. Everything in the chain ran and was checked
afterwards rather than assumed:

| Thing | Verified how |
|---|---|
| Pipeline | all four jobs green, first time ever |
| Image tags | `1.0.0`, `1.0`, `latest`, `main`, two shas listed on GHCR |
| Package visibility | anonymous token fetches the manifest, 200 — no login needed |
| GitHub release | not a draft, not a prerelease, both assets attached |
| Bridge download | fetched anonymously from `/releases/latest/download/`, checksum verifies, byte-identical to the repository copy, `BRIDGE_VERSION = "0.21.0"` |
| The whole user path | cloned the tag, `cp .env.example .env`, one variable, `docker compose up -d` → 14 migrations, `/api/health` reports `1.0.0`, `"database":"ok"` |
| First registration | in the browser on a fresh container: wizard → account → login → dashboard, 0 console errors, and the wizard then refuses a second account with 409 |

### The fault it found

The Security tab on the released image said **"Du verwaltest den
Schlüssel selbst"** — while the container had generated the key itself.

The cause: the entrypoint runs as root and wrote
`/app/var/secrets/credentials-key` as `0600 root:root`, but PHP runs as
`www-data`. `is_readable()` was false, and the endpoint collapsed that
into "not generated".

That is rule 6c exactly, in a place I had not looked for it: *cannot
read* and *the operator set it* became one value. The consequence is the
worst kind — the operator is told there is nothing to save, while the
only copy of the key that protects every stored FTP and RCON password
sits unreadable inside a container.

**It was only found by logging into the released image in a browser.**
Every local test passed, because in ddev the key comes from `.env.local`
and the "operator set it themselves" branch is the correct answer there.

Two fixes:

- `docker/entrypoint.sh` now `chown`s the secrets directory and the two
  generated files to `www-data` (`750` / `640`). The **database password
  deliberately stays `0600 root:root`** — the panel never reads it, only
  the entrypoint does, and it is written by the other container.
- The endpoint reports **three states**, not two:
  `{generated, key, unreadable}`. A key that exists but cannot be read
  returns `generated: true, unreadable: true`, and the card shows a
  destructive alert naming the path inside the container.

`GeneratedSecretsTest::testAKeyItCannotReadIsNotReportedAsTheOperatorsOwn`
guards it, staged with `chmod 0000` and skipping where the test user can
read it anyway (root in some CI images). Proven by restoring the 1.0.0
behaviour and watching it fail.

Verified on a rebuilt image: `-rw-r----- www-data` for both generated
files, `-rw------- root` for the database password, `www-data` can read
the key, the endpoint returns 64 hex characters, and the card shows the
"save this now" warning with a working reveal.

### The git flow changed, and it is now a rule

The user's instruction, verbatim: *"In Zukunft werden ALLE Anpassungen
die wir durchführen immer erst in einem Feature Branch committet,
gepusht. Du erstellt dann einen pull request into main, dann mergest du
das feature in main rein und erstellst dann tag und release."*

Written into `CLAUDE.md` as **rule 1c**: branch → push → PR → CI green →
merge → bump `app.version` → tag → watch the release. Nothing lands on
`main` directly now that `main` is what people install.

This change is the first to follow it: branch `fix/generated-key-unreadable`,
version bumped to **1.0.1** because it fixes something an operator of
1.0.0 can see.

---

## 2026-09-07 (evening) — The first real Coolify deployment, and what it broke

The user deployed to Coolify on a Hetzner Cloud server. It failed, and the
log answered three questions no local test could.

### 1. The published port collides — `ports:` does not belong there

```
Error response from daemon: failed to set up container networking:
Bind for :::8080 failed: port is already allocated
```

Coolify routes through its own proxy to the container's **exposed** port.
A published host port bypasses that proxy entirely and fights over a port
on the host — 8080 was already taken there. The comment in the compose
file called it "harmless behind a proxy". It is not.

Coolify's own documentation confirms the mechanism: a `ports:` entry is
"available on your server at port 3000, **outside the control of any
proxy configuration**".

**Resolved by the user's choice**: the fixed port stays (a local
`docker compose up` needs it, and Compose rejects an empty port value),
and `APP_PORT` overrides it. The README now names the exact error text
and says to set `APP_PORT` to something free.

Two alternatives were considered and rejected: a `docker-compose.override.yml`
that Coolify never reads (two files for one job), and dropping `ports:`
entirely (leaves a local install unreachable).

### 2. Coolify builds when a `build:` section exists

The log shows 1 minute 44 between "Pulling & building required images"
and "Removing old containers" — it **built from source** rather than
pulling the released image, despite `image:` and `pull_policy: missing`.

`build:` is gone from the compose file for that reason. Building locally
is `docker build -t … --target runtime .`, which is what the CI does
anyway.

### 3. Coolify expects `docker-compose.yaml`

The user had to set the compose location by hand because the file was
`.yml`. Renamed to **`docker-compose.yaml`**, so the default path works.
Compose itself reads both spellings, so nothing changes locally.

### The address no longer has to be typed

The user asked whether `APP_PUBLIC_URL` could just be the Coolify URL.
It can. Coolify injects **`COOLIFY_URL`** with the domain configured for
the application.

Worth keeping apart, because the first documentation page suggested the
wrong one: `SERVICE_URL_*` and `SERVICE_FQDN_*` are **generated** domains
for one-click services; `COOLIFY_URL` is the domain **the operator
entered**. This is an application, so `COOLIFY_URL` is the right one.

`docker/entrypoint.sh` now falls back to it, taking the first entry if it
holds several comma-separated domains, and refuses with a message naming
both variables when neither is set.

Verified against the built image, four cases:

| Given | Result |
|---|---|
| nothing | `FATAL: no public address is set`, naming both variables |
| `COOLIFY_URL=https://zomboid.example.com` | `Public address: https://zomboid.example.com` |
| `COOLIFY_URL=https://a…,https://b…` | takes the first |
| both set | `APP_PUBLIC_URL` wins |

And end to end with `COOLIFY_URL` alone: healthy, 14 migrations, and the
panel built its own URLs from it —
`discordInteractionUrl: http://localhost:8090/api/discord/interactions`,
`googleRedirectUri: …/api/connect/google/check`, with
`discordReachable: false` correctly reported for a localhost address.

**One caveat, undecided by documentation**: one Coolify page says the
predefined variables are used "by adding them as environment variables",
which reads like they must be declared rather than being present
automatically. The compose file therefore passes `COOLIFY_URL:
${COOLIFY_URL:-}` through explicitly, and the failure message tells the
operator to add it with an empty value if it did not arrive. Whether that
step is needed can only be settled on the user's own installation.

### Files

`docker-compose.yml` → **`docker-compose.yaml`** (no `build:`,
`COOLIFY_URL` passed through, port comment corrected),
`docker/entrypoint.sh` (address fallback and the refusal),
`README.md` / `README_DE.md` (Coolify step 3 rewritten: normally nothing
to set; the two error messages added to the troubleshooting tables),
`.env.example`, and `app.version` to **1.0.2**.

---

## 2026-09-07 (night) — The published port had to go entirely

1.0.2 shipped with `ports: "${APP_PORT:-8080}:80"` still in the compose
file, on the reasoning that a local `docker compose up` needs it and
Coolify operators can override `APP_PORT`. The user deployed again and
got the same failure:

```
Bind for 0.0.0.0:8080 failed: port is already allocated
```

Two things were wrong with that reasoning.

**The override did not take.** The log still shows 8080, so whatever the
operator set did not reach Compose's interpolation. Rather than chase
where Coolify puts its variables, the port is simply gone.

**The user checked the reference panel**, which publishes `3001:3001` —
and asked why ours needs the mapping at all. The honest answer is that
the reference gets away with it because 3001 is rarely taken. That is
luck, not a design, and it is not a reason to make every deployment here
depend on 8080 being free.

So: **the base file publishes nothing.** A reverse proxy — Coolify's
included — routes to `expose: 80` and never needs a host port, while
publishing one claims it on the host and fails the entire deployment when
something already holds it. `docker-compose.local.yaml` publishes it for
a run without a proxy:

```
docker compose -f docker-compose.yaml -f docker-compose.local.yaml up -d
```

Two files after all, which the user had reasonably questioned earlier.
The difference now is evidence: a single file cannot serve both, because
Compose rejects an empty port value and an override variable did not
reach it on the real deployment.

**Verified rather than argued**: 8080 was occupied by a second container,
then the stack deployed the way Coolify does it. The panel came up
healthy and answered `{"status":"ok","version":"1.0.2"}` over the Docker
network — the path a proxy uses — while the other container kept the
port. The local overlay was then checked separately and still publishes.

Version bumped to **1.0.3**.

---

## 2026-09-07 (late) — The map was invisible on the deployed panel

The user reported three things at once on the real installation: the map
did not load, Discord rejected the bot token, and Google would not
connect. Two of them had a single cause.

### `{{SERVICE_URL_APP}}` arrived verbatim

`/api/settings` on the deployment returned:

```
discordInteractionUrl: {{SERVICE_URL_APP}}/api/discord/interactions
googleRedirectUri:     {{SERVICE_URL_APP}}/api/connect/google/check
discordReachable:      false
```

`APP_PUBLIC_URL` held Coolify's **magic-variable syntax as text**.
Coolify substitutes `SERVICE_URL_*` and `SERVICE_FQDN_*` for *services*
(one-click) and leaves them untouched for an *application*, which is what
this is. So every URL the panel built was broken, and the Discord page
showed "slash commands do not work here" on a perfectly public domain.

The user replaced it with the real address; `discordReachable` went
**false → true** immediately.

`docker/entrypoint.sh` now refuses an unresolved template rather than
using it: a value containing `{{`, `}}` or `${` is discarded with a
message naming the cause, and the fallback to `COOLIFY_URL` applies. It
was the silent kind of failure — nothing logged, nothing broken until
Discord and Google both failed for reasons that looked unrelated.

**The Discord token was a separate, real fault**: `POST
/api/settings/discord/test` answers `401 discord.unauthorised`, which is
Discord's own answer, not ours. `RestDiscordClient` sends
`Bot <trimmed token>` correctly. Most likely the token was reset in the
developer portal. A token for application `1540899820892590201` must
begin `MTU0MDg5OTgyMDg5MjU5MDIwMQ`, which the operator can check without
us. Google was simply not configured: `googleIdConfigured: false`.

### The map: zero height, not a loading failure

Every tile fetched with 200 and `naturalWidth` 1024. The container was
**1193 × 0**:

- `.pz-map` had `size-full`, i.e. `height: 100%`
- its parent's height came from `min-h-[30rem]`, not `height`

A percentage height against a min-height resolves to zero. So the map
loaded perfectly and was squashed flat.

Three fixes, each measured:

1. **`absolute inset-0`** on `.pz-map` instead of `size-full`. 0 → 480px.
2. **The layout's page wrapper is a flex column** (`app-layout.tsx`), so
   the map page asks for `flex-1` instead of computing a height from
   header and padding sizes that go stale. `h-[calc(100svh-7.5rem)]` was
   tried first and was 40px too generous — it made `main` scroll. 480 →
   650px on a 806px window, 1284px on a 1440px one, and no scrollbar
   either way.
3. **`min-h-[30rem]` stays**, so a short window scrolls to a usable map
   rather than showing a sliver.

### Overlapping controls, and a Tailwind trap

The user reported the search field sitting under the players panel, and
asked for things to collapse or move on narrow screens rather than
overlap. A probe that compares every pair of absolutely positioned
overlays found it at several widths, not just on a phone.

- **The sidebar starts collapsed below `md`** — open it is 224px against
  a 390px screen. Derived from `useIsMobile` as `open ?? !isMobile`,
  not copied into state by an effect (rule 10g2).
- **The search is bounded by the sidebar**, not given a width:
  `left-14 right-20 md:right-64`. `left-14` is relative to the *map*,
  which the app's own sidebar has already narrowed, so every fixed width
  overlapped somewhere.
- **`max-w-xl` beat the right offset** and had to move to `xl:`.

**The trap worth remembering**: `md:right-[15.5rem]` appeared in the
built JavaScript but **not in the built CSS** — Tailwind 4 did not
generate that arbitrary value here, so the class did nothing and the
overlap persisted through two rebuilds. Checking the CSS bundle rather
than the source is what found it. `md:right-64` is on Tailwind's own
scale and is always generated.

Verified with no overlaps at 390, 768, 871, 1024 and 2560 px wide.

### Footer

`Panel v1.0.2 → v1.0.3` wrapped out of the fixed 2.25rem footer. Now
`whitespace-nowrap` with `overflow-hidden` on the bar, and below `sm`
only the icon and the version on offer — the label and the version being
replaced are what the operator already knows.

**The connection lights collapse below `lg` rather than `md`**, at the
user's request: four labelled lights are 286px of an 871px window and
crowded the version and credits out of the bar.

### Still open

**The deployment is running 1.0.2 while 1.0.3 is released.** The registry
digests for `latest` and `1.0.3` are identical, so Coolify used a cached
image rather than pulling. The compose file lost `pull_policy` when
`build:` was removed; whether Coolify's own redeploy pulls reliably is
not yet settled on the real installation.

Version bumped to **1.0.4**.

---

## 2026-09-08 — Map spacing, and login buttons that lead somewhere

Checked the deployed panel after the 1.0.4 redeploy. The map now renders
(650px on an 806px window, 688px on a phone, no overlaps, no scrollbar),
and the footer reads `Panel v1.0.4` with no update banner. Two further
faults came out of looking at it.

### The gap below the map was 40px against 24px everywhere else

Measured rather than eyeballed: `gapLeft: 24, gapRight: 24, gapTop: 24,
gapBottom: 40`. The extra 16px is `pb-4` on `.pz-page`, which every other
page wants as breathing room above the footer. The map is edge-to-edge,
so it read as a mistake. `-mb-4` on the map page cancels it; all four
sides are now 24.

### The attribution overlapped the places button

`© The Indie Stone · projectzomboidmap.com · B42.20.2` ran under the
"Orte" button by **15px** at 540px wide. The attribution had
`max-w-[calc(100%-6rem)]`, but the button is 87px plus its own 0.75rem
edge — 6rem was never enough. Now 8rem, verified at 390 and 540px.

**Why the earlier probe missed it**: it compared the map's *siblings*,
and both of these live *inside* the map. Widening it to include
descendants found the clash immediately — and buried it in 200 lines of
noise from the map's own markers, which overlap each other by design. A
useful probe here needs the wider net and a filter for the elements that
are chrome rather than content.

### Login buttons for providers nobody can use

The user asked for the Google and Steam buttons to appear only when a
provider can actually sign somebody in. Their first framing — "when at
least one user is connected" — has a trap worth recording: with nobody
linked the button disappears, and the first person can never link,
because linking happens while signed in. So the condition chosen was
**configured *and* linked**, with linking always available under Account.

`GET /api/login/providers` (`LoginProvidersEndpoint`) answers
`{google, steam}`:

- **Google** needs credentials *and* a linked account: without the client
  id the redirect cannot even be built.
- **Steam** needs only a linked account. Steam signs in over OpenID and
  needs no key — `STEAM_API_KEY` only reads names and avatars afterwards,
  which is a deliberate asymmetry rather than an oversight.

It is `PUBLIC_ACCESS` in `security.yaml`, which the first test run caught:
the endpoint answered `auth.required`, and it is asked *before* a login by
definition.

Guarded by `LoginProvidersTest` (6 cases: nothing offered on a fresh
install, Steam on a link alone, Google refused with a link but no
credentials, refused with credentials but no link, offered with both, and
reachable unauthenticated) and `login-providers.test.ts` (4 cases reading
the page's own wiring, proven by restoring an ungated button and watching
it fail).

The divider above the buttons is now conditional too: a label reading
"or continue with" over an empty area is worse than no label.

**Not verified in a browser**, and worth saying plainly: every attempt to
view the login page in ddev redirected to `/app` because the session was
live, and one attempt bounced through Steam's OpenID and signed back in.
The endpoint and the wiring are covered by tests; the rendered result is
not.

### Left alone at the user's request

Google linking redirects to `/app/` and appears to do nothing. The cause
is visible in `GoogleAuthenticator::onAuthenticationSuccess`, which
always returns `RedirectResponse('/app/')` — so a *linking* attempt looks
identical to a sign-in. Credentials are configured and the outbound
redirect to Google is correct (verified: the consent screen loads with
the right client id and redirect uri). The user asked to leave it for
now.

Version bumped to **1.0.5**.

---

## 2026-09-08 (later) — Google linking, who may sign in, and a layout fault I caused

### Linking Google did nothing visible

`/api/connect/google` is a **sign-in** route: GoogleAuthenticator handles
it and lands whoever comes back on `/app/`. The profile page used that
same route to *link*, so a successful link looked identical to being
dumped back at the dashboard.

Steam already had the right shape — `/api/connect/steam/link`, a separate
route with `#[CurrentUser]` returning to `/app/profile`. Google now has
`/api/connect/google/link` built the same way, with its own redirect uri
(Google checks it against the one the flow started with) and
`?linked=google` on return.

### Who may sign in with a provider

The user asked that only accounts that exist *and* have the provider
linked may sign in. Most of that was already true:

- **Steam** refuses an unknown identity outright.
- **Google** refuses too — but had a back door.

`IdentityLinker::findInvitedByEmail` matched on **the address alone**,
despite its name. So an account in use for months would accept whoever
later controlled a Google account with that address: somebody who left
the company, or a domain that changed hands. A sign-in nobody granted.

The user confirmed the invitation shortcut itself should stay — *"wenn
das Konto eingeladen wurde ist es ja ok wenn es automatisch verknüpft
... es ist ja quasi ein erwünschter User"* — with the boundary being
*"es soll sich nur nicht jeder X-Beliebige User einfach so anmelden
können"*.

So the match is now narrowed to accounts that are genuinely still
awaiting activation. Three conditions, each one a way in that proves the
account is already in use:

| Rejected when | Why |
|---|---|
| a password is set | activation is finished |
| a passkey exists | same reasoning `canUnlink` already uses |
| any provider is linked | the account signs in already |

Five cases in `IdentityLinkingTest`, proven by restoring the email-only
match and watching three of them fail.

### A layout fault I introduced yesterday

The user's screenshot showed the profile page at **413px** in a 1241px
area, and narrower still after adding a passkey — the width was following
the content.

The cause was mine: making `.pz-page` a flex column (so the map could ask
for `flex-1`) meant every page was sized to its content rather than
stretched. `mx-auto max-w-3xl` collapsed to the longest line.

`items-stretch` did **not** fix it: `mx-auto` sets automatic margins, and
those defeat stretch in flexbox. A `100cqh` container-query attempt made
it worse — the map overshot by 68px and the surface scrolled.

What works is `[&>*]:w-full` on the column: the width rule wins, and
`mx-auto` still centres within it. Measured afterwards on three page
shapes, which is the point — one page proves nothing here:

| Page | Width | Expected |
|---|---|---|
| profile | 768 | `max-w-3xl` |
| settings | 1024 | `max-w-5xl` |
| players | 1193 | fills 1241 minus padding |

And the map keeps 760px with four equal 24px gaps and no scrollbar.

### Attribution on a phone

`© The Indie Stone · projectzomboidmap.com · B42.20.2` still ran under
the places button at 390px. The user suggested shortening it. **The
Indie Stone's notice stays at every size** — it is the condition the
artwork is shown under (rule 10b), not decoration. The tile source and
build id drop below `sm`. Measured: 112px of clearance at 390px.

### Google needs both redirect uris registered

Linking starts its own flow, and Google refuses a redirect uri it was
not given. The settings page showed only the sign-in one, so a linking
attempt would have been rejected by Google with an error the operator
could not act on. Both are shown now — `.../google/check` for signing in
and `.../google/link` for linking — with the label saying to register
both. Verified: the endpoint returns two distinct absolute urls built
from `APP_PUBLIC_URL`.

**Not verified end to end**: whether the new link route completes against
the real Google, because that needs the second uri registered in the
user's Google console first.

Version bumped to **1.0.6**.

---

## 2026-09-08 — Mobile, tablet and desktop: measured on every page

The user asked for a full pass at three widths after the mobile work, and
during it added two rules that shaped the outcome:

> "mobil sollten möglichst gar keine horizontalen scrollbars angezeigt
> werden müssen" — and, when one is unavoidable, "sollte die ansicht
> mobil so angepasst werden das es besser bedienbar ist"

So a horizontal scroller on a phone is now a defect to design away, not
a scroller to keep tidy.

### How it was measured

A probe run over fifteen routes at **390, 820 and 1512 px**, navigating
inside the SPA (`history.pushState` + a `popstate` event) so each page
mounted for real. Per page it reported five things:

| Check | How |
|---|---|
| Overflow past the frame | `getBoundingClientRect().right > innerWidth + 1`, **skipping anything inside a scroller of its own** |
| A scroller that really scrolls | `overflow-x` is auto/scroll **and** `scrollWidth > clientWidth + 2` |
| Touch target too small | under 32px, excluding switches, header and footer counted separately |
| Raw translation key | a leaf node whose whole text matches `^[a-z]\w*\.[a-zA-Z][\w.]*$` |
| Off-centre page | `(main.right − el.right) − (el.left − main.left)` on every `.mx-auto` |

Two earlier mistakes are baked into that shape: the probe must skip
scroller contents (or every table is a false positive) and must scan the
**header and footer too** (the worst fault of the day was in the header).

### The two faults that cost a control, not looks

**1. The update button pushed the header controls off screen.**
`AppUpdateBanner` rendered a ~150px label. At 390px the right-hand group
ran to **414px in a 375px header**, so the language and theme buttons
were outside the viewport with `overflow-x-hidden` quietly cutting them
off. Now `size-9 p-0` with the label `hidden sm:inline`, keeping
`title`/`aria-label`.
`frontend/src/components/layout/app-update-banner.tsx`

**2. `scrollbar-gutter: stable` centred nothing centred.**
Reported by the user with an arrow on a screenshot — "rechts mehr
abstand als links". Measured: **229 left, 244 right, and the scrollbar is
15px**. The rule sat on `html` while the scrolling element is `main`, and
`stable` alone reserves on the end edge only.

`both-edges` fixed the desktop (237/237) and broke the phone: it reserves
**twice** the bar, 30 of 390 pixels, which the user caught immediately —
"hier ist links zu viel abstand oder?". Final shape in
`frontend/src/index.css`:

```css
html { scrollbar-gutter: stable; }

@media (min-width: 1024px) {
  .pz-surface { scrollbar-gutter: stable both-edges; }
}

@media (max-width: 1023px) {
  .pz-surface { scrollbar-width: none; }
  .pz-surface::-webkit-scrollbar { width: 0; height: 0; }
}
```

Below `lg` the bar overlays, which is what a phone does natively anyway.
Measured after: phone 16/16 with the card at **343px** (was 298), desktop
237/237, and `main.scrollHeight > main.clientHeight` still true so
scrolling was not lost.

### Two tables became cards

The only live horizontal scrollers anywhere. Both are five columns:

| Page | Wanted | Had | File |
|---|---|---|---|
| Moderation log | 724px | 276px | `features/players/moderation-history.tsx` |
| Account list | 763px | 325px | `features/users/account-list.tsx` |

Each renders a `<ul>` of cards and the table beside it, switched with
`lg:hidden` / `hidden lg:block`. **The breakpoint is `lg`, not `sm`** —
at 820px with the sidebar open the content frame is only **549px**, so
`sm` left both tables scrolling on a tablet (259px and 160px over).

The account list's four cell bodies were lifted into `Identity`, `Roles`,
`SignInMethods` and `Actions` so the two views cannot drift apart.

### Touch targets under 32px

| Control | Was | Now | File |
|---|---|---|---|
| Sidebar trigger | 28×28 | `size-9 sm:size-7` | `components/layout/app-layout.tsx` |
| Footer bar | `h-9` | `h-11 sm:h-9` | `components/layout/app-footer.tsx` |
| Credits button | `h-6` | `h-8 sm:h-6` | `features/panel/credits-dialog.tsx` |
| Connection lights | `h-6` | `h-8 sm:h-6` | `features/servers/connection-lights.tsx` |
| "How do I get this" | `h-auto` (16px) | `h-8 sm:h-auto` | `features/settings/credential-field.tsx` |
| Breadcrumb link | 20px | `py-2 sm:py-0` | `components/layout/breadcrumbs.tsx` |

The shadcn `Switch` (32×18) is **left alone**: the track is the target by
design, and 32px of width is enough for a thumb.

### Two more, found on the way

- **Page padding** `p-6` → `p-4 sm:p-6` in `app-layout.tsx`. On the
  players page two paddings plus a `<details>` took 375px down to 278;
  the cards gained 45px back.
- **The credential row could not shrink.** Every child carried
  `shrink-0` under `sm:flex-nowrap`, so a long label ran **53px past the
  frame at 820px**. The `<Label>` is now `min-w-0 truncate` — the one
  thing on that line that may give way.

### `settings.title` was a key that does not exist

In the mobile section select's `sr-only` label
(`features/settings/settings-page.tsx`), so only a screen reader would
have said it. Replaced with `nav.settings`.

Then **every** `t('...')` literal in `frontend/src` was checked against
both locale files: 20 hits, 19 of which resolve through i18next plural
suffixes (`_one`/`_other`). `settings.title` was the only real one.

### Texture packs: three named, five hold icons

The user asked whether the list under Item-Icons is complete. Counted
against the installation at `/Volumes/ESD-USB/ProjectZomboid`:

```
strings -n 6 <pack> | grep -c '^Item_'
```

| Pack | `Item_*` sprites | was listed |
|---|---|---|
| UI2.pack | 3848 | yes |
| UI.pack | 563 | yes |
| ApComUI.pack | 44 | yes |
| **RadioIcons.pack** | **13** | **no** |
| **IconsMoveables.pack** | **7** | **no** |

Twenty vanilla icons never arrived. `IconController::WANTED_PACKS` now
names all five, and `IconUploadTest::testTellsTheInterfaceEveryPackThat
HoldsItemIcons` carries the counts as its failure message. **Proved by
reverting**: with three packs the test fails.

The interface needed no change — the card renders `status.wanted`.

### Shipping the vanilla art: asked, and declined with reasons

> "Die Texturpakete die wir lokal bereits für die vanilla items und
> fahrzeuge haben könnten wir aber auch committen"

Not done, and the reasoning belongs here so it is not revisited blind:

- **Rule 10b and `backend/.gitignore:8` already answer it** — the
  artwork is The Indie Stone's. The terms permit *showing* it in a
  non-commercial fan project with a visible notice (`/app/credits`);
  redistributing it in a repository is a different act.
- **81 MB** — `backend/var/icons` is 17 MB (4352 files) and
  `backend/var/vehicle-models` is 64 MB. In every clone, every CI run
  and every image layer, permanently, because git does not forget.
- **Mod models belong to their authors**, which the credits page states.

The operator's path already exists: upload the packs, or run
`app:icons:extract <path>`. If first-run guidance is wanted, that is the
thing to build — not a commit of the art.

### What was verified

Fifteen routes × three widths, in the browser:

| Width | Result |
|---|---|
| 390 | no overflow, no live scroller, no target under 32px, no raw key |
| 820 | no overflow, no live scroller |
| 1512 | no overflow, tables still tables (1157px / 1022px), cards hidden, padding 24px, centred 237/237 |

Suites after the change: **800 backend** (14343 assertions), **362
frontend**, lint **0 errors**.

One diagnosis worth keeping: the panel first answered "Diese Seite konnte
nicht geladen werden" on every route. Not a code fault — the **service
worker was serving a precache manifest whose assets the rebuild had
removed**. Unregistering it and clearing `caches` fixed it. Worth
recognising quickly after any `npm run build` during a browser session.

Version bumped to **1.0.7**.

---

## 2026-09-08 — Five more languages, and what they exposed

The user asked for Spanish, Polish, Italian, French and Russian. 1708
keys each, so 8540 strings. Written by five subagents in parallel, one
file each, against a brief holding the rules that matter: keep every
`{{placeholder}}`, keep the key structure, leave the game's own
vocabulary in English, use the language's real plural rules, and address
the reader informally as `de.json` does.

**They are marked as machine-translated in the switcher.** The user chose
that over silence: nobody has read them as a native speaker, and a
clumsy sentence is easier to forgive when it did not claim otherwise.

### Files

| What | Where |
|---|---|
| The tables | `frontend/src/i18n/locales/{es,fr,it,pl,ru}.json` |
| Supported list, names, unreviewed set | `frontend/src/i18n/config.ts` |
| The switcher, reviewed above a labelled divider | `frontend/src/components/layout/language-toggle.tsx` |
| The guard, 40 cases over all seven | `frontend/src/i18n/locales.test.ts` |

`SUPPORTED_LANGUAGES` is now seven, `UNREVIEWED_LANGUAGES` names the five,
and `LANGUAGE_NAMES` gives each its own spelling — the switcher shows
"Русский", not "Russian". Detection matches on the primary subtag, so
`pt-BR` no longer silently means English while `pt` would have.

### The backend needed nothing

`SupportedLanguages` reads the locale directory rather than holding a
list — its docblock said "adding fr.json to the frontend is then the
whole of adding French", and that turned out to be exactly true.
Measured: `all()` returns `de, en, es, fr, it, pl, ru`, and `supports()`
answers yes to all five new ones and no to `xx`.

### Plural forms: Polish and Russian need four, English has two

`_one` in Russian resolves for **1, 21, 31, 101** — `Intl.PluralRules('ru')
.select(21)` is `"one"`. So where English writes "One value" with the
number spelled out, Russian must write `{{count}} значение`, or 21 pinned
values reads as "one value".

Both my probe script and the first version of `locales.test.ts` called
that a fault. **The translation was right and the check was wrong**, and
the Russian agent said so in its report. `_one` is now exempt from the
placeholder equality rule: nothing invented, nothing lost, `count`
allowed.

The files carry 1746 keys for `pl` and `ru` (1708 + 38 added forms) and
1708 for the rest.

### Two faults in my own test, found by an agent and by the run

- **`import it from './locales/it.json'` collides with vitest's `it`.**
  The file would not have run at all. Aliased to `italian`.
- **`read()` split on every dot, and a permission key contains one.**
  `roles.permissions.chat.read` is three levels, not four, so every
  language "failed" — including German, which has been complete for
  months. That was the tell. Replaced by `flatten()`, which walks the
  tree and returns a `Map` keyed by the dotted path.

The guard was then proved by breaking things: a removed key, a removed
`_many` form, and a `{{count}}` dropped from `_other` each fail with a
readable message, and all 40 pass again once restored.

### The browser found what no test could

Seven `sr-only` strings were hardcoded English in the shadcn components
and had never gone through i18n: "Toggle Sidebar" (three times, in
`sidebar.tsx`), "Sidebar" and "Displays the mobile sidebar" in the mobile
sheet header, "Close" in `dialog.tsx` and `sheet.tsx`, "More" in
`breadcrumb.tsx`.

Only a screen reader speaks them, which is why nobody saw them — and for
a Russian or Polish operator using one, that is exactly where the
navigation stops making sense. Four keys added to `common` **in all seven
languages**, and the components now take `useTranslation`.

Verified in the browser: the trigger reads "Показать или скрыть
навигацию" in Russian, and no `.sr-only` element in the document matches
`/^(Close|More|Sidebar|Toggle Sidebar|Displays)/`.

### Item names already worked; vehicle names did not

The user asked whether the bridge carries the translations. Traced:

- **Items: yes, already.** The bridge writes the name in the *server's*
  language, but `ItemController` overwrites it from `ItemName.json` for
  the *reader's* language — around 5000 entries per language, shipped
  with the game. Measured through the API: `Припарка из черемши`,
  `Okład z czosnku niedźwiedziego`, `Cataplasma de ajo de oso`. All five
  new languages worked with no change at all.
- **Vehicles: no.** The 213 names were compiled into
  `VehicleNames::NAMES` as English only. `en`, `de` and `ru` returned
  byte-identical lists.

### How different the vehicle names actually are

The user asked whether they are not the same in every language anyway.
Counted against the installation:

| | same as EN | differs |
|---|---|---|
| DE | 129 | 84 |
| ES | 128 | 85 |
| FR | 36 | 177 |
| IT | 43 | 170 |
| PL | 36 | 177 |
| **RU** | **0** | **213** |

Half right: the marques are shared — "Chevalier Nyala" is "Chevalier
Nyala" in Latin script. The qualifiers are not: *Trailer* is *Anhänger*,
*Remorque*, *Przyczepa*, *Прицеп*. And Russian transliterates the marque
too, so a Russian operator otherwise reads 213 Latin names inside a
Cyrillic interface. The user chose to keep the translation.

### `VehicleTranslations`, and where the body tiles came in

`backend/src/Server/Vehicles/Models/VehicleTranslations.php` follows
`ItemTranslations`: read `media/lua/shared/Translate/<CODE>/IG_UI.json`
over FTP, keep the `IGUI_VehicleName*` entries, cache for a week.

Three details that are not obvious:

- **`IG_UI.json` holds 7298 entries, of which 213 are vehicles.** Both
  the flat and the older `IG_UI`-nested shape are accepted.
- **One name wants an argument** — `IGUI_VehicleNameBurntCar` is
  "Verbrannt %1", the game filling in another vehicle's name. Skipped, so
  the English stands rather than a raw `%1` reaching the screen.
- **The body tiles are labelled separately.** `SpawnableVehicles::
  summarise()` names a body after its plainest member, in English, and
  that goes into the same cached catalogue. Translating only
  `items[].name` left 24 tiles Latin in a Russian session — measured
  in the browser: 3 of 24 Cyrillic. `VehicleTranslations::rename()`
  carries the item's translation across to its body, keeping the
  `— van` qualifier, which is a mask file name rather than a word. After:
  **24 of 24**.

The catalogue itself stays cached in one language-neutral shape; the
renaming happens on the way out, in `VehicleSpawnController::list()`.
The frontend sends `?language=` and holds it in the query key, as the
items page already did — without that, switching language shows the old
names from cache.

`rename()` lives on the class rather than in the controller so it can be
tested without an HTTP round trip. 12 unit tests, and the qualifier case
was proved by breaking the `explode` and watching it fail.

### Verified

- Backend **812** tests (was 800), frontend **399** (was 362), lint 0
  errors, build clean.
- In the browser: every new language renders with no raw key, no
  overflow and no live horizontal scroller — Russian across 11 routes at
  390px, Polish across 8, French across 6 at 1512px.
- Vehicle names measured per language through the API and on the page;
  English confirmed unchanged.

### Still open

- Nobody has read the five as a native speaker. The switcher says so.
- **The bridge's own 34 error messages are English**, and they reach the
  screen through `errorField(error, 'detail')` — `weather-page.tsx:136`,
  `ability-rows.tsx:99`, `character-card.tsx:77`. The fix is a stable key
  from the bridge translated in the panel, not translations inside the
  Lua: the bridge cannot know the reader's language, and every change to
  it costs an upload and a game-server restart. Not done here.

---

## 2026-09-08 — The panel deploys itself, and says why when it cannot

### How this started

The release pipeline called Coolify's deploy webhook from a GitHub
runner, and it failed with `403 You are not allowed to access the API`.
Diagnosing that by hand took an hour and three wrong turns, which is the
reason the final shape looks the way it does.

The probing, in order, because the sequence is the method:

| Probe | Answer | What it ruled out |
|---|---|---|
| `/api/health` | `OK` | The API is running |
| `/api/v1/deploy` with no token | `401 Unauthenticated` | — |
| Same with an invented token | `401 Unauthenticated` | The real token **is** recognised |
| Real token from a GitHub runner | `403 not allowed` | — |
| Real token from the user's own machine | **`403` as well** | Not the runner's address specifically |

That last one sent me to the wrong conclusion — I said the address list
was excluded and it must be the global switch. The screenshot showed
`API access: Enabled` with three addresses listed, and the user's current
IP was `84.155.168.132` against `84.155.166.233` on the list: **their own
address had changed**. So it had been the allow-list all along, and my
"proof" was a coincidence.

Then `405 This endpoint has changed to a POST request` — the recipe the
user had been given used GET.

### Why the call moved into the panel

Opening the list to `0.0.0.0` made Coolify print its own warning: *"API
access is open to every source."* The user was right to dislike it.

GitHub publishes **416 IP ranges** for Actions and changes them without
notice, so an allow-list cannot cover a runner. But the panel already
asks GitHub hourly whether a newer release exists
(`PanelUpdateChecker`), and it runs on the operator's own machine — an
address that is already on their list.

So the trigger moved from CI into the panel, and `0.0.0.0` came back out.

### What was built

| Piece | Where |
|---|---|
| The call, four outcomes | `backend/src/Panel/DeployTrigger.php` |
| The outcome and its advice | `backend/src/Panel/DeployOutcome.php` |
| Hourly, once per release | `backend/src/MessageHandler/DeployNewReleaseHandler.php` |
| The claim | `AppSettingRepository::claim()` / `release()` |
| Settings, test button, guide | `frontend/src/features/settings/deploy-card.tsx` |
| Endpoint | `POST /api/settings/deploy/test` |

Three settings: `deploy.webhook_url`, `deploy.webhook_token` (in
`SECRET_KEYS`), `deploy.on_release`. The tab is called **Coolify**, like
Steam and Discord beside it; the card title names what it configures —
"Auto-deploy in Coolify" — and the description says it replaces Coolify's
own switch and why that switch cannot work here.

### Deployed once, not once per panel

The user asked: with several panels running, does each one deploy?

**No, and not by agreement — by the database.** Each tries to insert a
row named `deploy.requested.<version>`; the name is the primary key, so
`ON CONFLICT DO NOTHING` lets exactly one through. No lock service, no
window where two can both read "nobody has it".

It answers two problems at once: two panels on one database, and the same
panel asking every hour until the container actually restarts.

The claim is taken **before** the call, not after. A hook that times out
may still have started a deployment, and asking twice is worse than
waiting for the next release.

Separate databases still deploy separately, which is correct — each panel
has to update itself.

`DeployClaimTest` proves it, and was proved by making `claim()` return
`true` unconditionally and watching it fail.

### The advice is the point

The user asked for failures to be explained with recommendations. An HTTP
code is a fact about the protocol, not an instruction, and the hour I
spent decoding a 403 is exactly what an operator should not repeat.

`DeployOutcome::adviceKey()` maps nine cases, and the interesting one is
the pair of 403s: **Coolify names the missing permission when a token is
short of one, and says nothing when the address list is what refused.**
So a bare 403 points at *Settings → Advanced → Allowed API IPs*, and a
403 mentioning a permission points at *Keys & Tokens*.

| Code | Advice |
|---|---|
| 403, no permission named | The address list |
| 403 naming a permission | Create a token with `deploy` |
| 401 | Token not recognised or revoked |
| **405** | **Ours to fix — update the panel** |
| 404 | Wrong uuid in the webhook |
| 429 | Coolify's 200/hour |
| 5xx | The platform itself |

Measured through the real interface against the user's real Coolify: an
invented token produced *"The token was not recognised. Check it was
copied whole, and that it has not been revoked under Keys & Tokens → API
Tokens."* with `HTTP 401 — {"message":"Unauthenticated."}` quiet
underneath.

### Two layout faults the user caught in a screenshot

My probe reported "no problems" both times, because it looked for
overflow and scrollbars — not for *unusably narrow*.

1. **The settings tab list ate half the frame.** At 820px with the
   sidebar open the content is 549px, of which the list took 192, leaving
   the card **285px**. Same fault as the tables earlier the same day and
   the same fix: the list switches to a select at **`lg`**, not `sm`.
   Card afterwards: **501px**.
2. **The warning beside the test button became a column** — 93px wide,
   240px tall. Now stacked above the button: **451 × 60**.

The probe grew a check for it: a paragraph narrower than 140px, taller
than it is wide, with more than 60 characters, is a column rather than a
sentence.

### Verified

- Backend **829** tests (was 812), frontend **399**, lint 0 errors.
- Nine settings sections × three widths in the browser: no overflow, no
  live scroller, no raw key, no narrow column, no target under 32px.
- The test button clicked for real against the user's Coolify, in German
  and English.

### Still open, and it is the user's to do

Coolify's *Allowed API IPs* currently contains `0.0.0.0`. With the
trigger now coming from the panel's own machine, that can go — the three
fixed addresses are enough.

### 1.2.1 — the token field could not be typed into

Reported immediately after the release: "ich kann den API-Token nicht
eingeben, dort lässt sich gar nichts eingeben."

`DeployCard` passed `value=""` to the token's `CredentialField`. A
controlled input with a constant value resets on every keystroke, so the
field looked normal and accepted nothing. The other secret fields on the
page spread `{...field(KEY)}`, which supplies the draft value as well as
the handler; my card took `onToken` but never the value to go with it.

Fixed by giving the card a `token` prop and passing
`field(SETTING_KEYS.deployWebhookToken).value`.

Verified in the browser end to end: typed, saved, `configured: true`,
the value correctly **not** returned in plaintext, and the test button
using the stored token. The probe data was removed again afterwards.

Worth naming as a shape: **a controlled input with a hardcoded value is
a read-only field that does not look read-only.** Nothing catches it —
not the type checker, not the test suite, not a screenshot. Only typing
into it does.

---

## 2026-09-08 — Item names came back English, and the panel said nothing

**Status: unfinished. Branch `fix/translation-diagnosis`, nothing
committed, backend edited, controller and frontend still to do.**

### The report

The user's production panel (1.2.1) showed a German interface with
English item names, and asked why: *"liefert unsere bridge doch bereits
die übersetzten namen der items und das hat doch auch schonmal
funktioniert."*

### What was measured, in order

**Locally the whole chain is intact.** `GET
/api/servers/<id>/items?language=de` returned `"language":"DE"` with
`Base.WildGarlicCataplasm = Bärlauch-Wickel`, `Base.PipeBomb =
Rohrbombe`, `Base.MakeupEyeshadow = Augen-Make-up` — the exact three
items their screenshot showed in English. The browser's network log
showed `items?language=de` → 200, and the rendered page showed
"Bärlauch-Wickel", "Rohrbombe", "Gasmaske". So the code was not the
fault.

**Then the user pasted their own response.** Two attempts: the first hit
my local server id and returned `{"status":"failed","error":
"errors.notFound"}` (my mistake — I gave them an id from my machine).
The second, against their own server, returned the full item list with
**no `language` field at all** and every name in English.

That is the proof. `ItemController` only sets `$catalogue['language']`
inside `if ($names !== [])`. The field being absent means
`ItemTranslations::forLanguage()` returned `[]` — the panel could not
read `media/lua/shared/Translate/DE/ItemName.json` over FTP, and said
nothing about it.

### The defect, named

`ItemTranslations::read()` had **four** `return []` for four different
causes:

| Cause | What the operator should do |
|---|---|
| no FTP credentials | configure them |
| `StorageException` — path missing | the base path points at the savegame dir, not the installation |
| `StorageException` — auth failed / unreachable | fix the login or the host |
| JSON not an array | the file is not what it should be |

All four looked like "this language has no translation", which is a
benign fifth case. Textbook rule 6c.

**Most likely cause on a rented server:** the transfer credentials open
on the savegame directory, so the game's own `media/` is somewhere else
entirely and the path simply is not there. That is a configuration
answer, not a bug — but only if the panel says it.

### What was written (backend, complete, lints clean)

**`backend/src/Server/Items/TranslationVerdict.php` — new.** A readonly
value object with six named states: `translated`, `noSuchLanguage`,
`pathMissing`, `noCredentials`, `unreachable`, `unreadable`. Carries
`language`, `names`, `path`. Plus:

- `fromStorageFailure(language, path, messageKey)` — maps
  `storage.authenticationFailed` and `storage.unreachable` to
  `unreachable`, everything else to `pathMissing`. This is the one place
  that separates "look somewhere else" from "fix the login", because
  `StorageException` carries both.
- `isTranslated()`, `needsAttention()` (true only for `pathMissing`,
  `unreachable`, `unreadable` — a language the game does not ship is not
  a fault and must not be shown as one), `toArray()`.

**`backend/src/Server/Items/ItemTranslations.php` — rewritten.**

- `forLanguage()` kept, now `verdictFor()->names`, so every existing
  caller is untouched.
- `verdictFor()` is the new entry point; it caches the **verdict**, not
  the array.
- **`FAILURE_TTL_SECONDS = 900`** beside `TTL_SECONDS = 604800`. This
  matters: the old code cached the empty array for a week, so an
  operator who fixed their FTP path would still see English names seven
  days later. A failure is worth retrying long before the game is
  patched.
- An empty-but-parsed table now returns `unreadable`, not `translated`.

Both files pass `php -l`.

### What still has to be done

1. **`backend/src/Controller/Api/ItemController.php`** — currently at
   `:57-72`:

   ```php
   $language = $request->query->getString('language', $request->getLocale());
   $names = $this->languages->supports($language)
       ? $this->translations->forLanguage($server, $language)
       : [];
   if ($names !== []) {
       $catalogue['items'] = array_map(..., $catalogue['items']);
       $catalogue['language'] = ItemTranslations::normalise($language);
   }
   ```

   Change to call `verdictFor()`, keep `language` as it is when
   translated, and **always** add `translation` => `$verdict->toArray()`
   so the state reaches the browser whichever way it went. Note
   `:88-90`, where the ETag is built from `$catalogue['language'] ??
   'none'` — the verdict state belongs in that ETag too, otherwise a
   fixed FTP path serves a cached English response.

2. **Frontend** — a line on the items page when
   `translation.state` needs attention, naming the path that was tried.
   Seven locales, per rule 1a.

3. **A test that fails when reverted.** The shape: a `FileBrowser` stub
   throwing `StorageException('storage.operationFailed', …)` must give
   `pathMissing`, one throwing `storage.authenticationFailed` must give
   `unreachable`, and a valid table must give `translated`. Plus one
   asserting the **failure TTL is the short one** — that is the part
   that silently rots.

4. **Vehicles have the same shape.** `VehicleTranslations` reads
   `IG_UI.json` the same way and swallows the same exception. Once the
   item side is proven, mirror it.

5. Then: build, commit, PR, merge, tag. Version would be **1.2.2**
   (`backend/config/services.yaml`), `BRIDGE_VERSION` stays 0.21.0 —
   the bridge is not involved.

### For the user, unchanged from before

- Remove `0.0.0.0` from Coolify's *Allowed API IPs*; the trigger now
  comes from their own machine.
- Redeploy to 1.2.1 and configure Settings → Coolify.
- The GitHub secrets `COOLIFY_WEBHOOK` / `COOLIFY_TOKEN` are no longer
  used by any workflow.

### Resume here — the exact next edit

Nothing is committed. `git status` on `fix/translation-diagnosis` shows
two files: `TranslationVerdict.php` (untracked, new) and
`ItemTranslations.php` (modified). Both lint clean. **The controller was
being read when the context ran out; not a character of it is changed
yet.**

`ItemController.php:52-93` reads today, verbatim:

```php
$catalogue = $this->catalogue->forServer($server, $request->query->getBoolean('refresh'));

$language = $request->query->getString('language', $request->getLocale());

$names = $this->languages->supports($language)
    ? $this->translations->forLanguage($server, $language)
    : [];

if ($names !== []) {
    $catalogue['items'] = array_map(
        static fn (array $item): array => isset($names[$item['type']])
            ? [...$item, 'name' => $names[$item['type']]]
            : $item,
        $catalogue['items'],
    );
    $catalogue['language'] = ItemTranslations::normalise($language);
}
// … JsonResponse …
$response->setEtag(sprintf(
    '%s-%d-%s',
    $catalogue['generatedAt'] ?? 0,
    $catalogue['fileSize'] ?? 0,
    $catalogue['language'] ?? 'none',
));
```

It becomes:

```php
$verdict = $this->languages->supports($language)
    ? $this->translations->verdictFor($server, $language)
    : TranslationVerdict::noSuchLanguage($language, '');

$names = $verdict->names;

if ($names !== []) {
    $catalogue['items'] = array_map(/* unchanged */);
    $catalogue['language'] = $verdict->language;
}

// Always present, whichever way it went: the empty case is the one
// worth explaining, and it is the one that used to say nothing.
$catalogue['translation'] = $verdict->toArray();
```

and the ETag's third segment becomes `$verdict->state` rather than
`$catalogue['language'] ?? 'none'` — **this part is easy to skip and
would cost an afternoon**: without it, an operator who fixes their FTP
path gets a 304 and still sees English.

`use App\Server\Items\TranslationVerdict;` has to be added; the
`ItemTranslations` import stays (the class is still used for
`normalise()` elsewhere in the file — check before removing it).

Then, in order: the four remaining points above (frontend line in seven
locales, the revert-proven test, `VehicleTranslations` mirrored,
`app.version` → 1.2.2), and finally `ddev exec -d /var/www/html/backend
"php bin/phpunit"` plus a browser check at all three widths.

**The one thing not yet known:** which of `pathMissing` /
`unreachable` / `unreadable` the user's server actually hits. The code
now distinguishes them, but nobody has seen the answer. Ask them to
reload the items endpoint once 1.2.2 is deployed and read
`translation.state` — that names the fix rather than guessing at it.

---

## 2026-09-08 (later) — the same game server, two panels, one of them English

**Status: in progress. Branch `fix/translation-diagnosis`, nothing
committed. Backend verdict work is written; three further pieces are
now in scope and not yet built.**

### What the user's screenshots settled, and what they overturned

Two screenshots changed the diagnosis, and the earlier entry above is
wrong in its conclusion — worth stating plainly rather than quietly
correcting.

**Screenshot one: the user's LOCAL panel, v1.2.1, Bridge v0.21.0,
against G-Portal `176.57.168.46` — the SAME game server as
production.** All four status lights green (Server, RCON, FTP, Bridge),
5092 items, and the names in German: "Bärlauch-Wickel", "Rohrbombe",
"Augen-Make-up", "Gasmaske", "Toilettenpapier".

That rules out everything the previous entry named as most likely:

| Earlier hypothesis | Now excluded because |
|---|---|
| FTP base path opens on the savegame dir | the same credentials read `media/lua/shared/Translate/DE/ItemName.json` fine |
| credentials refused / host unreachable | FTP light green, catalogue read |
| the game ships no DE file | it does; local proves it |

**So the fault is not in the game server and not in its
configuration.** It is a difference between the user's *two panel
instances*, both on 1.2.1, both talking to that same server.

**The user confirmed: production is its own Coolify instance with its
own database.** Therefore its own FTP credential rows, and — decisively
— **its own cache**.

### The hypothesis that now leads, with its mechanism

**A seven-day cached failure.** The old `ItemTranslations` cached the
empty array under `TTL_SECONDS = 604800`. If the production instance
ever got one empty read — during an FTP hiccup, or before the
credentials were entered at all — it holds that emptiness for a week,
long after the cause is gone. This fits the user's own words exactly:
*"Es hat doch schonmal funktioniert"* — it worked, it failed once, and
the cache wrote the failure down.

Two things made it worse, both already fixed in the working tree:

- `FAILURE_TTL_SECONDS = 900` beside the week-long success TTL.
- The ETag's third segment was `$catalogue['language'] ?? 'none'`,
  which is the same string for every failing cause — so a repaired
  server still got a 304. It is `$verdict->state` now.

### On the bridge — asked and answered, do not redo

The user asked whether the bridge should return translated item names.
**It cannot, and this is structural rather than a bug.**
`ZomboidControlBridge.lua:637` writes `item:getDisplayName()` and `:642`
tries `getItemNameFromFullType`. Both resolve through Zomboid's
`Translator`, which has **one active locale per process** — the
language the game server itself runs in, English here. Switching it
would change the running game's language for everybody.

That is precisely why the FTP route exists: the files sit on the server
one per language, so the panel reads the reader's language without
touching the game.

The user chose **"FTP-Weg fertigstellen"** over a bridge change. So:
**no bridge edit, no `BRIDGE_VERSION` bump, no upload and no server
restart in this piece of work.**

### Decisions the user made in this session

1. **`refresh` clears the translation cache too.** "Neue Fassung laden"
   handing back new items under a week-old failure's names is the
   collapsed-state fault again. Done: `verdictFor(..., bool $refresh)`,
   threaded from `ItemController`.
2. **A cache-clear control in Settings**, and after two corrections the
   placement is settled: **Einstellungen → a NEW group "Server" → an
   entry "Cache" in it.** Not the dashboard, not under "Datenschutz"
   where the first screenshot's arrow pointed.

### Written so far, all lint-clean, none committed

**`backend/src/Server/Items/TranslationVerdict.php`** — rewritten.
Seven states as `public const`: `TRANSLATED`, `NO_SUCH_LANGUAGE`,
`UNSUPPORTED_LANGUAGE`, `PATH_MISSING`, `NO_CREDENTIALS`,
`UNREACHABLE`, `UNREADABLE`. Prose comments cut to single lines per
rule 0b. `needsAttention()` is true only for `PATH_MISSING`,
`UNREACHABLE`, `UNREADABLE`.

`UNSUPPORTED_LANGUAGE` is new and replaces a misuse: the old code
returned `noSuchLanguage` for a language code the *panel* does not
speak, which is a different thing from one the *game* does not ship.

**`backend/src/Server/Items/ItemTranslations.php`** — `verdictFor(
GameServer, string $language, bool $refresh = false)`; caches the
verdict, not the array; TTL now keyed on `needsAttention()` rather than
on `names === []`, so `noSuchLanguage` gets the long TTL (it is a fact
about the game) while a failure gets 900 s.

New `explain()` makes `NO_SUCH_LANGUAGE` reachable, which it never was
before: a failed `readTail` asks `directoryExists('media/lua/shared/
Translate')` — present means the game ships no such language, absent
means the path is wrong. Skipped entirely when the key is
`storage.authenticationFailed`/`storage.unreachable`, because a refused
login must not be reported as a missing path.

**`backend/src/Controller/Api/ItemController.php`** — `verdictFor(...,
$refresh)`; `$catalogue['language'] = $verdict->language`;
`$catalogue['translation'] = $verdict->toArray()` **always**; ETag's
third segment is `$verdict->state`. Both `ItemTranslations` and
`TranslationVerdict` are imported (the former is still the constructor
type).

**`backend/tests/Unit/Server/Items/ItemTranslationsTest.php`** — new,
15 cases. Covers each state, that a refused login does not trigger the
directory probe (`expects(self::never())->method('directoryExists')`),
that a refresh re-reads and *overwrites* a held verdict, and that the
failure TTL is the short one.

### Not done — resume here

1. **`RecordingCache` test double is referenced but does not exist.**
   `ItemTranslationsTest::testRetriesAFailureLongBeforeItRetriesASuccess`
   uses `new RecordingCache()` and reads `->lifetime`. It needs a
   `CacheItemPoolInterface` implementation recording the int passed to
   `expiresAfter`. It replaced a helper that reflected into
   `ArrayAdapter::$expiries` (private, no getter — too fragile).
   `tests/Support/` holds no PHP classes today, only fixtures, so check
   the autoload map before choosing where it lives; the sibling
   `VehicleTranslationsTest` keeps its doubles inline.
   **The test file cannot run until this exists.**

2. **Settings → Server → Cache.** `frontend/src/features/settings/
   settings-page.tsx`: `SECTIONS` at `:56` and the `TabsList` at
   `:214-249` are two parallel lists, and `settings-tabs.test.ts` reads
   the TabsTrigger values out of this file so they cannot drift. A new
   `TabGroupLabel` for the group plus a `TabsTrigger value="cache"`,
   plus `settings.groupServer` and `settings.cacheTab` in **seven**
   locales. `SAVABLE_TABS` at `:48` must NOT gain 'cache' — it clears,
   it does not save.

3. **The backend endpoint behind that button.** Nothing exists yet.
   Needs a route, a permission, and a decision on scope: the item and
   vehicle translation keys (`items.names.*`, and the vehicle
   equivalent) plus the catalogues, or the whole pool. Per rule 10g the
   response must report what it actually cleared, and per 6c a clear
   that partly failed is its own state, not a success.

4. **Frontend line on the items page** when `translation.state` needs
   attention, naming the path tried. Seven locales.

5. **`VehicleTranslations` mirrors the same shape** — reads `IG_UI.json`,
   swallows the same exception, caches the same week. Do it after items
   is proven.

6. **`app.version` → 1.2.2** in `backend/config/services.yaml`.
   `BRIDGE_VERSION` stays 0.21.0. Then `ddev exec -d /var/www/html/
   backend "php bin/phpunit"`, a browser check at 390/820/1512, commit,
   PR, merge, tag.

### A separate defect found in passing, not yet fixed

**`backend/src/Server/Storage/ServerFileBrowser.php:117`** has its
`StorageException` arguments the wrong way round:

```php
throw new StorageException(sprintf('Cannot write to %s.', $target), 'storage.writeFailed');
```

The constructor is `(string $messageKey, string $message)`, so the
message key becomes an English sentence and the translation key becomes
the message. Only reachable in `download()` when the local sink cannot
be opened, so it has never been seen — but it would surface as an
untranslated string. Every other call site in the file has it right.

### What is still unknown

Which state the user's production server actually reports. The code now
distinguishes seven, and the panel will say — but nobody has seen the
answer. Once 1.2.2 is deployed: reload the items endpoint and read
`translation.state`. The strong expectation, given local works against
the same server, is that a **cache clear alone fixes it** — which is
what the new button is for.

### Verified in the browser (Playwright MCP), 2026-09-08

All at 390/820/1512 px, service worker unregistered first — it had
re-registered itself after `npm run build` and was intercepting
`/api/...` **before `page.route` could**, which cost two failed
interception attempts before it was spotted. Rule 10g0c again, in a new
guise: it does not only serve stale pages, it also defeats request
mocking.

**Settings → Server → Cache.** The group and the tab render at the
place the user's screenshot pointed to, footer reads `v1.2.2`. Clicking
"Zwischenspeicher leeren" → `POST /api/settings/cache` → **200** with
`{"state":"cleared","keys":21,"servers":1,"detail":null,"status":"ok"}`.
21 = 6 per-server keys + 2×7 languages + the global release key. The
confirmation renders with the right plural: *"21 Einträge für 1 Server
geleert."* At 390 px the tab list is replaced by the select, which shows
"Cache" — so the control exists at both widths.

**Item translations, read fresh after the clear.** `translation` is
`{state: "translated", language: "DE", path:
"media/lua/shared/Translate/DE/ItemName.json", count: 5138}` and the
page renders "Bärlauch-Wickel", "Rohrbombe", "Augen-Make-up",
"Gasmaske", "Toilettenpapier" over 5092 items. `pl`/`ru`/`it` with
`refresh=1` each read their own file: "Bomba rurowa" (5143),
"Трубчатая бомба" (5169), "Tubo bomba" (5143) — which also proves the
new `$refresh` really reaches `verdictFor`.

**The notice was proven by intercepting the response** and substituting
`state: "pathMissing"`, because this server is healthy and the fault
cannot be produced by clicking. It rendered in full: *"Item-Namen sind
auf Englisch — Die Sprachdatei des Spiels ist unter diesem Pfad nicht
auffindbar. … Versuchter Pfad:
media/lua/shared/Translate/DE/ItemName.json"*. With `state:
"translated"` no alert is rendered at all.

**Switching the language needs no cache clearing — measured, not
assumed.** The user proposed it twice, so it was tested through the
actual control rather than argued from the source: clicking *Polski*
fires exactly one request, `items?language=pl`, and the page then shows
"Okład z czosnku niedźwiedziego", "Bomba rurowa", "Cienie do powiek"
with no German left over. The cache key is
`items.names.<serverId>.<LANG>` and the query key is `['items', id,
i18n.language]`, so the languages live in separate entries and neither
staling nor clearing is involved. Clearing on a switch would *destroy
valid work*: going to Polish and back would re-fetch the 5138 German
names over FTP for nothing. The case the proposal was aiming at — a
*failed* entry held too long — is covered three other ways (900 s TTL,
`refresh`, the new button).

**Narrow-width probe: no horizontal scroll anywhere**, `docScrollsX`
and `mainScrollsX` false at all three widths on both pages. Content
frame at 820 px measures **549 px**, the value CLAUDE.md 10g0 already
records. Console: 0 errors.

### Pre-existing findings, NOT introduced here and NOT fixed

Left alone deliberately rather than mixed into this branch:

- **Touch targets under 32 px** (CLAUDE.md 10g0 sets 32 as the floor).
  On the items page: the ± steppers are **24×24**, the item type labels
  16 px tall, "Übersicht" breadcrumb 63×20. In the shell: the sidebar
  trigger **28×28**, "Danksagungen" 100×24, "Verbindungen" 46×24, the
  events expander 20×20. The sidebar trigger is the one that matters —
  it is how navigation opens on a phone.
- **`ServerFileBrowser.php:117`** has its `StorageException` arguments
  reversed, as recorded in the entry above.
- **React Router warns** `No HydrateFallback element provided to render
  during initial hydration` on every page.

---

## 2026-09-08 (evening) — the real cause: the image has no frontend/src

**Status: fixed and proven inside the built image. Branch
`fix/languages-in-the-image`. `app.version` → 1.2.3.**

### The correction, stated plainly

**Both earlier entries today name the wrong cause.** The seven-day
cached failure was a real defect and worth fixing, but it was *not* why
the user's item names were English. The cache-clear button did not help
them, which is the evidence that should have moved me off the
hypothesis sooner.

The user came back with: *"Nein die Item Namen sind immernoch englisch.
Und ich habe gesehen das der request an den Server eine leere response
zurück gibt."* — and offered their production panel to test against.

### What the new field settled in one request

`GET /api/servers/<id>/items?language=de` against
`https://zomboid.andreas-gerhardt.com`:

```json
"translation": {"state":"unsupportedLanguage","language":"de","path":null,"count":0}
```

`unsupportedLanguage` means `SupportedLanguages::supports('de')`
returned **false** — for German. No FTP call was made at all, which is
why `path` is null.

And the same endpoint with `language=en`:

```json
"translation": {"state":"translated","path":"media/lua/shared/Translate/EN/ItemName.json","count":4889}
```

**So FTP was never broken.** It read 4889 English names from the very
directory I had spent two entries suspecting. `basePath` is `/` and it
is correct.

This is the diagnosis field earning its keep on the first real use: it
named the cause instead of sending me back to the FTP path a third
time.

### The mechanism

`services.yaml:105` bound
`$localeDirectory: '%kernel.project_dir%/../frontend/src/i18n/locales'`.

`Dockerfile:84-85` copies **only** `/app` (the backend) and the built
assets to `/app/public/app`. Verified inside the image: `/frontend/src`
**does not exist**. So `glob()` returned nothing, and
`SupportedLanguages::all()` fell to its `if (!in_array('en', $found))`
branch and answered `['en']` — every language but English refused, with
nothing logged.

It therefore **never worked in production**, on any version. "Es hat
schonmal funktioniert" was true only of the local panel, which has
`frontend/src` beside `backend/`. Two panels, same version, same game
server, different filesystem — CLAUDE.md 6c's newest line, met in the
wild.

### The fix, in three parts

1. **`backend/src/Settings/SupportedLanguages.php`** — a
   `private const SHIPPED = ['de','en','es','fr','it','pl','ru']`
   unioned into whatever the directory yields. A directory that cannot
   be read is no longer an installation that speaks one language.
2. **`Dockerfile`** — copies `/build/src/i18n/locales` to
   `/app/resources/locales` and sets `ENV
   APP_LOCALE_DIRECTORY=/app/resources/locales`.
3. **`backend/config/services.yaml`** — `app.locale_directory` reads
   `APP_LOCALE_DIRECTORY` with the frontend path as its default,
   following the existing `app.repository` pattern.

The constant is the safety net, the copied files are the normal path.
Both were proven separately.

### Proven, not assumed

- **`tests/Unit/Settings/SupportedLanguagesTest.php`** — 5 cases, 14
  assertions. Written **before** the fix and it failed 3 with *"de is
  shipped but not supported"*, reproducing production locally.
  `testTheShippedFilesAndTheFallbackNameTheSameLanguages` reads the real
  locale directory and compares it against `SHIPPED`, so an eighth
  language without a constant entry fails here rather than silently
  going missing in the image only.
- **Inside the actually-built image**: `/frontend/src` absent, all seven
  json files present at `/app/resources/locales`, and
  `SupportedLanguages` answers `de,en,es,fr,it,pl,ru` with
  `supports('de') === true`. With the *old* path hardcoded it now also
  answers all seven — the net holds.
- 857 backend tests, 40 locale tests green.

### Still open

- **Not yet released.** Branch is committed but the PR, merge, tag
  v1.2.3 and release remain. Only after that deployment will the user's
  item names be German.
- **`openid.return_to` is `http://` on production** —
  `http://zomboid.andreas-gerhardt.com/api/connect/steam/check`, seen in
  the Steam login redirect while the site itself serves HTTPS. That
  points at `APP_PUBLIC_URL` lacking its scheme or set to `http://`
  there. Not touched in this branch; it is a separate defect and worth
  its own look, because CLAUDE.md 10j makes that variable
  load-bearing for every service→panel call.
- The pre-existing findings from the previous entry (sub-32px touch
  targets, `ServerFileBrowser.php:117` reversed arguments) are still
  open.

---

## 2026-09-08 (late) — v1.2.3 released; the Coolify test button rebuilt

**v1.2.3 is out.** The language fix from PR #15 shipped: gate → frontend
build → image pushed as 1.2.3 and `latest` → release published, all four
jobs `success`. The operator's item names should be German after their
next deployment.

### The new request, and the assumption it overturned

The user: *"der button 'Test senden' startet direkt ein deployment in
coolify. Können wir das so umbauen. das es zwar die verbindung prüft
aber nicht direkt das deployment startet?"* — plus, separately, a real
**"Jetzt deployen"** button, and: *"Können wir dann auch mit dem panel
automatisch darauf reagieren wenn das deployment in coolify durch ist
das es vielleicht automatisch von selbst neulädt"*.

The card's own text claimed this was impossible — *"man kann eine
Plattform nicht fragen, ob es klappen würde"*. **That was wrong.**
Coolify's OpenAPI (fetched from
`raw.githubusercontent.com/coollabsio/coolify/main/openapi.json`, 1 MB)
shows five token permissions — `read`, `read:sensitive`, `write`,
`deploy`, `root` — and a `GET /applications/{uuid}` that changes
nothing.

**The uuid is already in the webhook the operator pasted**
(`?uuid=7yjy9uoz032inopxjf2mzvcl`), so the probe needs no new field.

### Decisions the user made

1. **Two buttons**: a harmless "check the connection" and a real "deploy
   now" **with a plain-language confirmation** naming the restart.
2. **The browser follows the deployment and reloads.** This is
   structural rather than a preference: the panel restarts partway
   through its own deployment, so a backend watching itself would die
   mid-answer. `POST /deploy` returns `deployment_uuid`, and
   `GET /deployments/{uuid}` reports on that exact one.
3. The user **created a new Coolify token with `read` and `deploy`** and
   entered it locally and on production. The old one had `deploy` only —
   which is why the probe treats a 403 as *still deployable*.

### Backend: written, tested, and measured against the real Coolify

**`backend/src/Panel/DeployProbeVerdict.php`** — eight states:
`ready`, `noReadPermission`, `tokenRejected`, `notFound`,
`unreachable`, `notConfigured`, `noUuid`, `refused`. `looksReady()` is
true for `ready` **and** `noReadPermission`, because Coolify's `deploy`
and `read` are separate rights: a token that cannot read can still
deploy, and reporting that as broken would be the collapsed-state fault
again. Carries `applicationName` and `applicationState`.

**`backend/src/Panel/DeployProbe.php`** — `probe()` and
`statusOf($deploymentUuid)`. `readUrlFrom()` and `baseOf()` are static
and pure, so the uuid extraction is testable without HTTP. 401 →
`tokenRejected`, 403 → `noReadPermission`, 404 → `notFound`, anything
else → `refused` (kept as its own state, never bent).

**`backend/src/Panel/DeploymentStatus.php`** — `running`, `finished`,
`failed`, `cancelled`, `unknown`, `notFound`, `unreachable`.
**Coolify's `status` is a free string with no enum in the spec**, so
`fromReported()` maps the words it knows and keeps anything else as
`unknown` — and `unknown` is deliberately **not** settled, so the
interface keeps asking rather than declaring a result it cannot see.

**`DeployTrigger::deploymentUuidIn()`** plus a `deploymentUuid` on
`DeployOutcome`, so the interface follows the deployment it started
rather than the newest one it can see.

**`backend/src/Command/DeployProbeCommand.php`** — `app:deploy:probe`,
optionally with a deployment uuid. Useful to the operator later, and it
is how this was verified.

**Routes** in `SettingsController`: `POST /api/settings/deploy/probe`
and `GET /api/settings/deploy/status/{deploymentUuid}` — a GET because
the browser asks repeatedly and asking changes nothing.

### Measured against the operator's own Coolify — it works

`ddev exec … "php bin/console app:deploy:probe"`:

```
state         ready
http status   200
application   Zomboid Control Panel
running       running:healthy
detail        —
```

Nothing was deployed. And the probe endpoint through the browser
answered `{"state":"ready","status":200,...}` with HTTP 200.

### A bug found and fixed in the writing, worth its own note

`$body = mb_substr(trim($response->getContent(false)), 0, 500)` — the
truncation was meant for failure detail, and it **destroyed the success
answer**: Coolify returns roughly 8 kB of application, so the cut JSON
would not parse and `applicationName` came back `null` beside a 200. No
error, no exception, just an empty field.

The fix parses the whole body and shortens only the failure detail.
`testReadsTheNameOutOfAnAnswerLongerThanTheDetailLimit` fails with
*"Failed asserting that null is identical to 'Zomboid Control Panel'"*
when reverted — proven, not assumed. **A truncation applied before
parsing is a silent data loss**; that belongs in CLAUDE.md.

**26 unit tests** in `DeployProbeTest`, including
`testNeverCallsTheDeployHook`, which asserts the exact single request
`GET …/applications/{uuid}` — the reported bug cannot return.

### Not done — resume here

1. **`frontend/src/features/settings/deploy-card.tsx` is untouched**
   (164 lines). It still has one button wired to `testDeployHook` at
   `:45-48`, `:118-126`. Needs: a "check" button calling the new probe,
   a "deploy now" button behind a confirmation dialog naming the
   restart, and the progress/reload flow.
2. **`clearServerCache`-style API functions** for `probeDeploy` and
   `deployStatus` in `settings.ts` — pass the object, never
   `JSON.stringify` (rule 10f2).
3. **The reload flow**, which is the delicate part: after `POST
   /deploy`, hold the returned `deploymentUuid`, poll
   `/deploy/status/{uuid}`, expect the panel itself to become
   unreachable partway through (that is success, not failure), then poll
   `/api/health` until it answers and reload. An `unknown` status must
   not be read as done, and a failed deployment must say so rather than
   reloading into the old version.
4. **Seven locales** for every new key: `settings.deploy.probe.*` (eight
   states), the two button labels, the confirmation text, the progress
   text.
5. **Functional tests** for both endpoints, with the warning capture
   from rule 10h2.
6. **Remove the now-false sentence** `settings.deploy.testWarning`
   ("Das rollt wirklich aus: man kann eine Plattform nicht fragen, ob es
   klappen würde") in all seven locales — it is the claim this work
   disproved.
7. `app.version` → 1.2.4, then browser check at 390/820/1512, PR, merge,
   tag.

### Also open, from earlier

- **The cache card's sentence is unclear**: *"Die Namen werden beim
  nächsten Laden neu gelesen"* — the user asked *"welche Namen?"*. It
  means the item and vehicle names, and the sentence never says so.
  `settings.cacheClearedDetail_*` in seven locales.
- Their screenshot showed **"9 Einträge für 1 Server geleert"** where
  local said 21 — the difference is 2 languages instead of 7, which is
  the same `SupportedLanguages` bug, visible as a number. Should be 21
  after v1.2.3.
- **`APP_PUBLIC_URL` on production looks wrong**: the Steam redirect
  carried `openid.return_to=http://zomboid.andreas-gerhardt.com/...`
  while the site serves HTTPS.
- Sub-32px touch targets; `ServerFileBrowser.php:117` reversed
  `StorageException` arguments.

### Frontend done, verified in the browser — 2026-09-08, late

`deploy-card.tsx` now has **two buttons**. Measured with a request
listener attached:

- **"Verbindung prüfen"** fires exactly `POST /api/settings/deploy/probe`
  and nothing else, and renders *"Verbindung steht — Anwendung: Zomboid
  Control Panel · running:healthy"*. The reported bug cannot recur.
- **"Jetzt deployen"** opens the confirmation naming the restart, and
  **Abbrechen issues no request at all** (`callsAfterCancelling: []`).

New files: `use-deployment-watch.ts` (the browser follows the
deployment, expects the panel to vanish mid-flight, then polls
`/api/health` and reloads), `deploy-confirm.tsx`.
`testDeployHook` was renamed `triggerDeployment` — "test" was the wrong
word for the button that replaces the panel. `settings.deploy.test` and
`settings.deploy.testWarning` are deleted from all seven locales; the
warning was the false claim this work disproved.

888 backend tests, 403 frontend tests, 40 locale tests, lint 0 errors,
tsc clean, build clean. No horizontal scroll at 390/820/1512; the two
new buttons are both above the 32px floor.

### Two environment problems hit on the way, both worth knowing

1. **`backend/.env` had lost its `DATABASE_URL` line** — a tracked
   file, modified outside this work, which broke *every* functional
   test with `EnvNotFoundException` (including the already-merged
   `CacheClearTest`, which is how it was identified as environmental
   rather than mine). `git checkout -- backend/.env` restored it. Worth
   watching: if it disappears again, something is rewriting that file.
2. **The browser's HTTP cache served a stale `index.html`**, asking for
   an asset hash that no longer existed; the server answered the SPA
   shell and the module died on MIME type with a blank page and one
   console error. Unregistering the service worker did **not** fix it;
   `Network.clearBrowserCache` over CDP did. Added to CLAUDE.md 6c.

### Still open after this

- The cache card's *"Die Namen werden beim nächsten Laden neu
  gelesen"* — the user asked *"welche Namen?"*. Not yet reworded.
- `APP_PUBLIC_URL` on production carries `http://` in the Steam
  redirect.
- Sub-32px touch targets; `ServerFileBrowser.php:117`.

### v1.2.4 tagged — 2026-09-08

`4858a4c` on main, three things in it:

- **PR #16** — the Coolify probe: "Verbindung prüfen" reads
  `GET /applications/{uuid}` and starts nothing; "Jetzt deployen" is its
  own button behind a confirmation; the browser follows the deployment
  and reloads.
- **PR #16** — the cache confirmation names *which* names it affects
  (the user asked "welche Namen?").
- **PR #17** — the README badge pointed at `ci.yml`, deleted when the
  pipeline was split into `checks.yml` / `main.yml` / `release.yml`. It
  showed "repo or workflow not found" on the project page in both
  READMEs. Now `main.yml`, verified by fetching both URLs: the new one
  renders "passing", the old one the error. All six badges were checked;
  PHP 8.4 and React 19 match `composer.json` and `package.json`.

**The badge is the same family as everything else found today**: broken
since the pipeline split, visible to every visitor, and covered by no
test. Worth remembering that a rendered badge is a claim nothing
verifies.

`main.yml` for `4858a4c` concluded `success`, so the release gate's
conditions were met before tagging.

### For the operator, after v1.2.4 deploys

- The **first** rollout to 1.2.4 still happens under the old panel, so
  the old single button applies this once. From 1.2.4 the two are
  separate.
- v1.2.3 already carries the language fix, so item names should be
  German. If not, the items page now names the state.

---

## 2026-09-08 (night) — Steam got an http return address

**Branch `fix/steam-return-url`. `app.version` → 1.2.5.**

### The report

Noticed while signing in to the user's production panel: Steam's login
page carried

```
openid.return_to=http://zomboid.andreas-gerhardt.com/api/connect/steam/check
```

while the site itself serves HTTPS. Steam shows that address to the
reader as the site asking them to sign in, and the return trip would
have been unencrypted — CLAUDE.md 5 calls Steam OpenID an account
takeover risk if handled loosely.

### Two causes, one on top of the other

**1. Three call sites built the URL from the request.** CLAUDE.md 10j
already says not to: *"Build the URL from APP_PUBLIC_URL, never from the
request. Behind a proxy the request host is the container's."* Google
and Discord follow that; Steam never did.
`ConnectController::steam()`, `::linkSteam()` and
`SteamAuthenticator::returnUrl()` all called `generate(...,
ABSOLUTE_URL)`.

They are now one class, `SteamReturnUrl`, and that matters beyond
tidiness: **OpenID compares `return_to` on the way out against the one
presented at verification**, so three copies that could drift is a
login that breaks when one is fixed.

**2. `trusted_proxies` was configured nowhere at all.** So Symfony
discarded Coolify's `X-Forwarded-Proto` and `$request->isSecure()` was
false. `framework.yaml` now sets `trusted_proxies: '%env(TRUSTED_PROXIES)%'`
with the four forwarded headers, defaulting to the private ranges
Docker and Coolify use — **deliberately not `REMOTE_ADDR`**: the
compose file uses `expose` rather than `ports` so only the proxy can
reach the container, but trusting whoever connects would stop being
safe the moment somebody published a port.

### A third defect found while fixing it

`ConnectController::googleLinkUri()` called `$this->urls` — **a
property that did not exist**. The constructor took only
`IdentityLinker` and `EntityManagerInterface`. So "link a Google
account" from the profile page would have died on an undefined
property. It never showed up because nothing tests that path and the
container does not check property access. `$urls` and `$publicUrl` are
constructor arguments now, and the method builds from `APP_PUBLIC_URL`
like everything else.

### Why this never appeared locally — worth knowing

**ddev produced `https` even with the old code.** Reverting
`SteamReturnUrl` to request-derived behaviour and asking
`/api/connect/steam` still returned
`return_to=https://zomboidcontrol.ddev.site/...`, while 6 of the 8 unit
tests went red. ddev's proxy setup satisfies Symfony where Coolify's
does not.

So the local environment **cannot reproduce this class of fault**, and
the unit tests are the only thing that can. Same shape as the
`SupportedLanguages` bug from this afternoon: identical code, two
environments, different behaviour.

### Proven

- `tests/Unit/Security/OAuth/SteamReturnUrlTest.php` — 8 cases:
  https is preserved, a missing scheme becomes https, a trailing slash
  is not doubled, an explicit `http://localhost` is kept for local
  setups, login and linking use different routes,
  `testDoesNotAskTheRequestForTheHost` asserts `ABSOLUTE_PATH` is what
  is requested. 6 fail on revert.
- **In the browser**: `/api/connect/steam` redirects to Steam with
  `openid.return_to=https://zomboidcontrol.ddev.site/api/connect/steam/check`.
- 896 backend tests green; `lint:container` clean; an empty
  `TRUSTED_PROXIES` does not break the container.

### Left alone

The release badge showing v1.2.3 after v1.2.4 shipped is **GitHub's
Camo image proxy**, not a configuration fault: the README URL is
correct and shields.io answers `v1.2.4` directly, but Camo serves an
older copy with a steady `age` header. The user chose to leave it —
nothing in this repository can change it, and a cache-busting parameter
would force a README edit per release.

---

## 2026-09-08 (night) — texture packs could not be uploaded at all

**Branch `fix/large-icon-packs`. `app.version` → 1.2.6.**

### The report and the measurement

The user: *"Ich kann keine Texturpakete hochladen"*, with production
answering **500** on `POST /api/icons/upload`. Reproduced against their
own instance with the real packs from `/Volumes/ESD-USB/texturepacks`.

The answer came from the server itself, verbatim:

```
PHP Request Startup: POST Content-Length of 20971719 bytes
exceeds the limit of 16777216 bytes
```

`post_max_size = 16M` in `docker/php.ini`, and **`UI2.pack` is 52 MB**
-- the pack holding most of the item icons. PHP refuses an oversized
body **at startup, before any application code runs**, so the answer is
HTML with a 500 rather than a reason in JSON. Nothing in the controller
could have caught it.

### Coolify was not the constraint — measured, not assumed

The user asked whether the limits could be raised in Coolify. Probed
with 20, 60 and 100 MB bodies: **every one reached PHP**, and the
refusal carried PHP's own wording each time. A proxy limit would have
answered 413 before PHP saw anything. So there is nothing to change in
Coolify; the only limit was the one in this repository.

### The gap was in the frontend, and the backend said so

`IconController` has had `/icons/chunk` and `/icons/finish` all along,
with a comment naming this exact case -- *"UI2.pack is 54 MB and a
modded install can carry larger ones, against a container that accepts
a 16 MB request"* -- and `php.ini` said *"arrives in 8 MB pieces, so no
single request needs to be large."* **The interface never called
them.** `uploadIconPacks` put every file into one `POST /icons/upload`.

### What changed

- **`frontend/src/lib/chunks.ts`** — new. `CHUNK_BYTES = 8 MB`,
  `chunkOffsets()` and `sentAfter()`, both pure: an off-by-one there is
  a piece that never arrives, and that is testable without a network.
- **`uploadIconPacks`** sends each pack in pieces, then `/finish`, and
  reports progress per file and per piece.
- **`POST /api/icons/clear`** — new route. A piecewise upload has no
  single request to carry a "clear first" flag.
- **A progress bar** in `icon-packs-card.tsx` with the file counter,
  the megabytes and `role="progressbar"` carrying real aria values.
- **`MAX_TOTAL_BYTES`** 95 MB → 512 MB. The pieces are what keep a
  request small now, so the client-side cap only stops somebody
  dropping a whole game folder in.
- **`docker/php.ini`** 16M → 64M/68M anyway, as the user asked for
  both: a browser that sends one whole file should not meet a 500 from
  PHP's startup.
- **Vehicle models get the same treatment**, at the user's request
  (*"Vielleicht haben wir ja irgendwann mal ein mod modell was größer
  ist"*). Measured first: the base game's largest is 764 kB and the 15
  biggest together are 5 MB, so the existing batching was never at
  risk. `needsChunking()` sends anything over 8 MB alone and in pieces;
  everything else keeps the batch path, which is far fewer round trips
  for the 591 files an install holds. New routes
  `/vehicle-models/chunk` and `/finish`.
- **`ChunkedUpload` generalised** rather than copied:
  `safeFileName(...$extensions)` and a `$mustLookLikeAPack` flag, since
  an `.fbx` is not a `.pack`. Its 5 existing tests still pass.

### Two faults found while testing, both fixed

1. **`{{total}}` meant two things** in the new locale strings -- the
   number of files in one, the file's size in MB in the other. Renamed
   to `{{files}}` and `{{size}}`. CLAUDE.md 6c, in a translation file.
2. **The vehicle count was clamped to one line.** `AlertTitle` carries
   `line-clamp-1`, right for a short heading and wrong for *"192
   Modelle und 403 Texturen vorhanden"*, which read *"...403
   Texturen…"* on a phone. `line-clamp-none` at that one call site;
   the base class is left alone.

Also worth recording: a "152" I read as a bug was **my own probe**
concatenating two adjacent lines. Read separately, the DOM said
"Datei 1 von 1" all along.

### Proven

- **11 unit tests** in `chunks.test.ts` covering the offsets, the
  boundaries (empty file, exactly one piece, exactly the limit) and
  which vehicle models travel alone.
- **In the browser, against the real 52 MB `UI2.pack`**: 7 chunk
  requests, one `/finish`, **no errors**, and the card showed
  *"UI2.pack — 3848 Icons aus 15 Seiten"*. `UI.pack` gave 563 and
  `ApComUI.pack` 44.
- **The progress bar sampled mid-flight**: 0 % → 15 % → 31 % → 61 % →
  100 % with "0.0 → 52.1 von 52.1 MB übertragen". A screenshot
  afterwards cannot show that the middle existed.
- **A small vehicle model still takes the batch path** (1 `/upload`, 0
  chunks), so the common case did not get slower.
- 414 frontend tests, 896 backend, 40 locale, lint 0 errors,
  `lint:container` clean.
- **390 / 820 / 1512 px on both cards**: no horizontal scroll anywhere,
  symmetric 16 px padding, and the screenshots read cleanly. The
  remaining sub-32px control is the shadcn `Switch` (32×18), which is
  the same everywhere in the panel.

---

## 2026-09-08 (night, later) — GD was missing from the image

**Branch `fix/gd-in-the-image`. Three more requests queued behind it.**

### The report

v1.2.6 deployed, and **all five packs failed** -- including the small
ones that had worked before, so it was no longer about size. Measured
against production:

| Request | Answer |
|---|---|
| `POST /api/icons/chunk` | **200** |
| `POST /api/icons/finish` | **500** |

The upload arrives; assembling it fails. Locally both answer 200.

### The cause

`Dockerfile:27` installs `pdo_pgsql zip intl ftp sodium curl mbstring
xml fileinfo` -- **no `gd`**. `IconExtractor` needs
`imagecreatefromstring`, `imagecreatetruecolor` and `imagepng` to cut
icons out of the atlases; without the extension those functions are
undefined and PHP dies with a fatal error, which reaches the browser as
a 500 and the card as "Der Upload ist fehlgeschlagen".

That is exactly why `/chunk` worked and `/finish` did not: the first
only writes bytes to disk, the second decodes an image.

**ddev ships gd** (`php -m` confirms), and `composer.json` did not
require it, so nothing local could ever have caught this. **The fourth
time today** that identical code behaved differently in the two
environments -- after `SupportedLanguages`, the Steam scheme, and the
panel cache.

### The fix

- **`Dockerfile`** — `libpng-dev libjpeg62-turbo-dev libfreetype6-dev`,
  `docker-php-ext-configure gd --with-freetype --with-jpeg`, and `gd`
  in the install list.
- **`backend/composer.json`** — `"ext-gd": "*"`. Lock refreshed with
  `composer update --lock`, which changed only the content hash plus
  that line. From now on a missing gd stops `composer install` in CI
  rather than surfacing as a 500 in production.

**Not yet verified in a built image**: Docker Hub answered 500 to the
token request while trying (`failed to fetch anonymous token`). The CI
image job will prove it; if that also fails, retry the local build.

### Three further requests from the user, not yet built

1. **The release check is hourly; they want 5 minutes or less.**
   `MainSchedule.php:44` has `RecurringMessage::every('1 hour', new
   DeployNewRelease())` -- but the comment there is load-bearing:
   *"The update check itself is cached for six hours, so asking more
   often would only re-read the cache."*
   `PanelUpdateChecker::CACHE_SECONDS = 21600`. **Both have to come
   down or the shorter interval does nothing.** Mind GitHub's
   unauthenticated rate limit of 60 requests an hour -- at 5 minutes
   that is 12/hour for one panel, which is fine, but the cache should
   still absorb bursts.
2. **"Neue Fassung" appears after a redeployment**, even after the
   panel reloaded itself when the Coolify deploy finished. Cause:
   `use-deployment-watch.ts` calls `window.location.reload()`, which
   does **not** replace the service worker -- it keeps serving the old
   build, so the update prompt appears immediately afterwards. The user
   is right that the button makes sense for the PWA in general; it
   should just not be needed straight after a deploy the panel itself
   triggered. Rule 10g0c, met from the other side: unregister the
   worker (or `registration.update()` then `skipWaiting`) before
   reloading.
3. Same point restated: pressing **"Jetzt deployen"** should end in a
   panel that is genuinely on the new version, without a second manual
   step.
