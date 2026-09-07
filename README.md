# ZomboidControl

[![CI](https://img.shields.io/github/actions/workflow/status/zone1987/zomboid-control-panel/ci.yml?branch=main&label=CI&logo=githubactions&logoColor=white)](https://github.com/zone1987/zomboid-control-panel/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/zone1987/zomboid-control-panel?logo=github&label=release)](https://github.com/zone1987/zomboid-control-panel/releases/latest)
[![Image](https://img.shields.io/badge/ghcr.io-zomboid--control--panel-2496ED?logo=docker&logoColor=white)](https://github.com/zone1987/zomboid-control-panel/pkgs/container/zomboid-control-panel)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](backend/composer.json)
[![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)](frontend/package.json)
[![Licence](https://img.shields.io/badge/licence-MIT-green)](LICENSE)

> 🇩🇪 [Deutsche Fassung](README_DE.md)

**Run your Project Zomboid server from a web browser.**

Who is online and how are they doing? Where is everyone on the map? Change
the weather, kick somebody, read the chat, edit the server settings — all
from a web page, from anywhere, without logging into anything over SSH.

The panel does **not** run on your game server. It runs somewhere else —
your own computer, a small rented server, or a hosting provider — and
connects to your game server the same way you would: through file access
and the remote control the game already has.

> Free, non-commercial, and not an official product of The Indie Stone.

---

## Before you start

**You do not need to be technical.** This guide assumes you have never set
up a server before. Everything you need to type is here to copy.

**Set aside about 20 minutes** for the first installation.

**Two ways to install it:**

| Way                                            | Who it is for                                                        |
| ---------------------------------------------- | -------------------------------------------------------------------- |
| **[Coolify](#way-a-coolify-recommended)**      | Recommended. You click through a web interface, and HTTPS is handled for you |
| **[Docker](#way-b-docker-on-your-own-computer)** | If you want to try the panel out on your own machine                |

After that, both ways continue the same.

---

## Contents

1. [What the panel does](#what-the-panel-does)
2. [What you need from your game server](#what-you-need-from-your-game-server)
3. [Way A: Coolify (recommended)](#way-a-coolify-recommended)
4. [Way B: Docker on your own computer](#way-b-docker-on-your-own-computer)
5. [Logging in for the first time](#logging-in-for-the-first-time)
6. [Connecting your game server](#connecting-your-game-server)
7. [The Lua bridge — the piece that makes it worth it](#the-lua-bridge--the-piece-that-makes-it-worth-it)
8. [Connecting Discord](#connecting-discord)
9. [Updating](#updating)
10. [Backups](#backups)
11. [When something does not work](#when-something-does-not-work)
12. [All settings at a glance](#all-settings-at-a-glance)
13. [What the panel deliberately cannot do](#what-the-panel-deliberately-cannot-do)
14. [Game content and licence](#game-content-and-licence)

---

## What the panel does

- **Players** — who is online, health, infection, skills, traits, position.
  Kick, ban (by name or SteamID), unban, teleport, whitelist.
- **Map** — the world with your players and vehicles drawn on it. The
  vehicles are rendered from the game's own models.
- **Vehicles** — all 241 base-game vehicles plus whatever mods add, with
  their liveries and the real capacities read out of the game. Spawn one
  next to a player.
- **Items** — your server's real item list, with the icons from the game.
- **Weather and events** — rain, storm, snow, fog, lightning, helicopter,
  hordes. With a preview before you trigger anything.
- **Console** — the game's remote control, with your server's own command
  list.
- **Chat** — read it and write into it.
- **Server settings** — the whole configuration and every sandbox value,
  with explanations and in sensible groups instead of a text file.
- **Discord** — notifications in your channel, chat mirroring, and slash
  commands.
- **User management** — several accounts, two-factor login, passkeys, roles
  with per-page permissions, and a log of every action.

---

## What you need from your game server

Three things. You get the first two from your hosting provider — usually
in their control panel under "credentials" or "FTP".

### 1. RCON — the game's remote control

This is how the panel tells the game what to do: kick, ban, change the
weather, send messages.

You need: **address**, **port** (usually `27015`) and **password**.

*Running the server yourself?* The values are in your server INI under
`RCONPort` and `RCONPassword`. If there is no password set, RCON is off —
add one and restart the server.

### 2. FTP or SFTP — file access

This is how the panel reads and writes the configuration, reads the log
files for the chat, and uploads the bridge.

You need: **address**, **port**, **username** and **password**.

### 3. The Lua bridge — optional, but worth it

A small file the panel uploads to your server itself. Most things work
without it; with it, the panel becomes what it is meant to be. More on it
[below](#the-lua-bridge--the-piece-that-makes-it-worth-it).

---

## Way A: Coolify (recommended)

This assumes you already have Coolify running and can log into it.

**What you need besides that:** a domain pointing at your Coolify server —
an `A` record to its IP address. Coolify takes care of the HTTPS
certificate itself.

### Step 1 — Create the project in Coolify

1. In Coolify: **+ New** → **Project**, give it a name (`ZomboidControl`).
2. Inside the project: **+ New Resource**.
3. Choose **Public Repository**.
4. Enter as the repository:
   ```
   https://github.com/zone1987/zomboid-control-panel
   ```
5. As the **Build Pack**, choose **Docker Compose**. The default
   location (`/docker-compose.yaml`) is correct — there is nothing to
   change.
6. **Continue**.

### Step 2 — Enter your domain

Under **Configuration → General** you will find the **Domains** field.
Enter your domain, with `https://` in front:

```
https://zomboid.your-domain.com
```

Coolify requests the certificate itself as soon as the domain resolves to
its server.

### Step 3 — One variable, and only if the port is taken

**Normally there is nothing to set.** Coolify passes the domain you
entered as `COOLIFY_URL`, and the panel builds everything from it —
passwords, encryption keys and the database password are generated on
first start and kept in a volume. Mail, Google sign-in and the Steam key
are configured in the panel itself, under *Settings*, each with a test
button.

Two cases where you do add something under **Environment Variables**:

| Name             | When                                                             |
| ---------------- | ----------------------------------------------------------------- |
| `APP_PORT`       | if the deployment fails with **"Bind for :::8080 failed: port is already allocated"** — pick a free port, e.g. `8091` |
| `APP_PUBLIC_URL` | if the log says no public address is set, or you want a different address than the one Coolify routes |

> **Keep a copy of the encryption key once it exists.** After the first
> start you will find it under *Settings → Security*. It encrypts the FTP
> and RCON passwords, so a database backup without it restores everything
> except those. Save it to your password manager the first time you log in.

### Step 4 — Deploy

Click **Deploy**. Coolify builds the panel and starts it. The first time
this takes a few minutes — you can watch in the log window.

It is ready when it says **Running** at the top. Then open:

```
https://zomboid.your-domain.com/app
```

Continue at [logging in for the first
time](#logging-in-for-the-first-time).

### Coolify: what else you can do afterwards

- **Automatic updates.** Under *Configuration → General* there is
  **Automatic Deployment**. Switch it on and Coolify fetches new versions
  itself.
- **Restart.** The **Restart** button at the top right.
- **Read the log.** The **Logs** tab — it shows what the panel does at
  start and whether anything is missing.
- **Database backups.** Coolify can back the database up automatically: in
  the `database` resource under **Backups**. See also
  [backups](#backups).

---

## Way B: Docker on your own computer

For when you want to try the panel out first, or run it on your home
network.

**You need:** [Docker Desktop](https://www.docker.com/products/docker-desktop/)
(Windows, macOS) or Docker with the Compose plugin (Linux).

### Step 1 — Get the files

```bash
git clone https://github.com/zone1987/zomboid-control-panel.git
cd zomboid-control-panel
cp .env.example .env
```

> **No `git`?** You can also download the repository from GitHub as a ZIP,
> unpack it, and open a terminal in the unpacked folder. Rename the file
> `.env.example` to `.env` there.

### Step 2 — Set your address

Open `.env` in a text editor. One line matters:

```dotenv
APP_PUBLIC_URL=http://localhost:8080
```

Everything else has a working default. Passwords and encryption keys are
generated on the first start and kept in a Docker volume, and mail, Google
sign-in and the Steam key are configured in the panel itself.

> **Save the encryption key after the first start.** You will find it
> under *Settings → Security*. It encrypts the FTP and RCON passwords, so
> a database backup without it restores everything except those.

### Step 3 — Start it

```bash
docker compose up -d
```

The panel creates its own database and starts. You can watch with:

```bash
docker compose logs -f app
```

After about a minute it is ready. Then open in your browser:

```
http://localhost:8080/app
```

### Behind your own reverse proxy

The container serves plain HTTP on port 80 and expects your proxy to
handle HTTPS. Two things matter:

- `APP_PUBLIC_URL` must be the address **people actually type** — the panel
  builds its links from that value, not from the incoming request.
- Passkeys need the real domain in `WEBAUTHN_RELYING_PARTY_ID`, and they
  need HTTPS. Password plus authenticator app works without it.

---

## Logging in for the first time

The first time you open the panel it does not show a login but a **setup
wizard**: no account exists yet, so you create one. That account gets full
rights.

Afterwards the wizard closes for good — it cannot be opened a second time.

### If you ever lock yourself out

There is a way in from the command line. In Coolify you will find a
console in the container under **Terminal**; with Docker on your own
machine use:

```bash
docker compose exec app php bin/console app:user:create \
    you@example.com 'a password with at least 12 characters' --admin
```

The same command also resets the password of an existing account.

### Switch on two-factor login

Under *Account → Security*. The panel supports authenticator apps and
passkeys. You are shown recovery codes once — **write them down**, they
are never shown again.

---

## Connecting your game server

*Servers → Add server*. There are two sections to fill in.

### RCON — the remote control

| Field        | What goes in                                     |
| ------------ | ------------------------------------------------ |
| **Address**  | the IP or hostname of your game server           |
| **Port**     | usually `27015`, in the INI `RCONPort`           |
| **Password** | in the INI `RCONPassword`                        |

There is a **Test** button. **Use it.** A wrong RCON password is by far
the most common reason a fresh installation looks broken — and the test
tells you whether the connection or the password is the problem.

### FTP or SFTP — file access

| Field                                  | What goes in                                          |
| -------------------------------------- | ----------------------------------------------------- |
| **Address, port, user, password**      | as given to you by your hosting provider              |
| **Base path**                          | the directory you land in after logging in, usually `/` |
| **Lua path**                           | your server's `media/lua/server` directory            |
| **Log path**                           | where the log files are — this is what makes chat work |

**Do not know the paths?** No problem: the **Browse** button shows you your
server's directories, and you click your way there instead of guessing.

The password is stored encrypted and is never sent back to the browser.

---

## The Lua bridge — the piece that makes it worth it

RCON can **tell the game what to do**. What it cannot do is **ask the game
what is going on** — there simply is no command for "how much health does
this player have".

The bridge is a small file that runs inside the game and writes down what
it sees. The panel then reads those notes over FTP.

**Without the bridge you get:** console, chat, kick and ban, weather and
events, the configuration editor, and the list of who is connected.

**With the bridge you also get:** health, infection, skills, traits and
positions for every player; the map; the real item list including
everything mods bring; the vehicles this server actually loaded; safehouses
and factions.

### Installing it

*Servers → your server → Bridge → Install*. The panel uploads the file to
the Lua path you entered.

**Then restart the game server once.** The game only loads files like this
at start — a file on disk is not yet a running mod. So the panel does not
claim to be finished; it tells you exactly that.

Once the server is back, the bridge page shows the **running** version.
The panel reads that from what the bridge itself writes — not from the
file.

### Installing it by hand

If the panel cannot reach your FTP, every release ships the bridge as a
separate file:

1. Download `ZomboidControlBridge.lua` from the
   [latest release](https://github.com/zone1987/zomboid-control-panel/releases/latest).
2. Copy it into your server's `media/lua/server/` directory.
3. Restart the game server.

That is the whole installation. It needs no `mod.info` and does **not** go
into the `mods/` folder.

### Keeping it current

A panel update can bring a newer bridge. The bridge page compares three
things and keeps them apart: what this panel ships, what is on disk, and
**what is actually running in the game**. All three can differ — and only
the third one answers commands.

---

## Connecting Discord

Optional. The bot does three things, and they are deliberately kept apart,
because they can fail independently of one another:

| What                              | Direction        | Needs a public address? |
| --------------------------------- | ---------------- | ----------------------- |
| **Notifications** into a channel  | panel → Discord  | no                      |
| **Chat** mirrored from the game   | panel → Discord  | no                      |
| **Slash commands** (`/server status`) | Discord → panel | **yes**              |

The last one is the exception, and the reason is not a malfunction:
**Discord calls your panel from its own servers.** An address that only
exists on your computer — `localhost`, or anything with `.local` — cannot
be reached from there. The panel detects this and says so on the Discord
page instead of leaving you to guess.

**With Coolify and a real domain everything works**, because your address
is then public.

### Setting it up

1. Create an application at
   [discord.com/developers](https://discord.com/developers/applications).
2. Copy the **Application ID**, the **Public Key** and — in the *Bot* tab —
   the **Token** into the panel under *Settings → Discord*.
3. Invite the bot to your server with the link the panel builds for you.
4. Under *Discord*, pick the channels and switch on the events you want.
   **Everything is off to begin with.** Sending "X gave themselves 500
   rounds of ammunition" into a public channel is a different thing from a
   restart announcement — that is your decision to make.
5. For slash commands, enter the interaction URL the panel shows into your
   Discord application and click **Register commands**.

Anyone Discord considers an administrator of your server can use every
command. Beyond that you assign Discord roles per command — and each
command costs the same permission the equivalent button in the panel does.
Doing something through Discord is never a shortcut past your rights.

---

## Updating

**In Coolify:** click **Redeploy**. Or switch on **Automatic Deployment**
under *Configuration → General* and Coolify does it itself.

**With Docker:**

```bash
docker compose pull
docker compose up -d
```

The database is adjusted automatically at start — you do not have to do
anything.

The panel tells you in its header when a newer version exists. After an
update, the bridge page reports whether the bridge needs renewing too.

### Staying on a particular version

Set the `APP_VERSION` environment variable:

```dotenv
APP_VERSION=1.0.0
```

Without it, the newest version always runs.

---

## Backups

Two things are worth backing up.

### 1. The database

It holds your accounts, your game server's credentials, the action log and
all settings.

**In Coolify:** in the `database` resource under **Backups** — you can set
a schedule there, and Coolify can also send the backups to S3 storage.

**With Docker:**

```bash
docker compose exec -T database pg_dump -U zomboid zomboid > backup.sql
```

Restoring:

```bash
docker compose exec -T database psql -U zomboid zomboid < backup.sql
```

### 2. The encryption key

`CREDENTIALS_ENCRYPTION_KEY`. Without it, a database backup restores
everything — except the FTP and RCON passwords. **Keep it separately from
the backup**, ideally in your password manager.

---

## When something does not work

### The panel does not start

Look at the log — in Coolify in the **Logs** tab, with Docker via
`docker compose logs app`.

The panel would rather not start at all than run half-configured, and it
tells you which value is wrong:

| Message                                                       | What to do                                                  |
| ------------------------------------------------------------- | ----------------------------------------------------------- |
| `FATAL: CREDENTIALS_ENCRYPTION_KEY must be 64 hex characters`  | Only if you set one yourself — it needs `openssl rand -hex 32`. |
| `FATAL: database did not become reachable within 60s`          | The database did not come up — check its log.               |
| `FATAL: no database password appeared`                         | The database container never started. Check its log first.  |
| `FATAL: no public address is set`                              | Set `APP_PUBLIC_URL`. On Coolify, add `COOLIFY_URL` as an environment variable with an empty value so it reaches the container. |
| `Bind for :::8080 failed: port is already allocated`           | Something else holds that port. Set `APP_PORT` to a free one, e.g. `8091`. |

### "RCON unreachable"

Check in this order:

1. **The password.** Use the Test button — it distinguishes a refused
   password from a refused connection.
2. **The port.** It has to be reachable from wherever the panel runs. A
   firewall in between is the usual reason.
3. **Is the game server running at all?** RCON needs it up. This is also
   why the panel can stop a server but never start one.

### The player pages are empty

That is the bridge. Either it is not installed, or the file is there but
the game server has not been restarted since. The bridge page tells you
which of the two it is.

### Slash commands say "The application is not responding"

Your address cannot be reached from the internet. See
[Discord](#connecting-discord) — notifications and chat carry on working,
and the Discord page reports the two capabilities separately.

### A setting says "restart needed"

Because it is true. The game re-reads the server INI values while running,
but the sandbox values only at start. The panel measured this rather than
assuming it, and tells you which of the two kinds you are changing.

### I cannot get in any more

See [logging in for the first time](#logging-in-for-the-first-time) — the
`app:user:create` command also resets an existing password.

---

## All settings at a glance

### The address, which is all that is required

| Variable         | What it is                                                      |
| ---------------- | ----------------------------------------------------------------- |
| `APP_PUBLIC_URL` | the address people type, with `https://`                          |
| `COOLIFY_URL`    | set by Coolify to the domain you configured — used when `APP_PUBLIC_URL` is empty, so on Coolify there is nothing to set |

### Generated for you

Left empty, these are created on the first start and kept in a Docker
volume. Set them only when moving an existing installation to a new host.

| Variable                     | What it is                                            |
| ---------------------------- | ------------------------------------------------------ |
| `APP_SECRET`                 | Symfony's signing secret                               |
| `CREDENTIALS_ENCRYPTION_KEY` | encrypts the FTP and RCON passwords                    |
| `POSTGRES_PASSWORD`          | set one yourself, or let it be generated               |
| `DATABASE_URL`               | set it to use an external PostgreSQL instead           |
| `POSTGRES_DB` / `POSTGRES_USER` | the database name and user; `zomboid` by default    |

### Login

All three are derived from `APP_PUBLIC_URL`. Set them only when the panel
answers on a different name than the one people type.

| Variable                      | Default                    | What it is                            |
| ----------------------------- | -------------------------- | ------------------------------------- |
| `SERVER_NAME`                 | the host of APP_PUBLIC_URL | the web server's domain name          |
| `WEBAUTHN_RELYING_PARTY_ID`   | the host of APP_PUBLIC_URL | the bare domain for passkeys          |
| `WEBAUTHN_RELYING_PARTY_NAME` | `ZomboidControl`           | what the passkey prompt calls the panel |

Google sign-in is configured in the panel, under *Settings → Google*.

### Email

Configured in the panel under *Settings → Mail*, with presets for the
common providers, a test send, and a check of your SPF, DKIM and DMARC
records. There is nothing to set here.

### Size and performance

| Variable                   | Default | What it does                                  |
| -------------------------- | ------- | --------------------------------------------- |
| `APP_PORT`                 | `8080`  | the port on your machine (Docker only)         |
| `MESSENGER_WORKERS`        | `1`     | background workers; raise it for many servers  |
| `PHP_FPM_API_MAX_CHILDREN` | `12`    | concurrent requests                            |
| `PHP_FPM_SSE_MAX_CHILDREN` | `8`     | concurrent long-running connections            |

### Other

| Variable            | What it is                                                       |
| ------------------- | ---------------------------------------------------------------- |
| `APP_VERSION` | which version to run; the newest one if unset |

The Steam Web API key is configured in the panel, under *Settings → Steam*.

---

## What the panel deliberately cannot do

The panel runs somewhere other than your game server. That is by design,
and a few things follow from it that other panels offer:

- **No CPU, memory or disk readings for the game server.** The panel would
  be measuring its own container, not your game server.
- **It cannot start a stopped server.** RCON needs a running server to
  accept a command, so stopping is a one-way street. The panel says so
  rather than offering a button that cannot work.
- **No settings for the panel's own port or HTTPS.** Docker, or Coolify,
  takes care of that.

What it does instead: everything reachable over files and RCON — and with
the bridge, that turns out to be most of the game.

---

## Game content and licence

The panel displays vehicle models, textures, ground tiles and item icons
from Project Zomboid. These are © The Indie Stone Ltd and are used under
their terms, which permit this for a non-commercial fan project **on
condition of a visible notice** — the panel's `/app/credits` page. That is
why the page exists and why it is linked from everywhere.

**No game artwork is in this repository.** It is extracted from your own
installation. Mod content belongs to its authors and needs their permission
separately.

The panel's own code is under the [MIT licence](LICENSE); the game
content is not, and the licence file says so explicitly. ZomboidControl is
not an official product of The Indie Stone and is neither supported nor
endorsed by them.

---

## For developers

The development environment runs on [ddev](https://ddev.readthedocs.io/):

```bash
ddev start && ddev setup
ddev dev                       # frontend with hot reload
ddev exec -d /var/www/html/backend "php bin/phpunit"
```

`CLAUDE.md` holds the project's conventions; `CONTEXT.md` is the full
development record: what is built, what has been demonstrated, and why
decisions were made the way they were.
