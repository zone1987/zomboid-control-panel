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

## 0b. Context runs out; write CONTEXT.md as you go

**What is not possible, stated plainly so this rule is not built on a
wish:** the assistant cannot read its own context usage — there is no
percentage available to it and no tool that reports one — and it cannot
run `/compact`, which is a command of the interface rather than a tool.
So "start wrapping up at 90%" cannot be self-triggered. Any rule that
depends on it would silently never fire.

What works instead, and is therefore the rule:

- **Write the CONTEXT.md entry when a piece of work is finished, not at
  the end of the session.** One commit's worth of work, one entry. The
  state is then safe whenever compression happens, which is the whole
  point — and auto-compact is on in this project, so it happens without
  warning.
- **Every entry says what remains, to the smallest detail**: the exact
  file, the exact line, the value that was tried, the value to try next.
  "Verify the heading" is useless after a compact; "`CATALOGUE_HEADING =
  285` in `vehicle-preview.tsx`, alternatives 267/277/297, the arithmetic
  is `(wanted − 243.4) mod 360`" survives it.
- **Keep the TODO list at the end of the file**, and supersede the old
  one rather than editing it — what was planned stays readable beside
  what happened.
- **Ask for a compact when a long session reaches a natural break.** The
  assistant cannot run it, but it can say the state is written and now is
  a good moment. Do that rather than pressing on into a compression
  nobody prepared for.
- **After a compact, read CONTEXT.md before anything else.** Section 0
  already says this; it is the other half of the same rule.

## 1. All code is English

Identifiers, file names, comments, commit messages, test names and
documentation are English without exception. German is the language of
conversation in the terminal, not of the codebase.

Translation files (`frontend/src/i18n/locales/*.json`) hold the respective
language, and are the only place German appears in the repository.

## 1b. Commit messages follow Conventional Commits

`type(scope): summary`, with the type from `feat`, `fix`, `docs`, `style`,
`refactor`, `perf`, `test`, `build`, `ci` or `chore`, and a `!` before the
colon for a breaking change. The summary is lower case, imperative and
without a trailing full stop.

The body explains *why*, not what the diff already shows. Commits before
this rule was adopted are left as they are.

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

## 7. The product standard: it must be intuitive

Stated repeatedly by the user and binding on every decision. Not "has the
feature" — **intuitive**. A reference panel exists at
`https://zomboid.andreas-gerhardt.com` and is a source of shapes to learn
from, never a checklist to match: copy, improve or reject each of its
patterns on this test alone.

What that means concretely:

- **Show the state, not just the control.** A weather page that cannot say
  whether it is raining is a guess machine. "Vehicles (0 loaded)" tells you
  whether "nothing" means hidden or absent; "Vehicles" does not.
- **Pick things by sight where they have a look.** 241 vehicles have
  liveries and real names; body tiles and a livery grid beat a dropdown of
  `Base.*` strings.
- **No modal for something you compare.** Player details belong in a column
  you can switch within, not a box you close and reopen.
- **One page, one job** — and no page with 26 of them.
- **Never offer what cannot work.** A control needing the panel to sit
  beside the game, or a button that stops a server nothing can restart, is
  worse than its absence.
- **Say why something is unavailable.** Disabled with a reason beats
  vanished.
- **Type nothing you could click.** Variable chips over hand-typed braces, a
  day arc over an hour field, presets over "fill in intensity".
- **Show what will happen before it happens.** A live preview, a
  confirmation naming the destructive action, a verdict from the server
  rather than "the call did not throw".
- **A card gets the width its content needs, not the width available.** Full
  width is for a table, a map or a log — not a two-line text box.

## 8. Local versus remote: what this deployment can do

The reference panel can be installed **beside the game server**. **This one
runs in Docker or on Coolify, remote**, reaching the server only through
**FTP/SFTP** and **RCON**, plus the Lua bridge it uploads itself. Several
reference features are therefore impossible or pointless here, and the
difference must be stated rather than silently copied.

| Reference feature | Here |
|---|---|
| Host CPU / RAM / disk metrics | **Impossible** — would measure our own container |
| Start the server | **Impossible** — RCON needs it running; `quit` and `save` work, so shutdown is one-way |
| Panel port, HTTPS, self-restart | **Not applicable** — Docker or Coolify owns those |
| Steam Workshop / mod management | **Yes, and valuable** — `-mods` and `WorkshopItems` live in the INI, which we read and write |
| Server config editor, scheduler, backups | **Yes** — plain files over FTP, `servermsg` over RCON |

Rule: **copy the shape, not the feature list.** Where a feature depends on
locality, find the remote equivalent or leave it out and say why.

## 9. Performance and accessibility are requirements, not polish

- **Lazy-load and cache wherever it pays.** Measured examples: three.js was
  static in the map bundle (960 kB → 368 kB by importing it where used);
  both locales sat in the entry chunk (only the active one loads now).
- **Lighthouse 100 on all four scores** is the target the user set.
- **Images are optimised, never shipped as uploaded.** Trim the border,
  scale to what is displayed, re-encode at quality 80, offer AVIF and WebP
  and let the browser choose. Measured: the logos went 3.6 MB → 412 kB.
  WebP beats AVIF on flat graphics; AVIF wins on photographs — keep both.
- **BFSG compliance in full, with one exception: the map.** Its panning,
  zooming and label reveals *are* the information, and the accessibility
  directive provides for exactly that. `prefers-reduced-motion` is honoured
  globally and `.pz-map` is excluded from it deliberately.
- **Contrast is computed, not eyeballed.** The first green came out at
  exactly 4.50:1 against white, which rounding decides; it was darkened to
  5.00:1.
- **No colour-only meaning.** Roughly one man in twelve cannot separate red
  from green, so state carries a shape as well as a hue.

## 10. Data protection: nothing the panel needs is removed

Compliance here means processing what the purpose requires and being able to
account for it — **not** dropping data and breaking the tool.

Stays, with its reason:

| Data | Why |
|---|---|
| **SteamID** | A ban is worthless without it: names change, the id does not |
| **Positions** | The map *is* the feature |
| **Moderation log** | Answering "who banned this player, and why" is the point — and protects the banned player too |
| **Health, skills, traits** | What the player dossier exists to show |

What is narrower: a retention horizon for players nobody has seen in
months, **set by the operator and off by default**, never touching the ban
list. Plus a per-player export and erase, so a request can be answered
without opening the database by hand.

Also: **no analytics, no CDN fonts, no trackers.** The CSP enforces it with
`default-src 'self'` and two named exceptions.

## 10b. Using the game's own art

The Indie Stone's terms permit Project Zomboid's art in a non-commercial
fan project **on condition of a visible notice**, which is why
`/app/credits` exists and is linked from the sidebar footer. That page is
not decoration: it is the condition the permission rests on. Its English
wording is the text the terms specify — do not translate it.

- **Extract from the installation, never from the wiki.** A wiki image may
  carry an editor's own copyright on top of The Indie Stone's.
- **Assets stay out of git.** `backend/var/` is ignored (`backend/.gitignore:8`),
  and the 591 model files, the item icons and the ground tiles live there.
  The repository carries the code that reads them, never the art.
- **Mod art belongs to its author** and needs their permission separately.
  The terms are explicit about that, and the credits page says so.
- **Commercial use would need asking** (`info@theindiestone.com`). The
  permission as it stands covers a free panel.

## 10c. Geometry is derived, not tuned

Three separate visual bugs this session were fixed by arithmetic after
being made worse by adjustment. Compute the value, then look.

- **The ground tiles.** A tile pictures one world square at this very
  angle: the map's 2:1 projection is `sin(30°)`, the elevation the camera
  already uses. So a flat square projects back to exactly the diamond it
  was drawn as. Reasoning from `cos` instead led to "impossible".
- **The 45° offset.** A plane maps its texture onto its *square* while the
  picture is a *diamond* — 45° out of step, which showed as a chequerboard
  of holes. Turn the plane 45° and grow it by `√2`.
- **Which side is behind.** With yaw 225 the camera sits at negative x and
  z, so the far side is the positive corner. Guessing put the bushes in
  front of the vehicle.

## 10d. Vite in ddev misses host edits

Twice today the dev server kept serving a stale module while the file on
disk was correct — the vehicles tile without its star, then a new route
answering 404. Inotify does not always cross the mount.

Check what is actually served before debugging the code:

```
curl -sk https://zomboidcontrol.ddev.site:5173/app/src/<path> | grep <symbol>
```

If it is stale, restart with `ddev dev` — and note that `pkill -f vite`
does **not** reliably bring it back.

## 11. Delegating to subagents

Permitted and encouraged, with rules learnt the hard way:

- **Disjoint file sets only.** Give each agent an explicit allow-list and an
  explicit forbidden list. Name the files another agent is holding.
- **Never let an agent touch the bridge.** A bridge change needs the file
  uploaded and the game server restarted, which only the user can do — so
  **any bridge change must be reported in the main task**, not buried in an
  agent's report.
- **Two at a time, not three.**
- **Do not run the full test suite while agents are writing.** A 468-test
  run reading half-written files produces phantom failures; verify your own
  work with `--filter` until they finish.
- **Check their work yourself.** Read the diff, re-run the tests. Three
  agents delivered correctly today; one still put a task in the wrong
  schedule file because the brief named the wrong one.
