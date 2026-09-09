# Roadmap

What is planned for ZomboidControl, and what has deliberately been left
out. **Features only** — a bug being fixed is not a roadmap entry, it is
a patch release.

This file is kept up to date as the panel grows: an idea that comes up
is written down here, and an entry that ships moves to *Shipped* with
the version it went out in.

> 🇬🇧 [README](README.md) · 🇩🇪 [README_DE.md](README_DE.md)

**Nothing here has a date.** This is a free project built in the
evenings, and promising a month would be inventing one. The order
within each section is roughly the order things are likely to be
picked up.

---

## Next

The things most likely to be built next, because they are asked for
most or because the groundwork already exists.

### Automated backups

Today the panel can back up the server INI before it writes to it, and
Coolify can back up the panel's own database. What is missing is the
one an operator actually loses sleep over: **the savegame**.

- A schedule you set — daily, weekly, or before every restart.
- Kept over FTP/SFTP, with a retention you choose, so old ones fall
  away instead of filling the disk.
- A visible list of what exists, how big it is and when it was taken.
- **Restore from the panel**, with a confirmation that names exactly
  what is about to be overwritten.
- Optionally to S3-compatible storage, so a backup does not live on the
  same machine as the thing it protects.

The obstacle worth naming: a savegame is large and the panel reaches it
over FTP, so this needs to run in the background and report progress,
not block a request.

### Mod collections

A Steam Workshop collection is one ID that resolves to many mods. The
`children` mechanic that the dependency tree already uses is the same
one — so this is mostly interface work.

- Paste a collection link, see what is in it, choose what to take.
- See afterwards which of your installed mods came from that collection
  and whether the collection has changed since.

### Mod sets — save and restore

Named, restorable snapshots of `Mods=`, `WorkshopItems=` and `Map=`.

The case for it is the sentence every server owner has said: *"since
yesterday the server does not start any more."* A mod set is the
one-click way back, and it can be carried to a second server.

### Restart notice and a countdown

The panel can already stop a server and tell everyone something in
chat. What it cannot do is run the sequence people actually want:

- Announce a restart at 10, 5 and 1 minute, in the game and in Discord.
- Save the world, then stop.
- Say plainly that it **cannot start the server again** — that stays
  outside what a remote panel can do, and the notice should not pretend
  otherwise.

### A scheduler for the things above

One place where recurring jobs live: backups, restarts, announcements,
mod update checks. The panel already runs a scheduler internally; this
is about giving it a face and letting you add your own entries.

---

## Later

Wanted, thought through, not started.

### Players and characters

- **Multiple professions.** The game lets you grant several; the panel
  shows one, because `SurvivorDesc.characterProfession` is a single
  field. What you most likely see granted are the profession *traits*.
  Offering those separately needs a decision first, since setting one
  without its job leaves the character sheet inconsistent.
- **A player timeline** — deaths, bans, notes and sessions on one
  strip, so a dossier answers "what happened to this account" without
  reading three tabs.
- **Vehicle damage, rust and broken windows** on the map and in the
  vehicle view. Researched, nothing implemented.
- **Online-time statistics** per player and per server.

### The world

- **Save-and-restore for weather and climate** — a named preset, so
  "the storm we ran at Halloween" is one click rather than thirteen
  sliders.
- **Scheduled events** — a horde every Friday, a storm on the hour.
  Depends on the scheduler above.

### Mods

- **Impact analysis before an update.** A mod update on a running world
  can break saves. Showing what changed in the changelog, and which of
  your other mods depend on it, before you accept it.
- **A mod's own configuration files**, where it ships them, edited in
  the panel the way the server INI is.
- **Show mods on the map** — which map mods are active and where they
  sit in the world, since overlapping map mods are a common and silent
  fault.

### Operations

- **Show the full game build in the panel.** The bridge already reports
  it in full (`42.20.4`, not just `42`); nothing displays it yet.
- **More than one Discord server** per panel.
- **Webhooks of our own** — let the panel notify something other than
  Discord, so people can wire it into what they already run.
- **A read-only public status page**, linkable, showing whether the
  server is up and who is on it, without an account.
- **Import from another panel**, so moving here is not retyping every
  credential.

### The panel

- **More languages**, and a way for people to contribute a translation
  without opening a pull request.
- **Dashboard widgets you arrange yourself.**
- **A guided first run** that walks a new operator from empty panel to
  connected server, instead of leaving them to find the right pages.

---

## Deliberately not planned

Not oversights. Each of these is a thing the panel could pretend to do
and would do badly, and saying so is more useful than a feature request
that never gets answered.

| Idea | Why not |
|---|---|
| **CPU, memory and disk of the game server** | The panel runs somewhere else. It would be measuring its own container and calling it your server. |
| **Starting a stopped server** | RCON needs a running server to accept anything. Stopping is a one-way street, and a start button that cannot work is worse than none. |
| **Downloading or installing mods from the panel** | The game server does this itself at start, through Steam. The panel would be taking on a job it cannot finish. |
| **Scraping Steam comments and discussions** | It would tie the panel to Valve's HTML markup, which changes without notice. They are linked instead. |
| **Panel port, HTTPS, self-restart** | Docker, or Coolify, owns those. |
| **Anything requiring the panel to sit beside the game** | The whole design is that it does not have to. |

---

## Shipped

What is already in, newest first. The full detail per release is in the
[releases](https://github.com/zone1987/zomboid-control-panel/releases).

| Version | What it brought |
|---|---|
| **1.4.x** | Mod dependencies, load order, diagnosis and repair, mod update watch, Discord cards for mods |
| **1.3.x** | The mod manager: browse the Steam Workshop, mod detail pages, add and remove, cover images |
| **1.2.x** | Coolify auto-deploy, Steam sign-in, the visual pass — colour, depth, focus and glass |
| **1.1.x** | Discord slash commands, chat mirroring, per-command rights |
| **1.0.0** | The first release people install: players, map, vehicles, items, events, config, console, chat, users and roles |

---

## Screenshots for the README

Not a feature, but a step that belongs to shipping this: both READMEs
are to carry screenshots the way a reader expects, each one clickable.

- **They are taken last**, once the mod detail page shows what Steam
  shows. Pictures of a page still being rebuilt would be wrong within
  the week.
- **They live in `docs/images/`**, not in a top-level folder — the
  repository root is not a gallery.
- **The interface is in English** for both READMEs. The German file
  gets the same pictures; a screenshot is not a translation.
- **A demo account, never a real one.** `demo@example.com` exists
  locally for exactly this. No real address, no server IP, no player
  name in a picture that goes to a public repository.

## Suggesting something

Open an [issue](https://github.com/zone1987/zomboid-control-panel/issues).
What helps most is the situation rather than the solution — *"I cannot
tell which of my map mods is winning"* leads somewhere better than
*"add a dropdown"*.
