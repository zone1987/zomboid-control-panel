# CONTEXT — ZomboidControl

Complete state of the project. Read this first; append after every task.

---

## What this is

A web interface for administering Project Zomboid dedicated servers. Symfony
as a JSON API, React as the frontend, deployed as one Docker container plus
PostgreSQL — locally, on a server, or in Coolify.

The work is split into five sub-projects; see `docs/superpowers/briefs/`.
Sub-project 01 (foundation, authentication, server configuration) is in
progress.

---

## Current state — 2026-09-04

### Verified working

| Area | Evidence |
|---|---|
| Symfony 7.4.18 on PHP 8.4.24 | running in ddev |
| PostgreSQL 17.11 | 7 tables migrated |
| Credential encryption | 13 tests, including proof no plaintext reaches the database |
| Setup wizard | creates the first admin, then refuses; 6 tests plus browser run |
| Password login | 7 tests plus browser run; session survives reload |
| Passkeys | registration, login, management; verified with a virtual authenticator |
| TOTP two-factor | setup, login, recovery codes; verified with real codes in a browser |
| Frontend to backend | React reads `/api/health` through the Vite proxy |

45 backend tests green. Frontend builds without errors.

### Not yet built

- Google and Steam sign-in (buttons exist, routes do not)
- Invitations by mail, password reset
- Server configuration forms, connection test, directory browser, bridge upload
- Everything in sub-projects 02 to 05

### Known open risks

1. **RCON client unproven.** Zomboid speaks Source RCON (verified), and
   `xpaw/php-source-query-class` implements Source RCON (verified). That the
   two work together is **not** verified. Spike this before building on it.
2. **Production image never built.** The compose file is syntactically valid;
   the image has not been built or run.
3. **`data-grid` needs Base UI.** ReUI's data grid expects the Base UI variants
   of `dropdown-menu`, `select` and `checkbox`. Removed for now; the decision
   comes due when player lists are built.
4. **Bundle over 500 kB.** No code splitting yet.

---

## Facts established by research

Checked against primary sources. Do not re-litigate these without new evidence.

### Symfony 7.4 is forced

`scheb/2fa-bundle` v8.6.1 requires `symfony ^7.4 || ^8.0` on `php ~8.4.0`.
`DoctrineEncryptBundle` 7.0.x requires `6.4 || 7.4 || 8.0` independently.
Native SSE classes arrived in 7.3.

### Sessions, not JWT

`scheb/2fa` documents that the firewall must be stateful. WebAuthn stores its
challenge in the session by default. `EventSource` cannot send an
`Authorization` header. Same-origin removes the CORS argument for tokens.

### `SameSite=Lax`, not `Strict`

`Strict` withholds the cookie on the Steam OpenID cross-site return.

### Zomboid, verified against projectzomboid.jar build 42.20.2

- **No server-side chat event.** `OnAddMessage` is used only in
  `media/lua/client/Chat/ISChat.lua:1176` — it fires in the client. Chat must
  be read from the engine's `_chat.txt` log and sent via RCON `servermsg`.
- **No HTTP, no sockets in Lua.** Only `getFileWriter` / `getModFileWriter`,
  sandboxed to the Zomboid data folder. The file bridge is mandatory.
- **`EveryTenMinutes` and `EveryHours` do not exist** in this build. Use
  `OnTick` with a counter.
- **RCON replies are free text**, not JSON. Status via files, actions via RCON.
- Player data: `getUsername`, `getSteamID`, `getX/Y/Z`, `getHealth`,
  `getBodyDamage()`, `getPerkLevel`, `getTraits`, `getHoursSurvived`,
  `getAccessLevel`.
- Safehouses: `SafeHouse.getSafehouseList()` with coordinates, owner, members.
- B42 has a real role system (`Roles`/`Role` with capabilities), but whether it
  is writable from Lua is **not** established.

### Interface libraries

- ReUI's catalogue (1719 entries) is readable without a licence.
- Its **77 UI components install freely**: `tree`, `data-grid`,
  `number-field`, `code-block`, `filters`, `kanban`, `gantt`, `event-calendar`.
- Its **1638 page blocks return HTTP 401** without a licence key.
- shadcn's `marker` is an ARIA text marker, **not** a map component. The live
  map needs an external library.

---

## Findings from implementation

Things that cost time and would cost it again.

**Doctrine types cannot be constructor-injected.** The cipher reaches
`EncryptedStringType` through a Doctrine middleware. Kernel and console events
were tried first and do not fire for migrations or tests.

**WebAuthn has two handler contracts.** The firewall wants Symfony's
`AuthenticationSuccessHandlerInterface`; the registration controllers want the
bundle's own `SuccessHandler`. Not interchangeable.

**`CanSaveCredentialRecord` is required.** Without it the bundle validates a
ceremony and stores nothing, answering 501.

**Lazily loaded collections read zero.** The rule protecting the last passkey
counted through a collection and would have let an account lock itself out. It
counts in the database now.

**Logout redirected.** The default 302 leaves an SPA hanging; a `LogoutEvent`
listener answers JSON.

**scheb needs every token type listed.** `WebauthnToken` had to be added to
`security_tokens`, or a passkey login skips the second factor entirely.

**The TOTP provider is off until configured.** Without a `totp:` section the
`TotpAuthenticatorInterface` service does not exist and autowiring fails.

**Flex skipped two recipes.** `symfony/apache-pack` and the WebAuthn bundle
were marked `IGNORING`; `.htaccess` and `bundles.php` entries were written by
hand.

**`node_modules` are platform-bound.** Installed for Linux in the container.
Running npm on the host fails with missing native bindings.

---

## Next concrete step

Sub-project 01, step 6, remainder: Google and Steam sign-in, invitations by
mail, password reset.

Alternatively step 7 (server configuration), which should begin with the RCON
spike named under open risks.

---

## Log

### 2026-09-04 — Foundation
Symfony 7.4 skeleton, all auth packages at researched versions, PostgreSQL,
Apache in ddev, `CredentialCipher` with 11 tests.
Commit `722b8d8`.

### 2026-09-04 — Data model
Seven entities and tables. Cipher injection moved to a Doctrine middleware
after the event-listener approach failed for migrations and tests. Two
integration tests prove no plaintext reaches the database.
Commit `d36217b`.

### 2026-09-04 — Frontend and container
Vite 8, React 19, Tailwind v4, shadcn plus free ReUI components. Multi-stage
Dockerfile, compose file, CI workflow. `ddev dev` and `ddev setup`.
Verified in a browser: React reaches the API and reads PostgreSQL.
Commit `7506010`.

### 2026-09-04 — Setup wizard and password login
Wizard creating the first administrator and refusing every later call.
`json_login` with enumeration-resistant failures. Route guards. Validation
messages as translation keys. Two corrections: setup status was cached
indefinitely so the redirect never fired; Zod messages were untranslated.
Commit `769020a`.

### 2026-09-04 — TOTP two-factor
Setup with a session-held pending secret, ten single-use recovery codes stored
hashed, password confirmation for disabling and regenerating. QR code rendered
in the browser from the otpauth URI. `WebauthnToken` added to scheb's
security_tokens so a passkey login cannot bypass the second factor.
Verified in a browser with real TOTP codes: login halts at the second factor,
both an authenticator code and a recovery code sign in, the used recovery code
is consumed, a wrong password does not disable.
Commit `18063ff`.

### 2026-09-04 — Passkeys
Registration, login and device management. Four findings recorded above.
Verified with a virtual authenticator: key stored, login without email or
password, `lastUsedAt` recorded, signature counter advancing 1 → 2.
Commit `10a4c59`.
