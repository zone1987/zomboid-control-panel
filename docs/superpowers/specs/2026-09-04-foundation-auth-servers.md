# Foundation, authentication and server configuration

**Brief:** [../briefs/01-foundation-auth-servers.md](../briefs/01-foundation-auth-servers.md)
**Plan:** [../plans/01-foundation-auth-servers.md](../plans/01-foundation-auth-servers.md)
**Date:** 2026-09-04

## Decisions and the evidence behind them

Every claim below was checked against a primary source. Where something could
not be established, it says so.

### Symfony 7.4 is required, not preferred

`scheb/2fa-bundle` v8.6.1 constrains to `symfony ^7.4 || ^8.0` on
`php ~8.4.0`. `DoctrineEncryptBundle` 7.0.x independently requires
`6.4 || 7.4 || 8.0`, and the native SSE classes arrived in 7.3. Three packages
point at the same floor.

### Cookie sessions, not JWT

Three separate reasons, any one of which would be sufficient:

1. `scheb/2fa` documents that the firewall must be stateful. The state between
   "password accepted" and "second factor supplied" has to live somewhere.
2. WebAuthn stores its challenge between `/options` and `/result`; the bundle's
   default `options_storage` is `SessionStorage`. Without it the replay
   protection does nothing.
3. `EventSource` cannot set an `Authorization` header. With a token the SPA
   would have to put it in the query string, where it lands in access logs.

Same-origin deployment removes the usual reason to reach for tokens: there is
no CORS problem to solve.

### SameSite=Lax, not Strict

`Strict` would break the Steam OpenID return: the cookie is withheld on a
cross-site redirect, so the user arrives back unauthenticated.

### Encryption, not hashing, for third-party credentials

FTP and RCON passwords must be recoverable — the application signs in with
them. The TOTP secret likewise. Account passwords and backup codes are hashed,
because nothing ever needs to read them back. Mixing the two up is the classic
error here.

`sodium_crypto_secretbox` (XSalsa20-Poly1305) is authenticated, so tampering
in the database is detected rather than silently decrypting to garbage. The
key lives in an environment variable; a database dump alone is useless.
Ciphertexts carry a `v1:` prefix so a future key rotation has somewhere to
stand.

`symfony/secrets` is the wrong tool for the data — it encrypts configuration
at deploy time — but the right tool for managing the key.

## Architecture

One container, three processes. A multi-stage build compiles the React app
with Node 24, then serves it from PHP 8.4 with Apache and PHP-FPM. Apache
serves `/app` as static files and routes `/api` to FPM. Supervisor keeps a
Messenger worker alive for outbound mail. PostgreSQL is the only other service.

### Two FPM pools

Each open SSE connection occupies one FPM worker for its entire lifetime. With
a single pool, a handful of open browser tabs starves ordinary API requests and
the whole application appears dead. The SSE routes therefore get their own pool
with its own `pm.max_children`, plus a 60-second cap per connection —
`EventSource` reconnects by itself, steered by the `retry` field.

`session_write_close()` must run before any streaming loop. Without it the
session lock blocks every other request from the same user, and nothing in the
logs says why.

## Data model

Seven tables. `app_user` holds the account; `webauthn_credential` is a 1:n
relation so one account can hold a passkey per device; `oauth_identity` links
Google's `sub` or a SteamID64; `invitation` stores only a token hash, the
plaintext existing solely in the mail. `game_server` carries `ftp_config` and
`rcon_config`, whose passwords use the `encrypted_string` Doctrine type.

The WebAuthn user handle is the account UUID rather than the email address, so
changing an address does not invalidate existing passkeys.

## Authentication

Four ways in, one session:

- **Email and password** through `json_login`. A wrong password and an unknown
  account return the same message, so the endpoint cannot be used to discover
  which addresses are registered.
- **Passkeys** through `web-auth/webauthn-symfony-bundle` 5.3.8 with
  `@simplewebauthn/browser` 14.0.0.
- **Google** through `knpuniversity/oauth2-client-bundle`.
- **Steam** through OpenID 2.0 — not OAuth2; no League provider exists.

The login success handler distinguishes a `TwoFactorTokenInterface` from a
completed login. That single check is what lets the 2FA step slot in without
touching the rest of the flow.

### Steam's verification step is not optional

The parameters Steam returns must be posted back with
`openid.mode=check_authentication` and the reply checked for `is_valid:true`.
Accepting `openid.claimed_id` as it arrives is a trivial account takeover — the
parameters sit in the URL where anyone can edit them.

## Findings from the implementation

These cost time and are worth writing down:

**Cipher injection.** Doctrine builds types through a static registry, so the
cipher cannot be constructor-injected. Kernel and console events were the first
attempt; they do not fire for migrations or tests, leaving the type without a
cipher exactly where it was needed. A Doctrine middleware runs on every
connection and covers all three entry points.

**Two handler contracts.** The firewall expects Symfony's
`AuthenticationSuccessHandlerInterface`; the registration controllers expect
the bundle's own `SuccessHandler`. They are not interchangeable, and using one
where the other belongs fails at container build time.

**Silent non-persistence.** A credential repository that does not implement
`CanSaveCredentialRecord` will validate a ceremony and then store nothing, and
the bundle answers 501.

**Lazily loaded collections lie about counts.** The rule refusing to delete a
last passkey read zero from a freshly loaded collection and would have let an
account lock itself out. It counts in the database now, with a test.

**Logout redirected.** The default 302 leaves an SPA waiting for a navigation
that never happens; a `LogoutEvent` listener answers JSON instead.

## Interface

shadcn/ui provides the layout blocks; ReUI provides what shadcn lacks. ReUI's
catalogue is readable without a licence and its 77 UI components install
freely — among them `tree` for the directory browser and `number-field` for the
item steppers. Its 1638 prebuilt page blocks answer 401 without a key, so
layouts are built from shadcn blocks.

ReUI's `data-grid` expects the Base UI variants of `dropdown-menu`, `select`
and `checkbox` (`render` prop, `indeterminate`), not the Radix variants shadcn
installs by default. It is not used yet; when the player lists need it, that
choice has to be made deliberately.

## Verification

Backend: 33 tests covering encryption round-trips and the absence of plaintext
in the database, the setup wizard locking itself, login and enumeration
resistance, and passkey ownership and deletion rules.

Browser, end to end: the wizard rejecting a short password and creating the
first administrator; login rejecting wrong credentials and accepting right
ones; the session surviving a reload; the wizard locked afterwards; passkey
registration storing a key; passkey login working without email or password;
`lastUsedAt` recorded and the signature counter advancing.
