# ZomboidControl

[![CI](https://img.shields.io/github/actions/workflow/status/zone1987/zomboid-control-panel/ci.yml?branch=main&label=CI&logo=githubactions&logoColor=white)](https://github.com/zone1987/zomboid-control-panel/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/zone1987/zomboid-control-panel?logo=github&label=Version)](https://github.com/zone1987/zomboid-control-panel/releases/latest)
[![Image](https://img.shields.io/badge/ghcr.io-zomboid--control--panel-2496ED?logo=docker&logoColor=white)](https://github.com/zone1987/zomboid-control-panel/pkgs/container/zomboid-control-panel)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](backend/composer.json)
[![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)](frontend/package.json)
[![Lizenz](https://img.shields.io/badge/Lizenz-MIT-green)](LICENSE)

> 🇬🇧 [English version](README.md)

**Verwalte deinen Project-Zomboid-Server im Browser.**

Wer ist online und wie geht es ihnen? Wo steht jeder auf der Karte? Wetter
ändern, jemanden kicken, im Chat mitlesen, die Servereinstellungen
anpassen — alles über eine Webseite, von überall, ohne dich irgendwo per
SSH einzuloggen.

Das Panel läuft **nicht** auf deinem Spielserver. Es läuft woanders — auf
deinem eigenen Rechner, einem kleinen Mietserver oder bei einem Anbieter —
und verbindet sich mit deinem Spielserver so, wie du es auch tun würdest:
über Dateizugriff und die Fernsteuerung, die das Spiel bereits mitbringt.

> Kostenlos, nicht-kommerziell, und kein offizielles Produkt von The Indie Stone.

---

## Bevor du anfängst

**Du musst nicht technisch versiert sein.** Diese Anleitung geht davon
aus, dass du noch nie einen Server eingerichtet hast. Alles, was du tippen
musst, steht hier zum Kopieren.

**Plan etwa 20 Minuten** für die erste Installation ein.

**Zwei Wege zur Auswahl:**

| Weg                                             | Für wen                                                                    |
| ----------------------------------------------- | -------------------------------------------------------------------------- |
| **[Coolify](#weg-a-coolify-empfohlen)**         | Empfohlen. Du klickst dich durch eine Oberfläche, um HTTPS wird sich gekümmert |
| **[Docker](#weg-b-docker-auf-deinem-rechner)**  | Wenn du das Panel auf deinem eigenen Rechner ausprobieren willst           |

Danach geht es für beide Wege gleich weiter.

---

## Inhalt

1. [Was das Panel kann](#was-das-panel-kann)
2. [Was du von deinem Spielserver brauchst](#was-du-von-deinem-spielserver-brauchst)
3. [Weg A: Coolify (empfohlen)](#weg-a-coolify-empfohlen)
4. [Weg B: Docker auf deinem Rechner](#weg-b-docker-auf-deinem-rechner)
5. [Das erste Mal anmelden](#das-erste-mal-anmelden)
6. [Deinen Spielserver verbinden](#deinen-spielserver-verbinden)
7. [Die Lua-Bridge — das Stück, das sich lohnt](#die-lua-bridge--das-stück-das-sich-lohnt)
8. [Discord anbinden](#discord-anbinden)
9. [Aktualisieren](#aktualisieren)
10. [Sicherungen](#sicherungen)
11. [Wenn etwas nicht funktioniert](#wenn-etwas-nicht-funktioniert)
12. [Alle Einstellungen auf einen Blick](#alle-einstellungen-auf-einen-blick)
13. [Was das Panel bewusst nicht kann](#was-das-panel-bewusst-nicht-kann)
14. [Spielinhalte und Lizenz](#spielinhalte-und-lizenz)

---

## Was das Panel kann

- **Spieler** — wer online ist, Gesundheit, Infektion, Fertigkeiten,
  Eigenschaften, Position. Kicken, bannen (per Name oder SteamID),
  entbannen, teleportieren, auf die Weiße Liste setzen.
- **Karte** — die Welt mit deinen Spielern und Fahrzeugen darauf. Die
  Fahrzeuge werden aus den echten Modellen des Spiels gezeichnet.
- **Fahrzeuge** — alle 241 Fahrzeuge des Grundspiels plus alles, was Mods
  hinzufügen, mit ihren Lackierungen und den echten Platzangaben aus dem
  Spiel. Direkt neben einem Spieler erzeugen.
- **Gegenstände** — die echte Gegenstandsliste deines Servers, mit den
  Symbolen aus dem Spiel.
- **Wetter und Ereignisse** — Regen, Sturm, Schnee, Nebel, Blitz,
  Hubschrauber, Horden. Mit Vorschau, bevor du etwas auslöst.
- **Konsole** — die Fernsteuerung des Spiels, mit der Befehlsliste deines
  eigenen Servers.
- **Chat** — mitlesen und hineinschreiben.
- **Servereinstellungen** — die komplette Konfiguration und jeder
  Sandbox-Wert, mit Erklärungen und in sinnvollen Gruppen statt als
  Textdatei.
- **Discord** — Meldungen in deinen Kanal, Chat-Spiegelung und
  Slash-Befehle.
- **Benutzerverwaltung** — mehrere Konten, Zwei-Faktor-Anmeldung,
  Passkeys, Rollen mit Rechten pro Seite, und ein Protokoll jeder Aktion.
- **Sieben Sprachen** — Deutsch und Englisch sind von Hand geschrieben.
  Spanisch, Französisch, Italienisch, Polnisch und Russisch sind
  maschinell übersetzt und im Umschalter auch so gekennzeichnet;
  Korrekturen sind als Issue sehr willkommen. Die Namen von Items und
  Fahrzeugen kommen aus deiner eigenen Spielinstallation, heißen also
  genau so wie im Spiel.

---

## Was du von deinem Spielserver brauchst

Drei Dinge. Die ersten beiden bekommst du von deinem Hoster — meistens in
dessen Kundenbereich unter „Zugangsdaten" oder „FTP".

### 1. RCON — die Fernsteuerung des Spiels

Damit sagt das Panel dem Spiel, was es tun soll: kicken, bannen, Wetter
ändern, Nachrichten senden.

Du brauchst: **Adresse**, **Port** (meistens `27015`) und **Passwort**.

*Du betreibst den Server selbst?* Die Werte stehen in deiner Server-INI
unter `RCONPort` und `RCONPassword`. Ist dort kein Passwort gesetzt, ist
RCON aus — trag eines ein und starte den Server neu.

### 2. FTP oder SFTP — der Dateizugriff

Damit liest und schreibt das Panel die Konfiguration, liest die
Protokolldateien für den Chat und lädt die Bridge hoch.

Du brauchst: **Adresse**, **Port**, **Benutzername** und **Passwort**.

### 3. Die Lua-Bridge — optional, aber lohnend

Eine kleine Datei, die das Panel selbst auf deinen Server lädt. Das Meiste
funktioniert ohne sie; mit ihr wird das Panel erst das, was es sein soll.
Mehr dazu [weiter unten](#die-lua-bridge--das-stück-das-sich-lohnt).

---

## Weg A: Coolify (empfohlen)

Diese Anleitung geht davon aus, dass Coolify bei dir bereits läuft und du
dich dort anmelden kannst.

**Was du sonst noch brauchst:** eine Domain, die auf deinen Coolify-Server
zeigt — ein `A`-Eintrag auf dessen IP-Adresse. Um das HTTPS-Zertifikat
kümmert sich Coolify selbst.

### Schritt 1 — Das Projekt in Coolify anlegen

1. In Coolify: **+ New** → **Project**, gib ihm einen Namen
   (`ZomboidControl`).
2. Im Projekt: **+ New Resource**.
3. Wähle **Public Repository**.
4. Trag als Repository ein:
   ```
   https://github.com/zone1987/zomboid-control-panel
   ```
5. Als **Build Pack** wählst du **Docker Compose**. Der voreingestellte
   Pfad (`/docker-compose.yaml`) stimmt — daran musst du nichts ändern.
6. **Continue**.

### Schritt 2 — Deine Domain eintragen

Unter **Configuration → General** findest du das Feld **Domains**. Trag
deine Domain ein, mit `https://` davor:

```
https://zomboid.deine-domain.de
```

Coolify fordert das Zertifikat selbst an, sobald die Domain auf seinen
Server zeigt.

### Schritt 3 — Eine Variable, und nur wenn der Port belegt ist

**Normalerweise ist gar nichts einzutragen.** Coolify übergibt die Domain,
die du eingetragen hast, als `COOLIFY_URL`, und das Panel leitet alles
daraus ab. Passwörter, Verschlüsselungsschlüssel und das Datenbankpasswort
entstehen beim ersten Start und liegen in einem Volume. E-Mail,
Google-Anmeldung und den Steam-Schlüssel trägst du im Panel selbst ein,
unter *Einstellungen* — dort steht neben jedem ein Testknopf.

Ein Fall, in dem du unter **Environment Variables** doch etwas anlegst:

| Name             | Wann                                                             |
| ---------------- | ------------------------------------------------------------------ |
| `APP_PUBLIC_URL` | wenn im Protokoll steht, dass keine öffentliche Adresse gesetzt ist, oder du eine andere Adresse willst |

> **Sichere den Verschlüsselungsschlüssel, sobald es ihn gibt.** Nach dem
> ersten Start findest du ihn unter *Einstellungen → Sicherheit*. Er
> verschlüsselt die FTP- und RCON-Passwörter, eine Datenbanksicherung ohne
> ihn stellt also alles wieder her außer diesen. Kopiere ihn beim ersten
> Anmelden in deinen Passwortmanager.

### Schritt 4 — Starten

Klick auf **Deploy**. Coolify baut das Panel und startet es. Beim ersten
Mal dauert das ein paar Minuten — im Protokollfenster kannst du zusehen.

Fertig ist es, wenn oben **Running** steht. Dann öffne:

```
https://zomboid.deine-domain.de/app
```

Weiter geht es beim [ersten Anmelden](#das-erste-mal-anmelden).

### Coolify: was du danach noch tun kannst

- **Automatische Updates.** Nicht über den Schalter **Automatic
  Deployment** unter *Configuration → General* — der beobachtet ein
  Git-Repository, und dieses Panel kommt als fertiges Image, dort ist
  also nichts zu sehen. Der Deploy-Webhook erledigt es stattdessen:
  [Aktualisieren, ohne zu klicken](#aktualisieren-ohne-zu-klicken).
- **Neustart.** Der **Restart**-Knopf oben rechts.
- **Ins Protokoll schauen.** Der Reiter **Logs** — dort steht, was das
  Panel beim Start macht und ob etwas fehlt.
- **Datenbanksicherungen.** Coolify kann die Datenbank automatisch
  sichern: in der Ressource `database` unter **Backups**. Siehe auch
  [Sicherungen](#sicherungen).

---

## Weg B: Docker auf deinem Rechner

Für den Fall, dass du das Panel erst einmal ausprobieren oder es im
Heimnetz betreiben willst.

**Du brauchst:** [Docker Desktop](https://www.docker.com/products/docker-desktop/)
(Windows, macOS) oder Docker mit dem Compose-Plugin (Linux).

### Schritt 1 — Die Dateien holen

```bash
git clone https://github.com/zone1987/zomboid-control-panel.git
cd zomboid-control-panel
cp .env.example .env
```

> **Kein `git`?** Du kannst das Repository auf GitHub auch als ZIP
> herunterladen, entpacken und im entpackten Ordner ein Terminal öffnen.
> Benenne dort die Datei `.env.example` in `.env` um.

### Schritt 2 — Deine Adresse eintragen

Öffne `.env` in einem Texteditor. Eine Zeile ist wichtig:

```dotenv
APP_PUBLIC_URL=http://localhost:8080
```

Alles andere hat einen funktionierenden Standardwert. Passwörter und
Verschlüsselungsschlüssel entstehen beim ersten Start und liegen in einem
Docker-Volume; E-Mail, Google-Anmeldung und den Steam-Schlüssel trägst du
im Panel selbst ein.

> **Sichere den Verschlüsselungsschlüssel nach dem ersten Start.** Du
> findest ihn unter *Einstellungen → Sicherheit*. Er verschlüsselt die
> FTP- und RCON-Passwörter, eine Datenbanksicherung ohne ihn stellt also
> alles wieder her außer diesen.

### Schritt 3 — Starten

```bash
docker compose -f docker-compose.yaml -f docker-compose.local.yaml up -d
```

Die zweite Datei veröffentlicht den Port, damit du das Panel unter
`localhost` erreichst. Die Basisdatei allein hat keinen veröffentlichten
Port — ein Reverse Proxy braucht keinen, und ein belegter Port lässt sonst
das ganze Deployment scheitern.

Das Panel legt seine Datenbank selbst an und startet. Zusehen kannst du
mit:

```bash
docker compose logs -f app
```

Nach etwa einer Minute ist es bereit. Dann öffne im Browser:

```
http://localhost:8080/app
```

### Hinter einem eigenen Reverse Proxy

Der Container liefert einfaches HTTP auf Port 80 aus und erwartet, dass
dein Proxy sich um HTTPS kümmert. Zwei Dinge sind wichtig:

- `APP_PUBLIC_URL` muss die Adresse sein, die **die Leute tatsächlich
  eintippen** — das Panel baut seine Links aus diesem Wert, nicht aus der
  eingehenden Anfrage.
- Passkeys brauchen die echte Domain in `WEBAUTHN_RELYING_PARTY_ID`, und
  sie brauchen HTTPS. Passwort plus Authenticator-App funktioniert auch
  ohne.

---

## Das erste Mal anmelden

Beim ersten Aufruf zeigt das Panel keinen Login, sondern einen
**Einrichtungsassistenten**: es gibt noch kein Konto, also legst du eines
an. Dieses Konto bekommt alle Rechte.

Danach schließt sich der Assistent endgültig — ein zweites Mal lässt er
sich nicht öffnen.

### Falls du dich jemals aussperrst

Es gibt einen Weg über die Befehlszeile. In Coolify findest du unter
**Terminal** eine Konsole im Container; mit Docker auf deinem eigenen
Rechner nimmst du:

```bash
docker compose exec app php bin/console app:user:create \
    du@beispiel.de 'ein Passwort mit mindestens 12 Zeichen' --admin
```

Derselbe Befehl setzt auch das Passwort eines bestehenden Kontos zurück.

### Zwei-Faktor-Anmeldung einschalten

Unter *Konto → Sicherheit*. Das Panel unterstützt Authenticator-Apps und
Passkeys. Die Notfallcodes werden dir einmalig angezeigt — **schreib sie
auf**, sie werden nie wieder gezeigt.

---

## Deinen Spielserver verbinden

*Server → Server hinzufügen*. Zwei Abschnitte sind auszufüllen.

### RCON — die Fernsteuerung

| Feld         | Was hineingehört                                 |
| ------------ | ------------------------------------------------ |
| **Adresse**  | die IP oder der Hostname deines Spielservers     |
| **Port**     | meistens `27015`, in der INI `RCONPort`          |
| **Passwort** | in der INI `RCONPassword`                        |

Es gibt einen **Testen**-Knopf. **Benutze ihn.** Ein falsches
RCON-Passwort ist mit Abstand der häufigste Grund, warum eine frische
Installation kaputt aussieht — und der Test sagt dir, ob die Verbindung
oder das Passwort das Problem ist.

### FTP oder SFTP — der Dateizugriff

| Feld                                     | Was hineingehört                                        |
| ---------------------------------------- | ------------------------------------------------------- |
| **Adresse, Port, Benutzer, Passwort**    | wie von deinem Hoster angegeben                         |
| **Basispfad**                            | das Verzeichnis, in dem du nach dem Login landest, meist `/` |
| **Lua-Pfad**                             | das Verzeichnis `media/lua/server` deines Servers        |
| **Protokollpfad**                        | wo die Protokolldateien liegen — dafür funktioniert der Chat |

**Du kennst die Pfade nicht?** Kein Problem: der **Durchsuchen**-Knopf
zeigt dir die Verzeichnisse deines Servers, und du klickst dich hin, statt
zu raten.

Das Passwort wird verschlüsselt gespeichert und nie an den Browser
zurückgegeben.

---

## Die Lua-Bridge — das Stück, das sich lohnt

RCON kann dem Spiel **sagen, was es tun soll**. Was es nicht kann, ist das
Spiel **zu fragen, was gerade los ist** — es gibt schlicht keinen Befehl
„wie viel Gesundheit hat dieser Spieler".

Die Bridge ist eine kleine Datei, die im Spiel mitläuft und aufschreibt,
was sie sieht. Das Panel liest diese Aufzeichnungen dann über FTP.

**Ohne Bridge bekommst du:** Konsole, Chat, Kicken und Bannen, Wetter und
Ereignisse, den Konfigurationseditor und die Liste, wer verbunden ist.

**Mit Bridge kommt dazu:** Gesundheit, Infektion, Fertigkeiten,
Eigenschaften und Positionen jedes Spielers; die Karte; die echte
Gegenstandsliste inklusive allem, was Mods mitbringen; die Fahrzeuge, die
dieser Server wirklich geladen hat; Zufluchtsorte und Fraktionen.

### Installieren

*Server → dein Server → Bridge → Installieren*. Das Panel lädt die Datei
in den Lua-Pfad hoch, den du eingetragen hast.

**Danach musst du den Spielserver einmal neu starten.** Das Spiel lädt
solche Dateien nur beim Start — eine Datei auf der Festplatte ist noch
kein laufender Mod. Deshalb behauptet das Panel auch nicht, fertig zu
sein, sondern sagt dir genau das.

Ist der Server wieder da, zeigt die Bridge-Seite die **laufende** Version.
Die liest das Panel aus dem, was die Bridge selbst schreibt — nicht aus
der Datei.

### Von Hand installieren

Falls das Panel deinen FTP-Zugang nicht erreicht, liegt die Bridge jeder
Veröffentlichung als eigene Datei bei:

1. Lade `ZomboidControlBridge.lua` von der
   [neuesten Version](https://github.com/zone1987/zomboid-control-panel/releases/latest)
   herunter.
2. Kopiere sie in das Verzeichnis `media/lua/server/` deines Servers.
3. Starte den Spielserver neu.

Das ist die ganze Installation. Sie braucht keine `mod.info` und gehört
**nicht** in den `mods/`-Ordner.

### Aktuell halten

Ein Panel-Update kann eine neuere Bridge mitbringen. Die Bridge-Seite
vergleicht drei Dinge und hält sie auseinander: was dieses Panel
mitliefert, was auf der Festplatte liegt, und **was tatsächlich im Spiel
läuft**. Alle drei können sich unterscheiden — und nur das Dritte
antwortet auf Befehle.

---

## Discord anbinden

Optional. Der Bot macht drei Dinge, und die sind bewusst getrennt, weil
sie unabhängig voneinander ausfallen können:

| Was                                    | Richtung        | Braucht eine öffentliche Adresse? |
| -------------------------------------- | --------------- | --------------------------------- |
| **Meldungen** in einen Kanal           | Panel → Discord | nein                              |
| **Chat** aus dem Spiel spiegeln        | Panel → Discord | nein                              |
| **Slash-Befehle** (`/server status`)   | Discord → Panel | **ja**                            |

Der letzte Punkt ist die Ausnahme, und der Grund ist keine Fehlfunktion:
**Discord ruft dein Panel von seinen eigenen Servern aus an.** Eine
Adresse, die nur auf deinem Rechner existiert — `localhost` oder etwas mit
`.local` — ist von dort nicht erreichbar. Das Panel erkennt das und sagt
es dir auf der Discord-Seite, statt dich raten zu lassen.

**Mit Coolify und einer echten Domain funktioniert alles**, weil deine
Adresse dann öffentlich ist.

### Einrichten

1. Erstelle eine Anwendung auf
   [discord.com/developers](https://discord.com/developers/applications).
2. Kopiere **Application ID**, **Public Key** und — im Reiter *Bot* — den
   **Token** in das Panel unter *Einstellungen → Discord*.
3. Lade den Bot mit dem Link, den das Panel dir baut, auf deinen Server
   ein.
4. Unter *Discord* wählst du die Kanäle und schaltest die Ereignisse ein,
   die du willst. **Zunächst ist alles aus.** „X hat sich 500 Schuss
   Munition gegeben" in einen öffentlichen Kanal zu schicken ist etwas
   anderes als eine Neustart-Ankündigung — das ist deine Entscheidung.
5. Für die Slash-Befehle trägst du die Interaktions-URL, die das Panel
   anzeigt, in deiner Discord-Anwendung ein und klickst auf **Befehle
   registrieren**.

Wer in Discord Administrator deines Servers ist, darf jeden Befehl nutzen.
Darüber hinaus weist du pro Befehl Discord-Rollen zu — und jeder Befehl
kostet dieselbe Berechtigung wie der entsprechende Knopf im Panel. Etwas
über Discord zu tun ist nie eine Abkürzung an den Rechten vorbei.

---

## Aktualisieren

**In Coolify:** klick auf **Redeploy**. Die Compose-Datei setzt
`pull_policy: always`, das aktuelle Image wird also jedes Mal geholt.

**Mit Docker:**

```bash
docker compose pull
docker compose up -d
```

Die Datenbank wird beim Start automatisch angepasst — du musst nichts tun.

Das Panel sagt dir oben im Kopfbereich, wenn es eine neuere Version gibt.
Nach einem Update meldet die Bridge-Seite, ob auch die Bridge erneuert
werden muss.

### Aktualisieren, ohne zu klicken

Hier steckt eine Falle, die benannt gehört, denn der Schalter, der
richtig klingt, ist der falsche.

Coolify hat unter *Configuration → General* die **Automatic
Deployment** — und die hilft bei diesem Panel nicht. Sie beobachtet ein
**Git-Repository** auf neue Commits. Dieses Panel wird als fertiges Image
ausgeliefert, eine neue Version ändert also keine Datei, die Coolify
sieht: sie ändert nur, auf welches Image der Tag `latest` zeigt. Ein
wandernder Registry-Tag ist kein Ereignis, und von allein merkt das
niemand.

Also sagt das Panel Bescheid. Unter *Einstellungen → Coolify* steht alles
Nötige, und es ist aus, bis du es einschaltest.

**1. API-Zugriff erlauben.** In Coolify unter *Settings → Advanced* den
**API Access** einschalten.

**2. Token anlegen.** *Keys & Tokens → API Tokens → Add*, mindestens mit
der Berechtigung **deploy**. Gleich kopieren — Coolify zeigt ihn nur
einmal.

**3. Die Webhook-Adresse holen.** Öffne deine Anwendung, dann *Automation
→ Webhooks*. Der oberste, der **Deploy webhook**, ist der richtige:

```
https://coolify.example.com/api/v1/deploy?uuid=DEINE-UUID&force=false
```

Die vier darunter — GitHub, GitLab, Bitbucket, Gitea — sind für etwas
anderes: mit ihnen reagiert Coolify auf einen Git-Push. Nicht diese.

**4. Beides ins Panel eintragen**, unter *Einstellungen → Coolify*,
**Test senden** drücken und **Neue Version automatisch ausrollen**
einschalten.

Der Test rollt wirklich aus — man kann eine Plattform nicht fragen, ob
etwas klappen würde. Wird er abgelehnt, sagt dir das Panel, welche
Einstellung zu ändern ist, statt dir einen HTTP-Code zu zeigen.

**Zur Adressliste.** Ist in Coolify *Allowed API IPs* gesetzt, trag die
Adresse ein, von der das Panel selbst aufruft — also die des Rechners, auf
dem es läuft. Genau deshalb sitzt das im Panel und nicht in der
Release-Pipeline: ein CI-Runner hat keine feste Adresse und würde dich
zwingen, die Liste für alles zu öffnen.

**Mehrere Panels an einer Datenbank?** Nur eines rollt aus. Der Anspruch
ist eine Zeile mit dem Versionsnamen, und die Datenbank lässt genau einen
durch.

### Bei einer bestimmten Version bleiben

Setz die Umgebungsvariable `APP_VERSION`:

```dotenv
APP_VERSION=1.0.0
```

Ohne sie läuft immer die neueste Version.

---

## Sicherungen

Zwei Dinge solltest du sichern.

### 1. Die Datenbank

Darin stecken deine Konten, die Zugangsdaten deines Spielservers, das
Aktionsprotokoll und alle Einstellungen.

**In Coolify:** in der Ressource `database` unter **Backups** — dort lässt
sich ein Zeitplan einstellen, und Coolify kann die Sicherungen auch zu
einem S3-Speicher schicken.

**Mit Docker:**

```bash
docker compose exec -T database pg_dump -U zomboid zomboid > sicherung.sql
```

Zurückspielen:

```bash
docker compose exec -T database psql -U zomboid zomboid < sicherung.sql
```

### 2. Der Verschlüsselungsschlüssel

`CREDENTIALS_ENCRYPTION_KEY`. Ohne ihn stellt eine Datenbanksicherung
alles wieder her — außer den FTP- und RCON-Passwörtern. **Bewahre ihn
getrennt von der Sicherung auf**, am besten im Passwortmanager.

---

## Wenn etwas nicht funktioniert

### Das Panel startet nicht

Schau ins Protokoll — in Coolify im Reiter **Logs**, mit Docker über
`docker compose logs app`.

Das Panel startet lieber gar nicht als halb konfiguriert, und es sagt dir,
welcher Wert falsch ist:

| Meldung                                                        | Was zu tun ist                                             |
| --------------------------------------------------------------- | ----------------------------------------------------------- |
| `FATAL: CREDENTIALS_ENCRYPTION_KEY must be 64 hex characters`   | Nur wenn du selbst einen gesetzt hast — er braucht `openssl rand -hex 32`. |
| `FATAL: database did not become reachable within 60s`           | Die Datenbank ist nicht hochgekommen — schau in ihr Protokoll. |
| `FATAL: no database password appeared`                          | Der Datenbank-Container ist nie gestartet. Schau zuerst in dessen Protokoll. |
| `FATAL: no public address is set`                               | Setz `APP_PUBLIC_URL`. In Coolify legst du `COOLIFY_URL` als Umgebungsvariable mit leerem Wert an, damit sie den Container erreicht. |
| `Bind for :::8080 failed: port is already allocated`            | Nur wenn du selbst einen Port veröffentlichst: setz `APP_PORT` auf einen freien. Coolify veröffentlicht keinen. |

### „RCON nicht erreichbar"

Prüf in dieser Reihenfolge:

1. **Das Passwort.** Nimm den Testen-Knopf — er unterscheidet ein
   abgelehntes Passwort von einer abgelehnten Verbindung.
2. **Den Port.** Er muss von dort erreichbar sein, wo das Panel läuft.
   Eine Firewall dazwischen ist der übliche Grund.
3. **Läuft der Spielserver überhaupt?** RCON braucht ihn laufend. Deshalb
   kann das Panel einen Server auch stoppen, aber nie starten.

### Bei den Spielern steht nichts

Das ist die Bridge. Entweder ist sie nicht installiert, oder die Datei
liegt da, aber der Spielserver wurde seitdem nicht neu gestartet. Die
Bridge-Seite sagt dir, welches von beidem es ist.

### Slash-Befehle sagen „Die Anwendung reagiert nicht"

Deine Adresse ist aus dem Internet nicht erreichbar. Siehe
[Discord](#discord-anbinden) — Meldungen und Chat funktionieren weiter,
und die Discord-Seite meldet beide Fähigkeiten getrennt.

### Eine Einstellung sagt „Neustart nötig"

Weil es stimmt. Die Werte der Server-INI liest das Spiel im laufenden
Betrieb neu ein, die Sandbox-Werte aber nur beim Start. Das Panel hat das
gemessen statt es anzunehmen, und sagt dir, welche der beiden Sorten du
gerade änderst.

### Ich komme nicht mehr rein

Siehe [Das erste Mal anmelden](#das-erste-mal-anmelden) — der Befehl
`app:user:create` setzt auch ein bestehendes Passwort zurück.

---

## Alle Einstellungen auf einen Blick

### Die Adresse — mehr braucht es nicht

| Variable         | Was sie ist                                                    |
| ---------------- | ---------------------------------------------------------------- |
| `APP_PUBLIC_URL` | die Adresse, die man eintippt, mit `https://`                    |
| `COOLIFY_URL`    | setzt Coolify auf deine Domain — wird genommen, wenn `APP_PUBLIC_URL` leer ist, du musst dort also nichts eintragen |

### Wird für dich erzeugt

Bleiben sie leer, entstehen sie beim ersten Start und liegen in einem
Docker-Volume. Setz sie nur, wenn du eine bestehende Installation auf
einen neuen Server umziehst.

| Variable                        | Was sie ist                                        |
| ------------------------------- | ---------------------------------------------------- |
| `APP_SECRET`                    | der Signierschlüssel von Symfony                    |
| `CREDENTIALS_ENCRYPTION_KEY`    | verschlüsselt die FTP- und RCON-Passwörter          |
| `POSTGRES_PASSWORD`             | selbst setzen, oder erzeugen lassen                 |
| `DATABASE_URL`                  | setzen, um eine externe PostgreSQL zu benutzen      |
| `POSTGRES_DB` / `POSTGRES_USER` | Name und Benutzer der Datenbank; sonst `zomboid`    |

### Anmeldung

Alle drei werden aus `APP_PUBLIC_URL` abgeleitet. Setz sie nur, wenn das
Panel unter einem anderen Namen antwortet als dem, den man eintippt.

| Variable                      | Standard                     | Was sie ist                             |
| ----------------------------- | ---------------------------- | --------------------------------------- |
| `SERVER_NAME`                 | der Host aus APP_PUBLIC_URL  | der Domainname des Webservers           |
| `WEBAUTHN_RELYING_PARTY_ID`   | der Host aus APP_PUBLIC_URL  | die nackte Domain für Passkeys          |
| `WEBAUTHN_RELYING_PARTY_NAME` | `ZomboidControl`             | wie die Passkey-Abfrage das Panel nennt |

Die Google-Anmeldung richtest du im Panel ein, unter *Einstellungen → Google*.

### E-Mail

Wird im Panel eingerichtet, unter *Einstellungen → E-Mail* — mit Vorlagen
für die gängigen Anbieter, einem Testversand und einer Prüfung deiner
SPF-, DKIM- und DMARC-Einträge. Hier ist nichts zu setzen.

### Größe und Leistung

| Variable                   | Standard | Was sie tut                                     |
| -------------------------- | -------- | ------------------------------------------------ |
| `APP_PORT`                 | `8080`   | der veröffentlichte Port, nur mit `docker-compose.local.yaml` |
| `MESSENGER_WORKERS`        | `1`      | Hintergrundarbeiter; höher bei vielen Servern    |
| `PHP_FPM_API_MAX_CHILDREN` | `12`     | gleichzeitige Anfragen                           |
| `PHP_FPM_SSE_MAX_CHILDREN` | `8`      | gleichzeitige Dauerverbindungen                  |

### Sonstiges

| Variable      | Was sie ist                                         |
| ------------- | ---------------------------------------------------- |
| `APP_VERSION` | welche Version laufen soll; ohne Angabe die neueste |

Den Steam-Schlüssel trägst du im Panel ein, unter *Einstellungen → Steam*.

---

## Was das Panel bewusst nicht kann

Das Panel läuft woanders als dein Spielserver. Das ist so gewollt, und
daraus folgen ein paar Dinge, die andere Panels anbieten:

- **Keine Anzeige von Prozessor, Arbeitsspeicher oder Festplatte des
  Spielservers.** Das Panel würde seinen eigenen Container messen, nicht
  deinen Spielserver.
- **Es kann einen gestoppten Server nicht starten.** RCON braucht einen
  laufenden Server, um einen Befehl anzunehmen — Stoppen ist deshalb eine
  Einbahnstraße. Das Panel sagt das, statt einen Knopf anzubieten, der
  nicht funktionieren kann.
- **Keine Einstellungen für Port oder HTTPS des Panels selbst.** Darum
  kümmert sich Docker beziehungsweise Coolify.

Was es stattdessen tut: alles, was über Dateien und RCON erreichbar ist —
und mit der Bridge ist das der weitaus größte Teil des Spiels.

---

## Spielinhalte und Lizenz

Das Panel zeigt Fahrzeugmodelle, Texturen, Bodenkacheln und
Gegenstandssymbole aus Project Zomboid. Diese gehören © The Indie Stone
Ltd und werden unter deren Bedingungen verwendet, die das für ein
nicht-kommerzielles Fan-Projekt **unter der Bedingung eines sichtbaren
Hinweises** erlauben — die Seite `/app/credits` im Panel. Deshalb gibt es
sie, und deshalb ist sie von überall verlinkt.

**In diesem Repository liegt keine Spielgrafik.** Sie wird aus deiner
eigenen Installation ausgelesen. Inhalte von Mods gehören ihren Autoren
und brauchen deren Erlaubnis gesondert.

Der Code des Panels steht unter der [MIT-Lizenz](LICENSE); die
Spielinhalte nicht — die Lizenzdatei sagt das ausdrücklich. ZomboidControl
ist kein offizielles Produkt von The Indie Stone und wird von dort weder
unterstützt noch empfohlen.

---

## Für Entwickler

Die Entwicklungsumgebung läuft mit [ddev](https://ddev.readthedocs.io/):

```bash
ddev start && ddev setup
ddev dev                       # Frontend mit Hot Reload
ddev exec -d /var/www/html/backend "php bin/phpunit"
```

`CLAUDE.md` hält die Konventionen des Projekts fest, `CONTEXT.md` ist die
vollständige Entwicklungsgeschichte: was gebaut ist, was nachgewiesen
wurde, und warum Entscheidungen so getroffen wurden. Beide sind auf
Englisch, wie der gesamte Code.
