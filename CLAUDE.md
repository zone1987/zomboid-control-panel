# ZomboidControl — project conventions

## 0. Read CONTEXT.md first — always

Before starting or continuing any task, read `CONTEXT.md` in this directory.
It is the complete record of where the project stands: what is built, what is
verified, what is known to be broken, and what the next concrete step is.
After a `/compact` or in a new session it is the only thing that carries the
state forward.

After finishing any feature or task, append a log entry to `CONTEXT.md`:
what changed, in which files (exact paths), how, when, whether it is verified,
and what remains open. Never shorten it to save space — mark things done
rather than deleting them. Rewrite it completely at 90%, 95% and 98% context
usage, so nothing is lost to compression.

## 1. All code is English

Identifiers, file names, comments, commit messages, test names and
documentation are English without exception. German is the language of
conversation in the terminal, not of the codebase.

Translation files (`frontend/src/i18n/locales/*.json`) hold the respective
language, and are the only place German appears in the repository.

## 2. No prose comments

Multi-line explanatory blocks are not wanted. Reasoning belongs in
`CONTEXT.md` or in a test name that states the rule
(`testRefusesASecondAccountThroughTheWizard`).

Allowed: short single lines marking a non-obvious constraint, brief doc
comments on public API, `TODO`/`FIXME` with a concrete reference, and
tool-required directives.

## 3. Documents

- `docs/superpowers/briefs/` — what is being built and why, per sub-project
- `docs/superpowers/specs/` — the design, with evidence for each decision
- `docs/superpowers/plans/` — the steps, with actual progress

## 4. Working in this project

Everything runs inside ddev; PHP, Composer and the correct Node binaries do
not exist on the host.

| Task | Command |
|---|---|
| Start | `ddev start` |
| Frontend with hot reload | `ddev dev` |
| First-time preparation | `ddev setup` |
| Backend tests | `ddev exec -d /var/www/html/backend "php bin/phpunit"` |
| Frontend build | `ddev exec -d /var/www/html/frontend "npm run build"` |
| Clear cache | `ddev exec -d /var/www/html/backend "php bin/console cache:clear"` |

`node_modules` are installed for Linux inside the container. Running npm on
the host fails with missing native bindings, and vice versa.

## 5. Security rules that are not negotiable

- The encryption key lives in `.env.local` or an environment variable, never
  in the repository and never in the database.
- FTP, RCON and TOTP secrets are **encrypted** — they must be readable again.
  Account passwords, backup codes and invitation tokens are **hashed**. Do
  not mix the two.
- Login failures must not reveal whether an address is registered.
- Steam OpenID requires the `check_authentication` round trip. Skipping it is
  an account takeover.
- Session cookies use `SameSite=Lax`. `Strict` breaks the Steam return.

## 6. Verification

A change is done when it has been demonstrated, not when it looks right.
Backend work gets a test; interface work gets checked in a real browser.
State plainly what was verified and what was not.
