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

Work is split into six sub-projects; see `docs/superpowers/briefs/`.
Sub-project 01 is complete. Sub-project 02 is in progress.

---

## Next concrete step

**Sub-project 02, remaining work: the player management interface.**

The backend is finished and verified against a live server. What is missing
is the frontend:

1. A player list page at `/servers/:id/players` — table of who is online,
   with position, health, infection, access level, skills and traits.
   Endpoint: `GET /api/servers/{serverId}/players`.
2. Kick, ban and unban from that list. Ban needs a duration picker: the
   preset list (1h, 2h, 6h, 12h, 1 day, 2 days, 5 days, 1 week, permanent)
   plus a free-entry field, sent as `durationMinutes`.
3. A ban list and moderation history, from `GET .../players/bans` and
   `GET .../players/history`.
4. Access level selector per player, from the six values in
   `PlayerModerator::ACCESS_LEVELS`.

Two smaller items also outstanding:

- The deliverability check has an endpoint
  (`GET /api/settings/mail/deliverability`) but no button in the interface.
- ReUI's `data-grid` was removed early on because it needs Base UI variants
  of dropdown-menu, select and checkbox. The player list is where that
  decision comes due: either install the Base UI variants, or build the
  table with plain shadcn parts.

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
| Player list backend | **verified: position, health, access level all correct** |
| Moderation backend | kick, ban, unban, access level; timed bans via scheduler |
| Mail sending | **user received a test message** (in spam, see below) |

115 backend tests green. Frontend builds without errors.

### Not yet built

- Player management interface (see "Next concrete step")
- Everything in sub-projects 03 to 06

### Known open risks

1. **Production image never built.** The compose file is valid; the image has
   not been built or run. This is the largest untested area.
2. **`data-grid` needs Base UI.** Decision due with the player list.
3. **Bundle over 500 kB.** No code splitting yet.
4. **Mail lands in spam** without DKIM. Not a defect in the application — see
   the DNS section below — but new operators will hit it.

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
