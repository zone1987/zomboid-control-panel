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

## Next concrete step

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
is the pyramid depth, and it had never reached the renderer. At the
default of 2 the same world is **197 k tiles and 45 GB, about four hours
each way**. Both savings multiply: the survey skips 96 % of the passes,
and dropping two levels quarters what the rest produce twice over.

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
