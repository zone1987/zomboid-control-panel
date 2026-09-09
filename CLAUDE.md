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

## 1a. Seven languages, and a new key belongs in all of them

`de`, `en`, `es`, `fr`, `it`, `pl`, `ru`. English and German are written
by hand; the other five are machine-translated and say so in the
switcher, which is the honest version of shipping them at all.

`locales.test.ts` enforces the rest, 40 cases over every language: same
keys, no empty value, same interpolations, and the plural forms each
language actually uses. Adding a key to `en.json` alone turns six of
them red — which is the point.

Three things that are not obvious and each cost something:

- **Polish and Russian need four plural forms** (`_one`, `_few`,
  `_many`, `_other`) where English has two. And their `_one` resolves
  for **21, 31, 101**, so it must carry `{{count}}` even though English
  spells the number out. A check comparing placeholders against English
  calls the correct translation a fault.
- **`SupportedLanguages` reads the directory**, so the backend needs no
  edit — but a *new* language does need its name in `LANGUAGE_NAMES` and
  a decision about `UNREVIEWED_LANGUAGES`.
- **A key path is not always a path.** `roles.permissions.chat.read` is
  three levels deep, because the permission name contains a dot. Walk
  the tree and key by the joined path; splitting on `.` finds nothing
  and reports every language as broken.

**Names that come from the game are not ours to translate.** Items and
vehicles are read from the installation's own
`media/lua/shared/Translate/<CODE>/` files at run time — `ItemName.json`
and the `IGUI_VehicleName*` entries in `IG_UI.json`. Do not type those
into a locale file; read them, and fall back to English where the game
has nothing.

## 1b. Commit messages follow Conventional Commits

`type(scope): summary`, with the type from `feat`, `fix`, `docs`, `style`,
`refactor`, `perf`, `test`, `build`, `ci` or `chore`, and a `!` before the
colon for a breaking change. The summary is lower case, imperative and
without a trailing full stop.

The body explains *why*, not what the diff already shows. Commits before
this rule was adopted are left as they are.

## 1c. Every change goes through a branch, a PR and a release

**Binding since 1.0.0 was tagged.** The panel is now something people
install from a release, so `main` is what they get and nothing lands on
it directly.

The flow, in order, for every change without exception:

1. **Branch off `main`** — `feat/…`, `fix/…`, `docs/…`, `chore/…`,
   matching the Conventional Commits type of the work.
2. **Commit and push the branch.**
3. **Open a pull request into `main`** (`gh pr create`). Its body says
   what changed and what was verified, the way a commit body does.
4. **Wait for CI to pass on the PR.** A red pipeline is not merged and
   not overridden.
5. **Merge into `main`.**
6. **Bump `app.version`** in `backend/config/services.yaml` when the
   change ships to users, and **tag** `vX.Y.Z` on `main`.
7. **The tag builds the release** — the image, the GitHub release, and
   the bridge as a download. Watch it finish; a release job that failed
   leaves users on the previous version with no warning.

### Three workflows, each doing its part once

The suites run **on the pull request and nowhere else**. Main and the
release trust that result rather than repeating it, which is safe only
because of the branch protection rule below — remove it and the pipeline
is testing a commit that is not the one being shipped.

| Workflow | Fires on | Does |
|---|---|---|
| `checks.yml` | pull request | backend suite, frontend lint/test/build, image **built and started but never pushed** |
| `main.yml` | push to main | image built, started, pushed as `main` and the short SHA |
| `release.yml` | tag `v*` | gate → frontend build → image pushed as the version **and `latest`** → GitHub release → Coolify told to pull |

**`strict: true` on main's protection is load-bearing.** "Require
branches to be up to date before merging" makes the commit that passed
`checks.yml` the commit that lands, so not re-running the suites on main
is a saving rather than a gap. The required checks are named exactly:
*Backend tests*, *Frontend build*, *Image builds and starts* — rename a
job and the protection silently stops requiring it.

**The release gate proves what it no longer runs.** Before anything is
built, `release.yml` checks that the tagged commit is an ancestor of
`origin/main` **and** that main's own run for that exact SHA concluded
`success`, waiting up to ten minutes if it is still going. A tag on a
feature branch, or on a main whose run failed, stops there.

Four things the pipeline enforces so they cannot be forgotten:

- **The tag must match `app.version`.** The release job refuses
  otherwise, because a release named 1.0.1 while the panel reports 1.0.0
  makes the in-panel update notice permanently wrong.
- **The image is proven to start before it is pushed**, twice: once with
  every secret supplied, once with only `APP_PUBLIC_URL`. Both live in
  `.github/scripts/start-image.sh`, which three workflows call — change
  the check once, not three times.
- **A release only comes from main**, and only from a green one.
- **`latest` moves only on a release**, never on a merge to main. The
  compose file defaults to `latest` with `pull_policy: always`, so
  moving it from main would put untagged code into a production
  container at its next restart. Main publishes `main` and the short
  SHA; a prerelease tag (one containing `-`) moves nothing.
- **The deployment is told, because nothing watches a registry tag.**
  Coolify's "Automatic Deployment" reacts to git commits, and a release
  changes no file it can see — so `release.yml` calls the Coolify deploy
  webhook itself, from `COOLIFY_WEBHOOK` and `COOLIFY_TOKEN`. It runs
  **after** the release is published and therefore after the image was
  pushed, which happens only once that image has started twice and
  answered. Absent secrets skip the job rather than failing it, so a
  fork is not broken by it.

Versioning is semantic: a fix that changes nothing for the operator is a
patch, a new capability is a minor, and anything that makes an existing
installation need attention on upgrade is a major.

**Bump `BRIDGE_VERSION` separately.** The bridge has its own version and
its own upgrade path — an operator has to upload it and restart the game
server — so it moves only when the Lua actually changed.

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

## 6b. Test what a user does, in a browser, always

Stated by the user after three separate features passed a console check
and then failed on the first click: **"Du musst bitte IMMER alles mit
dem playwright browser mcp testen. Über die Konsole reicht es
grundsätzlich nie aus. Wir müssen immer das testen was ein Benutzer
machen würde."** And the reason it is not merely thorough: **on Coolify
the operator has barely any console access at all.** A feature that
works only from `app:bridge:send` is not a feature.

- **`app:bridge:send` proves the bridge, never the panel.** Firing a
  handler by hand shows the game answers; it says nothing about whether
  a click reaches it. `setSkillLevel` answered `skill level set` from
  the CLI and returned **422** from the button in the same minute.
- **Click the actual control**, then read the network entry: the request
  body, the status, the response body. A toast saying "failed" is not a
  diagnosis; `{"errors":{"skill":"validation.invalid"}}` is.
- **A green test suite and a clean build prove neither.** Both were
  green while the button was broken.
- **Look for the controls a user cannot find, not just the ones that
  error.** Two sections were wrapped in `player.online &&` and were
  therefore *absent* rather than disabled, with nothing explaining why —
  the user asked "und wo?" and was right to. `browser_snapshot` shows
  what is really rendered; assuming from the source is how I claimed a
  chooser was "there, just collapsed" when it was not rendered at all.
- **Report only what the browser showed.** If the click was not made,
  say the click was not made.

## 6c. One value carrying two meanings is a defect

The reference panel found this exact shape **six separate times**, and
every fix was the same: make the ambiguous case its own explicit state,
and be wrong in the safe direction.

Their six, as a checklist of the shapes it takes:

| Collapsed | Should have been |
|---|---|
| backup absent vs. backup **failed** | benign vs. dangerous |
| `checkServerRunning()` false | stopped vs. **could not tell** |
| `isRemote` computed from reachability | remote vs. **temporarily unreachable** |
| `runTaskNow()` returning `undefined` | success vs. failure |
| unknown select value coerced to default | known vs. **unrecognised, preserved** |
| `if (!user) return next()` | absent vs. **unauthenticated** |

This is already practice here — `ModerationAction::hasFailed()` is
three-state, `PanelUpdateChecker` lets `upToDate` be `null` because "not
knowing is not the same as being current", and the ability rows show
*unknown* rather than claiming *off*. **Written down so it is a review
question, not a habit**: for every boolean or nullable return, ask what
the third state is and whether it is being hidden.

Two rules follow from it:

- **In doubt, say the cautious thing.** A timeout, an unparseable
  answer, a read-back that did not happen: all of those are "restart
  needed", never "applied".
- **Never coerce an unrecognised value.** Tell the operator what it is
  and that it stays untouched.
- **An empty collection is a state, not an absence.** `ItemTranslations`
  returned `[]` four times for four causes — no credentials, path not
  found, login refused, file not a table — and a fifth, benign one: the
  game ships no such language. The operator saw English item names with
  nothing said, and the likely fix (the FTP base path opens on the
  savegame directory, so the game's own `media/` is elsewhere) was
  invisible. Where a lookup can fail in more than one way, return a
  verdict that names the way.
- **Cache a failure for minutes, a fact for a week.** The same code
  cached that empty array for seven days, so fixing the FTP path would
  have changed nothing until the next week. A separate, short TTL for
  the failing case is part of the fix, not a refinement of it.
- **A cache key built from the success value hides the recovery.** The
  items ETag was `generatedAt-fileSize-language`, and `language` is
  absent exactly when the translation failed — so every failure shares
  one key with every other, and a repaired server still gets a 304. Key
  on the verdict's state.
- **Not everything in the cache pool is a cache.** A "clear the cache"
  button that drops `discord.chat.<server>` makes the chat mirror treat
  the next run as a first run and **silently skip** what arrived in
  between; `events.bridge.<server>` loses one up/down event, and
  `bridge.sequence.<server>` costs a resync. Those three are positions
  and counters. `ServerCacheCleaner` names the keys it clears and says
  on screen which it leaves alone — enumerate, never `clear()` the pool.
- **The same panel version against the same game server can differ.**
  Item names were German locally and English in production on 1.2.1
  with identical credentials, because each instance has its own
  database and its own cache. Before blaming the game server, ask what
  is per-instance — a cached failure outlives its cause.
- **The service worker also defeats request mocking.** After a build it
  re-registers and answers `/api/...` itself, so `page.route` never
  sees the request and an interception test looks like a broken
  component. Unregister it before mocking, not only before debugging.
- **Truncate for display, never before parsing.** The deploy probe cut
  the response to 500 characters — right for an error message shown as
  text, fatal for a success body: Coolify answers with ~8 kB of
  application, the cut JSON would not parse, and `applicationName` came
  back `null` beside an HTTP 200. No exception, no log, just an empty
  field. Parse the whole body and shorten only what is displayed.
- **Ask the API before repeating "it cannot be done".** The card's own
  text claimed a platform cannot be asked whether a deploy would work.
  Coolify's OpenAPI lists five token permissions and a
  `GET /applications/{uuid}` that changes nothing — so a real check was
  available all along. A sentence in the interface asserting an
  impossibility is a claim to verify, not a constraint to design around.
- **The browser's HTTP cache holds a stale index.html too.** After a
  rebuild, Chrome asked for an `index-<hash>.js` that no longer existed;
  the server answered the SPA shell, and the module failed on MIME type
  with a blank page. Unregistering the service worker was not enough —
  `Network.clearBrowserCache` was. So rule 10g0c has a second half.

## 6f. A field shows its value, always — a secret as dots

Stated by the user after the Steam key field came back blank from a
save: **"eingabefelder sollten grundsätzlich ihren wert anzeigen. Bei
passwortfeldern eben durch punkte."** Having to click into a field to
find out what it holds is not a disclosure control, it is a field that
lies about being empty.

- **A stored secret is loaded with the page, not on focus.** Focus is a
  gesture nobody makes to *read*; an operator checking a saved token
  clicked, saw nothing, and reasonably concluded the save had failed.
- **After a save the field keeps showing what was saved.** Clearing the
  draft must not drop the display back to a placeholder — that reads as
  "it did not take".
- **A password field shows dots, which is the whole point.** The masking
  is the browser's job and the reveal toggle already exists; blankness
  adds no protection over `type="password"` and costs the operator the
  one thing they came to check.
- **Empty and unknown stay distinct** (rule 6c). Nothing stored is an
  empty field with its placeholder; a value that could not be read is
  said out loud, never drawn as empty.

## 6d. Send only what changed

The reference panel's interface resent the entire settings object on
every save. That single habit caused **four separately-fixed bugs** in
its config editor alone: masked secrets overwritten with their own
mask, duplicate keys destroyed, hand-written whitespace stripped, and
capability gating firing on presence rather than on change.

The house pattern already does the right thing — `draft` holds only what
was touched (`server-detail-page.tsx:46`, `settings-page.tsx:45`) — so
this is a rule to *keep*, not to adopt:

- A PATCH body carries the changed fields, never a full snapshot.
- **Gate a permission check on the change, not the presence** of a
  sensitive key. Resending an unchanged `RCONPassword` must not require
  the credential permission.
- A blank secret field means "leave unchanged", so the field is **absent
  from the request** — `SettingsController` reads `''` as *clear*.

## 6e. Ask the game, then ask its own Lua, then measure

Four traps of the same family have cost five uploads
(`ClimateBool::getFinalValue`, `Color::getA`, the `ORDERED_STATS` array,
`SandboxOptions`' fields, `PerkInfo.perk`). The order that works:

1. **`javap -p` the class.** Field or method? Bounds? Overloads?
2. **`javap -p -c` the bytecode** when behaviour is the question, not
   existence. It settled that `reloadoptions` never touches sandbox
   values, that `sendPlayerStatsChange` returns immediately on a server,
   and that `RunLuaInternal` re-registers a reloaded file — three
   answers no amount of reading documentation would have given.
3. **Grep `media/lua/` for how the game does the same job.** A call site
   under `server/` is the strongest evidence a thing works server-side.
   `ISPerkLog.lua` gave the perk walk; `ISPlayerStatsUI.lua` gave the
   `modifyTraitXPBoost` step that `add` alone omits.
4. **Then measure on the running server**, and read back from a
   *different* field than the one written.

**Derive tables, never type them.** `CharacterDefinitions.php`,
`skills.ts` and the coming sandbox schema all come from the
installation's own files. Where a table is generated, **commit the
generator and a provenance-stamped fixture** — the reference stamps
`buildid` from `appmanifest_108600.acf`, which is what makes a drift
gate meaningful. `climate-api.json` is the pattern we already have.

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

## 10e. A control speaks the unit its display speaks

The wind control set a climate value from 0 to 100 while the strip
beside it showed km/h, so asking for 100 displayed 120. Neither number
was wrong; the pair was unusable.

- **Ask the game for its own bounds.** `getMaxWindspeedKph()` exists, so
  120 is read rather than assumed. `EventCatalogue::MAX_WIND_KPH` holds
  it, the field declares the range in km/h, and the controller divides
  by the ceiling on the way to the bridge.
- **Convert at the boundary, not in the interface.** The panel says km/h
  end to end; `EventController::climateValue()` is the single place the
  0..1 climate value appears.
- **Prove the round trip.** Asking for 95 and measuring 95 back from the
  bridge is the test; a slider that merely moves proves nothing.
- **Every number on screen carries its unit.** A range reading "0–120"
  beside one reading "0–100" tells nobody that the first is km/h and the
  second a percentage — which is the same confusion the wind control
  had, moved from the value to its label. Write **"0–120 km/h"**,
  **"0–100 %"**, **"−30–40 °C"**, and put the unit on the field's own
  value too. A bare range is a range in unknown units.
- **The unit belongs to the field, not to the page.** `EventField`
  carries it, so the catalogue states it once and every renderer shows
  it. `features/events/units.ts` prints it: `withUnit` and
  `formatRange`.
- **A number's sign may mean opposite things in two tables.** A
  *trait*'s `cost` is a rating (athletic +10 good, deaf −12 bad); a
  *profession*'s `cost` is a price, so it inverts (veteran −8 is the
  dearest, unemployed +8 refunds). Reading one through the other colours
  every badge backwards. Check both ends of a scale against a case you
  know before building a colour on it.
- **Once, at the label that explains it.** On the range, not also on the
  input beside it — "0–120 km/h" above a field reading "95 km/h" says it
  twice, which is noise. The input keeps it in its `aria-label`.
- **A negative range needs it on both ends.** `-30–40` cannot be read:
  the range dash and the minus are the same stroke. Write
  **"−30 °C – 40 °C"**, and keep the compact form when the minimum is
  positive.

## 10f. The bridge and the panel are two files uploaded apart

A command exists in `BridgeCommand` and a handler in the Lua, and the
mod is uploaded by hand — so a mismatch surfaces on a live server as
"unknown action", long after the change.

- **`BridgeCommandCoverageTest` asserts both directions**: every command
  has a handler, every handler has a command. Do not add one without the
  other.
- **Bump `BRIDGE_VERSION` for any handler change.** Otherwise an
  operator running the older mod is told they are up to date while the
  panel sends commands it cannot answer.
- **Fire every new handler at the running server before building on
  it.** Three uploads in a row shipped broken because the local checks
  cannot see what Lua does at run time: a public Java field indexed
  (returns null), a handler above its own table (`luac -p` passes),
  and a copy left on the old shape by a scripted edit. `app:bridge:send`
  exists for exactly this, and each of those three now has a guard.
- **A test tool must send the types the real caller sends.**
  `-a on=true` arrived as the string `"true"` while `BridgeCommand`
  checks `=== true`, so "switch the power on" switched it off and a
  working handler looked broken. A tool that cannot express the real
  request proves the wrong thing.
- **Say plainly when an upload and a restart are needed**, in the commit
  and to the user. Only they can do it.
- **`DocumentationTest` will fail** until `llms.txt` names the new
  version. That is the intended behaviour, not an obstacle.
- **Read back rather than trusting a setter — from a different field
  than the one you wrote.** `setPrecipitationIsSnow` writes
  `ClimateBool.finalValue` and `getPrecipitationIsSnow` reads that same
  field, so the read-back proved only that the write happened. The game
  recomputed `finalValue` on its next tick and the snow became rain,
  while the panel had reported success. The admin override
  (`setEnableAdmin` + `setAdminValue`) is what persists, and
  `getAdminValue()` is a read that means something.
- **Ask the class, not your memory.** `javap -p -c` against
  `/Volumes/ESD-USB/ProjectZomboid/projectzomboid.jar` settled in
  minutes what guessing had got wrong for an hour: which field a setter
  writes, that temperature is −80…80 rather than −30…40, that
  `setAdminValue` **clamps silently** rather than refusing, and that
  three of the thirteen climate floats do not run 0..1. A silent clamp
  applies something other than what was asked and reports success.
- **A public Java field is not reachable from Lua; only methods are.**
  `elecShutModifier` is `public` on `SandboxOptions`, and indexing it
  returned null — the live server answered "attempted index:
  getValueAsObject of non-table: null". `getElecShutModifier()` and
  `set(String, Object)` work. So the rule is: **`javap` the member, and
  if it is a field rather than a method, find the method.**
  `BridgeCommandCoverageTest`'s sibling
  `testNoPublicJavaFieldIsIndexed` now checks this against every public
  field of eleven game classes, not just the ones already known.
- **A field read costs nothing and reports success.** This family has
  now cost five uploads — `ClimateBool::getFinalValue`, `Color::getA`,
  the `ORDERED_STATS` array, `SandboxOptions`' fields, and
  `PerkInfo.perk`, which has **no getter at all**, so every player's
  skills arrived as `{}` with nothing thrown and nothing logged. The
  panel drew an empty list and called it a success. **An empty result is
  a symptom, not an absence of data**: check the read before concluding
  the server has nothing to say.
- **Ask the game's own Lua how it does the same job.**
  `media/lua/` is the reference implementation, and it settled in one
  grep what the class list could not: `ISPerkLog.lua` walks the perks by
  index through `PerkFactory.getPerk`, `forageSystem.lua` resolves a
  trait by `CharacterTrait.get(ResourceLocation.of(name))`, and
  `ISPlayerStatsUI.lua` shows that adding a trait needs
  `modifyTraitXPBoost` beside it or the trait is inert. Each of those is
  a step I would have missed. **A `server/` call site is also the
  strongest evidence a thing works server-side at all** — which is why
  traits are settable and professions are not.
- **A setter with no server-side transport is not a feature.**
  `setCharacterProfession` exists, but `sendPlayerStatsChange` opens with
  `getstatic GameClient.client; ifeq` and returns immediately on a
  server, `GameServer` only *receives* stat changes, and the one real
  server-side broadcast (`sendPlayerExtraInfo` → `ExtraInfoPacket`)
  carries roles and cheat flags but no professions or traits. Read the
  **bytecode of the sync call**, not just the setter. Where the effect
  cannot reach the player, either say so on screen (traits do) or do not
  build the control (professions).
- **A guard is only as wide as the class hierarchy it walks.**
  `BridgeClimateCallsTest` compared calls against one `javap` per class
  and passed for weeks; the moment a handler called `player:getX()` it
  failed, because `getX` is on `IsoMovingObject` — **four classes above
  `IsoPlayer`** (`IsoPlayer → IsoLivingCharacter → IsoGameCharacter →
  IsoMovingObject → IsoObject → GameEntity`). Follow `extends` to the
  top when generating the fixture. Doing so took the guard from 1386 to
  6430 assertions, which is the measure of how much it had been missing.
- **Two overloads of one method can differ in what they cost.**
  `LevelPerk(perk)` **spends one of the player's real unspent skill
  points per call**; `LevelPerk(perk, false)` does not. Filling a skill
  to 10 through the wrong one would silently rob the player of ten
  points. When `javap` shows two overloads, find out what the extra
  argument is *for* before picking one.
- **The reference bridge is evidence too.**
  `reference/zomboid-control-panel/pz-mod/PanelBridge/media/lua/server/PanelBridge.lua`
  is 9132 lines by somebody who read the class files by hand. What it
  *omits* is as informative as what it does: zero mentions of
  profession. It also named `sendPlayerExtraInfo` as "the one broadcast
  mechanism here actually confirmed to exist", which is worth knowing.
- **Nothing at module level touches an exposed game class.** A
  `local X = WeatherPeriod.STAGE_STORM` runs when the mod loads, and a
  class not yet reachable there takes the **whole bridge** down rather
  than one handler. Read it inside the handler, in a `pcall`, with the
  literal as a fallback.

## 10g. A status the endpoint cannot be wrong about

Two failures in one afternoon, both from a status that was read off the
convenient thing rather than the true one.

- **The file on disk is not the running mod.** The mod loads at server
  start, so an upload changes the file and nothing else.
  `BridgeInstaller::status()` compares disk against shipped, which read
  "up to date" while the game still answered with the older handler set
  — the one moment the light exists for. The bridge writes its own
  version into its output; **that** is the one answering commands.
  `BridgeVersionVerdict` decides between all three.
- **An endpoint with no test can lose a method silently.** A refactor
  deleted `ConnectionStatusEndpoint::game()` and all 529 tests stayed
  green while the browser got a 500. A controller returning a shape the
  interface polls needs a functional test that **asks for the response**
  — and the test is worth only what a revert proves: removing that
  method again fails 4 of its 6 cases.
- **Clear the cache after adding a class to a controller.** The dev
  environment kept returning 500 from a stale container until
  `php bin/console cache:clear`, which cost fifteen minutes of debugging
  correct code.

## 10f2. `apiFetch` stringifies the body; a caller must not

Three new calls sent `JSON.stringify(...)` as the body while `apiFetch`
does that itself, so Symfony received a JSON **string** holding JSON.
`toArray()` read nothing, every field came back null, and the 422 named
the first field it checked — pointing at the payload's *contents* while
the fault was its *shape*.

- **Pass the object**: `body: { skill, level }`, never
  `body: JSON.stringify({ skill, level })`.
- **A 422 naming a field can be a lie about the cause.** When every
  field looks absent, print the raw body before doubting the values.
  `$request->getContent()` next to `$payload` settles it in one request.
- `frontend/src/lib/api.test.ts` guards both directions: the client must
  keep stringifying, and no feature module may do it twice.

## 10g0. Narrow is a width to design for, not to squeeze into

A pass over fifteen pages at 390, 820 and 1512 px found nine faults, and
two of them cost the operator a control rather than looking untidy. The
user set the standard: **"Auch mobil muss die UX perfekt sein und es darf
nichts zu schmal sein"**, and then narrowed it further — **"mobil sollten
möglichst gar keine horizontalen scrollbars angezeigt werden müssen"**,
and where one is unavoidable, **"sollte die ansicht mobil so angepasst
werden das es besser bedienbar ist"**.

So a sideways scroller on a phone is a shape to replace, not a scroller
to tidy.

- **A table wider than its frame becomes cards, and the breakpoint is
  `lg`.** `sm` is wrong: at 820px with the sidebar open the content
  frame is **549px**, so both five-column tables still scrolled on a
  tablet. Lift the cell bodies into components used by both views, or
  they drift apart.
- **`overflow-y-auto` makes the x axis `auto` too.** A few stray pixels
  then draw a bar across the whole page. `overflow-x-hidden` beside it,
  and anything genuinely wide carries its own scroller.
- **`scrollbar-gutter: stable` reserves on the end edge only**, which
  puts every centred page half a scrollbar off centre — 229 left against
  244 right, and the user saw it. `both-edges` fixes that and costs
  **twice** the bar, 30 of 390 pixels, so it belongs from `lg` up; below
  that let the bar overlay (`scrollbar-width: none`), which is what a
  phone does natively. And put the rule on the element that scrolls: it
  sat on `html` while `main` was the scroller.
- **Measure the header and footer, not just `main`.** The worst fault was
  a 150px button label pushing the language and theme buttons to 414px in
  a 375px header — outside the viewport, with `overflow-x-hidden` hiding
  the evidence. A label that long is icon-only below `sm`.
- **32px is the floor for anything touchable.** Six controls were under
  it, including the sidebar trigger — the one button that opens the
  navigation on a phone.
- **One child on a `flex-nowrap` row must be allowed to give way.** Every
  child `shrink-0` ran the credential row 53px past the frame; the label
  is `min-w-0 truncate` now.
- **A probe that reports everything reports nothing.** Skip what lives
  inside a scroller of its own, skip `truncate`, and count a scroller
  only when `scrollWidth > clientWidth`. Otherwise every table is a false
  positive and the real fault is buried.

## 10g0b. A raw translation key can hide where only a screen reader looks

`settings.title` sat in an `sr-only` label and exists in neither locale.
Nothing on screen showed it; a screen reader would have read the key
aloud.

Check the whole surface rather than the page in front of you: every
`t('...')` literal in `frontend/src`, against both files. Of 20 hits, 19
resolved through i18next plural suffixes (`_one`, `_other`) and one was
real — so **the check has to know about the suffixes**, or it produces
nineteen false alarms and gets ignored.

## 10g0c. A service worker serves the build that no longer exists

Every route answered "Diese Seite konnte nicht geladen werden" after a
rebuild, with **no console error and every asset returning 200**. The
precache manifest still named the previous build's chunks.

After any `npm run build` during a browser session, unregister the worker
and clear `caches` before debugging the code. Fifteen minutes went into
correct code because the symptom looks like an application fault.

## 10g0d. An HTTP code is a fact, not an instruction

A deploy hook answered `403 You are not allowed to access the API`, and
working out which of two settings caused it took an hour of probing. An
operator must not repeat that.

- **Map the code to the setting to change**, and put that on screen.
  `DeployOutcome::adviceKey()` names nine cases; the platform's own words
  go underneath, small, because they are what to quote and rarely what to
  act on.
- **Two identical codes can mean different things, and the body says
  which.** Coolify names the missing permission when a token is short of
  one and says nothing when the address list refused — so a bare 403
  points at the allow-list and a 403 mentioning a permission points at
  the token.
- **Some failures are ours.** `405 This endpoint has changed to a POST
  request` is a bug in the caller; the advice says "update the panel",
  not "check your settings".
- **Probe to eliminate, and do not trust one negative.** The same token
  failed from the user's own machine, which I read as "not the address
  list" — their IP had simply changed since the list was written. The
  answer that actually settled it was that an *invented* token returned
  401 while theirs returned 403: recognised, but not allowed.

## 10g0e. Who makes the call decides what has to be open

The release pipeline called the deploy hook from a GitHub runner, and
runners have no fixed address: GitHub publishes **416 ranges** and changes
them without notice. Making that work meant opening the platform's
allow-list to `0.0.0.0`, which the platform itself warns against.

**The panel already runs on an address the operator trusts.** It asks
GitHub hourly whether a newer release exists, so the trigger belongs
there, and the allow-list can stay closed.

Before adding an outward call to CI, ask what it forces the user to open.

## 10g0f. One job, many panels: let the database decide

Several panels sharing a database all notice the same release in the same
minute, and all would deploy it.

- **A row named after the job is the lock.** `INSERT … ON CONFLICT DO
  NOTHING` on a primary key lets exactly one caller through, with no lock
  service and no window where two both read "nobody has it".
- **Claim before acting, not after.** A call that times out may still
  have done its work; asking twice is worse than waiting.
- **A failed claim is not permission.** If the database cannot record it,
  return false — "could not tell" must not read as "it is mine".

## 10g1. A grid `1fr` is not zero-minimum

Reported twice from the browser and both times it looked like a
different bug: "wenn man den verlauf öffnet wird die ganze seite
breiter".

- **`grid-cols-[20rem_1fr]` means `minmax(auto, 1fr)`**, and `auto` is
  the *content's own minimum width*. A table, a log or a long
  unbreakable string inside that column therefore pushes the whole page
  wider instead of scrolling within itself. Write
  **`minmax(0,1fr)`** for any column that holds something wide, and
  `min-w-0` on a flex child for the same reason.
- **shadcn's `TableCell` and `TableHead` carry `whitespace-nowrap`.**
  That is right for a timestamp and wrong for prose, so a free-text
  column needs `whitespace-normal break-words` plus a `max-w-*`. Only
  the free-text column — wrapping a date is worse than not.
- **The bars stay still by capping the shell, not by `position:
  fixed`.** `SidebarInset` gets `h-svh overflow-hidden` and `<main>`
  gets `overflow-y-auto`; fixed positioning would take the bars out of
  the flow and they would no longer know the sidebar's width. Prove it
  by measuring `getBoundingClientRect()` before and after a scroll —
  header top and footer bottom must not move, and
  `document.documentElement.scrollHeight > innerHeight` must be false.

## 10g2. Never copy server data into state with an effect

Five of these existed and two were hiding real bugs, so this is a rule
rather than a lint preference.

An effect that copies a query's result into state renders twice — once
with the old value, once with the new — and **overwrites whatever
somebody is typing** whenever the query refetches. The shape that works
holds the edit against the thing it belongs to and derives the rest:

```ts
const [edit, setEdit] = useState<{ from: X; value: V } | null>(null)
const shown = edit !== null && edit.from === current ? edit.value : current
```

- **A fallback selection is derived, not set.** `chosen ?? list[0] ?? null`
  during render. An effect doing it renders once with nothing selected.
- **Narrow the fallback to what the page actually shows.** The events
  page fell back to `matches[0]`, which filtered only by search term
  while the category was applied later — so the sounds page opened on a
  weather action.
- **An observer's callback updates from the previous value.** An effect
  depending on one thing keeps whatever else it closed over; the items
  page added to a stale count forever.
- **A fact about the browser is not state.** `useState(browserSupportsWebAuthn)`
  reads once at mount; an effect made the passkey button flicker in on
  the second render.

## 10g3. Let the control show the wait

A click that changes server state has three states worth drawing, and
one component can carry all of them.

- **Show the asked-for value at once**, hold it against the thing it
  belongs to (`{skill, level}`, not a bare number), and render
  `hovered ?? asked ?? server`.
- **Animate while it is unconfirmed.** `.pz-pending` is a 900 ms opacity
  breath on the already-filled marks, so the wait *is* the indicator —
  no second spinner, and the preview doubles as the progress.
- **Clear it after the refetch, not before.** Clearing on success alone
  drops the display to the stale value for one render before it rises
  again.
- **On failure, clear immediately.** Leaving an optimistic value on
  screen after a refusal is the worst of the three states.
- **Prove it mid-flight.** Click, sample after ~30 ms, sample again
  after the round trip: filled and pulsing, then filled and settled. A
  screenshot after the fact cannot show the middle state at all.

## 10h. Doctrine, migrations and the test database

- **`messenger_messages` is not ours.** Every `migrations:diff` proposed
  dropping its three indexes — the ones that keep the queue fast — until
  `doctrine.yaml` grew `schema_filter: ~^(?!messenger_messages)~`. Read
  a generated migration before running it; the generator describes the
  whole schema, not the change that was asked for.
- **Migrate the test database as well.** `--env=test` is a separate
  database, and forgetting it turns one new column into twenty-one
  errors that look like a code fault.
- **A new column on an existing table is nullable, and null means
  unknown.** `failed` could not be `false` for rows written before
  anybody recorded it: that would be inventing history, and a list
  claiming every past action succeeded is worse than one admitting it
  does not know.

## 10h2. A 200 in the test environment can be a 500 in dev

The Discord page failed to load with `Undefined array key`. A functional
test was written for exactly that case — and **it passed with the bug
restored**.

The reason is worth knowing before it costs an afternoon: in the test
environment an undefined array key is a PHP *warning*, the request
completes, and the response is 200. In dev the same warning is turned
into an `ErrorException` and the response is 500. A test asserting only
`assertResponseIsSuccessful()` is therefore blind to a whole family of
faults that reach a browser as a blank page.

`failOnWarning="true"` in `phpunit.dist.xml` does **not** cover this: it
covers PHPUnit's own warnings, and there is no `failOnPhpWarning` in the
schema — only `failOnPhpunitWarning`.

So a functional test of an endpoint the interface polls asserts two
things:

```php
set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
}, E_WARNING | E_NOTICE);

try {
    $this->fetch();
} finally {
    restore_error_handler();
}

self::assertSame([], $warnings, 'the endpoint raised: '.implode('; ', $warnings));
self::assertResponseIsSuccessful();
```

And the usual proof applies: **revert the fix and watch the test fail.**
A guard that has not been seen to fail is a guard nobody has checked.

Related, and the reason this one existed at all: **`?->` handles a null
value, not a missing key.** `$rows[$key]?->method()` warns when `$key` is
absent, which on a freshly configured server is every key. Write
`($rows[$key] ?? null)?->method()`.

## 10i. A `final` collaborator needs a narrow interface, not unsealing

Three times in one session a unit test could not be written because the
collaborator was `final`: `ServerInfoReader` for the bridge reload,
`DiscordNotificationRepository` for the notifier,
`DiscordCommandRightRepository` for the authorisation. PHPUnit answers
`ClassIsFinalException`, and the tempting fix is to drop the `final`.

**Do not.** Introduce an interface holding only what the caller
actually needs, and have the class implement it:

- `RunningBridgeReading` is two fields — version and session id — so a
  reload verdict cannot be swayed by weather or game time.
- `NotificationSettings` is one lookup, because announcing depends on
  whether a row exists and on nothing else about persistence.
- `CommandRights` likewise.

The narrower dependency is the point and the testability follows. A
service that takes a whole repository can reach anything in it later;
one that takes a single-method interface cannot, and the interface
documents exactly what the collaboration is.

This is already the shape `RconClientInterface` and
`FileBrowserInterface` have. The `when@test` block in `services.yaml`
marking them `public: true` is the other half of the same pattern, for
the cases where a functional test has to swap the real one out.

## 10j. Who calls whom decides what works locally

Discord answered "the application is not responding" to every slash
command while every setting was correct. The reason is structural, not
a bug: **`APP_PUBLIC_URL` was `https://zomboidcontrol.ddev.site`, and
Discord calls the panel from its own servers.** A hostname that resolves
only on the developer's machine is a dead end no configuration fixes,
and the symptom in Discord points nowhere useful.

So for every integration, ask which direction the call goes:

| Direction | Works locally? |
|---|---|
| **Panel → service** (Discord messages, Steam, RCON, FTP) | yes |
| **Service → panel** (Discord interactions, a webhook, an OAuth callback) | **only from a public address** |

Three rules follow:

- **Report the two capabilities separately.** Saying "Discord is not
  working" when notifications and outbound chat work perfectly is the
  "one value, two meanings" fault again. `discordReachable` and
  `commandsReachable` are their own fields, and the interface says
  plainly that commands wait for a deployment while everything else
  already works.
- **Detect it rather than documenting it.** A host with no dot, or
  ending `.localhost .local .test .internal .ddev.site .example`, or a
  private/reserved IP, cannot be reached from outside.
  `DiscordReachabilityTest` asserts the verdict against the environment
  the suite runs in — so the fault is caught here rather than by a
  channel full of red error messages.
- **Build the URL from `APP_PUBLIC_URL`, never from the request.**
  Behind a proxy the request host is the container's, which the outside
  world could never reach. `SettingsController::googleRedirectUri()`
  already did this; the Discord one follows it.

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
- **A read-only survey is the best use of one.** Enumerating what
  `ClimateManager` exposes meant `javap` over dozens of classes and a
  grep across the game's whole Lua tree — a large amount of output for
  three paragraphs of conclusion. Give it an explicit "change no file,
  report only what the tool shows, say 'not present' rather than
  guessing", and demand a file:line for every claim.

## 10k. Colour, depth and movement: the rules that came out of one evening

The user asked for the panel to stop looking *"grau und trist"*, and
the pass that followed produced more general lessons than visual ones.

- **Two accents at a different hue read as a mistake, not as variety.**
  `--primary` sat at hue 143 while every success state used emerald at
  ~162. Nineteen degrees apart, side by side, and the user spotted it
  immediately. Unified onto 162, keeping lightness and chroma so the
  contrast barely moved (dark 8.84 → 8.93:1, light 5.23 → 5.05:1).
  `--chart-1` was left alone: a chart series is *meant* to differ.
- **The obvious shade usually fails AA.** `emerald-600` on white is
  **3.77:1**. The pattern that works is a darker text over a faint tint
  of the same hue: emerald-700 on emerald-50 is 5.21:1, and
  emerald-300 on emerald-900/40 is 6.38:1. Computed for amber, red and
  violet too, so warning/danger/info follow the same recipe.
- **A translucent surface can *raise* contrast, but only measured.**
  Cards at 78% over a darker page field took the muted description from
  6.74 to **6.95:1**. That was luck in the right direction; the rule is
  that the effective ground is a blend and has to be recomputed, never
  assumed from the token.
- **Half-coloured is worse than grey.** A green tick inside a grey
  pill, an amber triangle inside a neutral alert: nine sites asserted a
  colour and then withheld it from the container. `Alert` had only
  `default` and `destructive`, so every "good" or "warning" box was
  faking it. Adding the variants was the single highest-leverage
  change.
- **Colour what reports a state; leave counts and names alone.** "5092
  Items" is a count. "Hinterlegt", "deaktiviert", "stale" are states —
  and a stale bridge that looked identical to "no chat log yet" is the
  case that makes it worth doing.
- **Never colour a deliberate third state.** `ability-rows.tsx` keeps
  *unknown* grey on purpose (rule 6c). Green or red there would be
  exactly the collapse that file exists to prevent.
- **`prefers-reduced-motion` is not a reason to build nothing.** The
  user put it plainly: *"das ist ja auch absolut korrekt. heißt aber
  nicht das wir den usern die bewegung aktiv haben keine bewegung
  bieten können."* One global block already silences every transition
  and animation, so movement can be used freely — proven by measuring
  the card's transition duration collapse to `1e-05s` under
  `reducedMotion: 'reduce'`.
- **A static gradient carries no meaning, which is why it is safe.** It
  cannot be mistaken for a state colour, and it needs no motion
  exemption. Three off-centre pools plus a 1px hatch beat one radial
  gradient, which reads as a bullseye and bands on a wide screen.
- **`backdrop-filter` does nothing over an opaque surface.** Cards were
  `oklch(1 0 0)` with no alpha, so the blur the user asked for would
  have been pure cost. Give the surface an alpha first, then blur, then
  recompute the text contrast.
- **Check every selector against the source before committing.**
  `.pz-row-link` and `[data-slot='chevron']` matched nothing —
  rules promising an effect nobody could see. `.lucide-chevron-right`
  is what those icons actually carry.
- **A visual effect has to be measured with the state applied.** An
  outline that only paints on `:focus-visible` reports its width at
  rest too; reading the computed value of an unfocused element proves
  nothing. Press Tab, then read.
- **A layer beats specificity, so neither one alone fixes focus.** The
  focus halo lived in `@layer base` and every component's
  `focus-visible:ring-*` utility overrode it -- utilities win over base
  whatever the selector. Writing Tailwind's own `--tw-ring-shadow`
  failed too, because the same utility reassigns it. What worked was
  `!important` on the shadow plus supplying `--tw-ring-color`: several
  components declare a ring with no colour, which resolves to
  *transparent* and paints nothing. Measured with Tab pressed on nine
  controls: four were invisible, now none are.
- **Lightning CSS drops a standard property that follows its own
  prefix.** `backdrop-filter` written after `-webkit-backdrop-filter`
  vanished from the build, so the blur was `none` in the browser while
  the source read correctly. Prefix first, standard second -- and read
  the built file, not the source, when a property does not arrive.
- **An opaque utility shows through wherever a gradient runs out.**
  `hover:bg-sidebar-accent` painted `oklch(0.274 …)` with no alpha
  behind the accent tint, which the user saw as a grey block on the
  right where the gradient faded. The shorthand `background:` did not
  reset it; `background-color: transparent !important` did.
- **One glyph, one file, every colour `currentColor`.** The mark was a
  PNG at three sizes plus AVIF and WebP, and could not follow the
  accent. Drawn as an inline SVG at opacities of `currentColor` it takes
  the colour of whatever contains it -- and the favicon, which inherits
  nothing, is the one place the hue is written out.
- **A `size-*` class on an icon inside a shadcn component is a
  suggestion.** `SidebarMenuButton` carries `[&>svg]:size-4`, so a mark
  asking for `size-8` rendered at 16px. Measure
  `getBoundingClientRect()` rather than trusting the class, and use
  `size-9!` where the parent asserts a size.
- **Trust the user's eye on aesthetics and the arithmetic on
  legibility.** They were right about the two greens, the mismatched
  help link, the loud scrollbar, the hard rules cutting the gradient,
  and the top-bar blur that made the page darker at the top than the
  bottom. Every one of those was a real fault. The numbers are for
  contrast, not for taste.
