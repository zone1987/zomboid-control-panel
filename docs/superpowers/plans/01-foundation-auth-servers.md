# Plan 01 — Foundation, authentication, server configuration

**Brief:** [../briefs/01-foundation-auth-servers.md](../briefs/01-foundation-auth-servers.md)
**Spec:** [../specs/2026-09-04-foundation-auth-servers.md](../specs/2026-09-04-foundation-auth-servers.md)

## Step 1 — Foundation ✅ done

Symfony 7.4.18 on PHP 8.4.24, PostgreSQL 17.11, Apache in ddev; React 19 with
Vite 8, TypeScript 6, Tailwind v4; shadcn plus the free ReUI components.

Deviations from the original design:
- ddev switched from `nginx-fpm` to `apache-fpm`, so local and production run
  the same web server.
- ddev switched from MariaDB to PostgreSQL.
- The `symfony/apache-pack` recipe was skipped by Flex, so `public/.htaccess`
  is written by hand — which is preferable anyway, since it also carries the
  SPA fallback.

`ddev dev` starts Vite with hot reload; `ddev setup` prepares everything.

## Step 2 — Container and pipeline ✅ done

Multi-stage `Dockerfile`, `docker-compose.yml` with required-variable checks
that fail with a readable message, GitHub workflow publishing to ghcr.io.
Apache runs two FPM pools; the entrypoint refuses to start without
`APP_SECRET` and a 64-character `CREDENTIALS_ENCRYPTION_KEY`.

**Not yet verified:** the production image has not been built and run. The
compose file is syntactically valid; that is not the same thing.

## Step 3 — Data model ✅ done

Seven tables, migrated. The encryption type reaches Doctrine through a
middleware rather than an event listener — see the spec for why the first
attempt failed.

## Step 4 — Authentication core ✅ done

Setup wizard, `json_login`, session handling, route guards, JSON logout.

## Step 5 — Passkeys ✅ done

Registration, login, and device management, verified with a virtual
authenticator. Four findings from this step are recorded in the spec.

## Step 6 — Remaining sign-in methods ✅ done

**TOTP ✅ done.** Setup, login, recovery codes, disabling. Two findings:
`WebauthnToken` must appear in scheb's `security_tokens` or a passkey login
skips the second factor; and the TOTP provider service does not exist until a
`totp:` section is configured.

**Google ✅** — tested by the user against real credentials. The OAuth client
bundle cannot see credentials entered at runtime, so a factory builds the
provider at call time.

**Steam ✅** — tested by the user; the SteamID64 is stored. A forged callback
with an edited claimed_id is rejected by the `check_authentication` round trip.

**Invitations and password reset ✅** — hashed tokens, enumeration-resistant
reset, screens for both.

## Step 7 — Server configuration ✅ done

Forms, connection tests, directory browser and bridge upload, all verified
against the user's own Zomboid server.

**The RCON risk is resolved.** `xpaw/php-source-query-class` talks to a real
Zomboid server: `players` returned "Players connected (1): -admin". Seven
integration tests against a local Source RCON server keep it checked.

One thing the spike did not catch, found only against the real server: the
library's timeout bounds individual reads, not the exchange, so a port that
accepts a connection and never answers held an FPM worker indefinitely. Three
layers of timeout now bound it.

## Open items carried into sub-project 02

- Production image never built or run — the largest untested area
- `data-grid` needs Base UI variants; the player list forces the decision
- Bundle chunk exceeds 500 kB; code splitting not yet applied
