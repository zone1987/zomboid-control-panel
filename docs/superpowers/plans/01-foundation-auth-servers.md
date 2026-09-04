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

## Step 6 — Remaining sign-in methods 🟡 partly done

**TOTP ✅ done.** Setup, login, recovery codes, disabling. Two findings:
`WebauthnToken` must appear in scheb's `security_tokens` or a passkey login
skips the second factor; and the TOTP provider service does not exist until a
`totp:` section is configured.

Still open:

- Google through `knpuniversity/oauth2-client-bundle`.
- Steam through `xpaw/steam-openid` and a custom authenticator, with the
  `check_authentication` round trip that must not be skipped.
- Invitations by mail, and password reset.

The login page already shows all four buttons; Google and Steam currently
point at routes that do not exist.

## Step 7 — Server configuration ⬜ open

Entities exist; forms, connection test, directory browser and bridge upload do
not.

**Do this first:** verify `xpaw/php-source-query-class` against a real Zomboid
server. It is established that Zomboid speaks Source RCON and that the library
implements Source RCON; that they work together is not established. Everything
else in this step can proceed regardless, but nothing should be built on top of
the RCON client until it has answered `players` once.

## Open items

- Production image never built or run
- RCON client unproven against a real server
- `data-grid` needs Base UI variants; deferred until player lists need it
- Bundle chunk exceeds 500 kB; code splitting not yet applied
