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
`/Users/andreasgerhardt/Downloads/screen-map (1).jsx`. Only the map imagery is
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
- Test account: `a.gerhardt1987@gmail.com`, password
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
The user supplied `/Users/andreasgerhardt/Downloads/screen-map (1).jsx` as a
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
