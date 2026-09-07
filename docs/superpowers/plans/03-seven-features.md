# Sieben Vorhaben: Planung vom 2026-09-07

*Vollständig mit dem Nutzer durchgesprochen. Jede Entscheidung, jeder
belegte Fakt und jede Abbruchbedingung steht hier. Nach einem
`/compact` ist dies die Arbeitsgrundlage — zusammen mit `CONTEXT.md`.*

---

*Arbeitsstand der Planung. Entscheidungen werden hier festgehalten und am
Ende nach `CONTEXT.md` übernommen.*

## Kontext

Das Panel steuert heute Spieler, Welt, Wetter, Items und Fahrzeuge. Was
fehlt, sind sieben Bereiche, die der Betreiber eines Servers täglich
braucht — und die bisher nur deshalb offen sind, weil jeder von ihnen
zuerst eine Umfangsentscheidung braucht.

Der Nutzer hat sie benannt: Discord, Steam-Workshop, Konfigurationseditor,
Zeitplaner, Statistiken, Benachrichtigungsglocke, Avatare.

## Entschieden (mit dem Nutzer, 2026-09-07)

| Frage | Entscheidung |
|---|---|
| **Reihenfolge** | **Fundament zuerst** — siehe unten |
| **Sandbox-Bedienung** | **Gruppiert mit Suche**, Typ je Wert, kein Rohtext-Editor als Standard |
| **Discord-Umfang** | **Voller Bot** mit Gateway: Slash-Befehle, Chat in beide Richtungen. Braucht einen zweiten Prozess, den der Nutzer in Coolify einrichtet |
| **Discord-Anspruch** | Ausdrücklich **deutlich mehr als die Referenz** |
| **Referenz** | Nur Inspiration. Nie 1:1 übernehmen |

### Die Reihenfolge und ihr Grund

1. **Statistik-Sammeltabelle** — muss zuerst anfangen zu sammeln, sonst
   gibt es später nichts zu zeigen. `PlayerSnapshot` überschreibt.
2. **Server-Konfigurationseditor** — INI und Sandbox über FTP.
3. **Mod-Verwaltung** — baut auf dem INI-Zugriff aus 2 auf.
4. **Ereignisstrom** — das Fundament, das Glocke *und* Discord brauchen.
   Zweimal gebaut wäre es zweimal gepflegt.
5. **Benachrichtigungsglocke** — die kleine Nutzung des Stroms.
6. **Discord** — die große Nutzung desselben Stroms.
7. **Zeitplaner**, **Avatare**, **Diagramme** — unabhängig, passen dazwischen.

## Belegte Fakten aus der Spielinstallation

Aus `/Volumes/ESD-USB/ProjectZomboid`, mit `javap` und den Spieldateien
geprüft — nicht geraten.

### Der Sandbox-Editor lässt sich vollständig ableiten

**Das entscheidet den Aufwand des Editors.** Das Spiel trägt jede Option
typisiert und mit allen Metadaten:

| Quelle | Was sie liefert |
|---|---|
| `media/lua/shared/Sandbox/Apocalypse.lua` | **274 Werte** mit Namen und Standardwert (nicht 183, wie früher notiert) |
| `zombie.SandboxOptions` | je Option ein Feld vom Typ `EnumSandboxOption`, `BooleanSandboxOption`, `DoubleSandboxOption`, `IntegerSandboxOption` |
| `SandboxOption` (Interface) | `getShortName()`, `getTranslatedName()`, `getTooltip()`, **`getPageName()`** (die Gruppierung!), `getTableName()` |
| `DoubleConfigOption` / `IntegerConfigOption` | **`getMin()`, `getMax()`** |
| `EnumConfigOption` | **`getNumValues()`** |
| `Translate/DE/Sandbox.json` | **1082 Einträge**: Namen, **jede Auswahloption** (`Sandbox_ActiveOnly_option1` = „Beides"), und **266 Erklärungstexte** |

Also: Name, Typ, Grenzen, Auswahllabels, Erklärung und Gruppe kommen
alle aus dem Spiel. Dieselbe Methode wie bei `CharacterDefinitions.php`
und der Fertigkeitstabelle.

### Die INI ist genauso ableitbar — 144 Optionen

`zombie.network.ServerOptions` trägt dieselbe Struktur wie
`SandboxOptions`, mit `getTooltip()` und `asConfigOption()`:

| Typ | Anzahl |
|---|---|
| `BooleanServerOption` | 73 |
| `IntegerServerOption` | 29 |
| `StringServerOption` | 22 |
| `EnumServerOption` | 11 |
| `DoubleServerOption` | 7 |
| `TextServerOption` (mehrzeilig) | 2 |

**Zusammen also 274 Sandbox- und 144 INI-Werte, jeweils mit Typ,
Grenzen und Erklärung aus dem Spiel.** Kein Wert wird getippt.

### Drei INI-Werte verdienen einen eigenen Auswähler

Vom Nutzer angemerkt: die **Startitems** sollen über ein Modal mit
Item-Auswahl gesetzt werden, nicht als Text — plus **Vorlagen**.

Im Spiel gefunden, alle drei `StringServerOption`:

| Option | Was sie hält | Was das Panel schon hat |
|---|---|---|
| **`spawnItems`** | die Startitems eines Spielers | **den Item-Auswähler mit Icons und Suche** (`features/items/`) |
| `workshopItems` | die Mod-Liste — **derselbe Wert, den die Mod-Verwaltung schreibt** | (kommt mit Schritt 3) |
| `spawnPoint` | der Startpunkt | die Karte |

Damit ist `spawnItems` kein Textfeld, sondern ein Modal über den
bestehenden Auswähler — Regel 7: *„Type nothing you could click"* und
*„Pick things by sight where they have a look."* **Vorlagen** kommen
dazu, nach dem Vorbild von `MAIL_PRESETS` in `settings.ts:74-90`, das
genau diese Form schon hat.

Wichtig: `workshopItems` gehört **derselben Datei** wie die
Mod-Verwaltung. Die zwei dürfen sich nicht überschreiben — ein Grund
mehr, den INI-Schreibpfad einmal richtig zu bauen (Schritt 2) und die
Mod-Verwaltung darauf zu setzen (Schritt 3).

### Die Dateinamen hängen vom Servernamen ab

**Vom Nutzer angemerkt, und es verhindert einen echten Fehler:**
`servertest.ini` und `servertest_SandboxVars.lua` heißen nur so, wenn der
Server „servertest" heißt. Auf einem echten Server heißen sie anders.

Folge für den Plan: die Dateien werden **gefunden**, nie geraten — über
das Verzeichnis, das die Bridge schon kennt, und nach Muster
(`*.ini`, `*_SandboxVars.lua`). Das gilt für den Editor **und** für die
Mod-Verwaltung, die in dieselbe INI schreibt.

## Belegte Fakten aus dem eigenen Code

Aus der Erkundung, jede Angabe mit Datei:Zeile geprüft.

### Der Zeitplaner ist fast fertig — mit einer Lücke, die alles blockiert

`symfony/scheduler` ist installiert (`backend/composer.json:36`) und läuft
**produktiv**: `docker/supervisord.conf:44-51` konsumiert
`scheduler_main`. `backend/src/Scheduler/MainSchedule.php` hat zwei
Aufgaben (Bans aufheben minütlich, Spieler aufräumen täglich).

**Aber: `.ddev/config.yaml:313-317` startet nur den `async`-Konsumenten,
nicht `scheduler_main`.** Lokal feuert also keine geplante Aufgabe. Das
ist der erste Schritt, sonst ist keine Zeitplaner-Funktion prüfbar — und
nach Regel 6b muss alles im Browser prüfbar sein.

Zwei Altlasten dabei: `backend/src/Schedule.php` ist unveränderter
Flex-Rumpf ohne Aufgaben und ohne `declare(strict_types=1)` — toter Code.
Und `MainSchedule` ist **nicht** `stateful()`, verpasste Läufe werden
nach einem Neustart also nicht nachgeholt.

### Statistiken: eine Zeitreihe existiert schon

**Korrektur einer früheren Notiz.** `PlayerSnapshot` überschreibt
(`PlayerSnapshot.php:12-14`, eine Zeile je Spieler) — aber
`moderation_action` ist **anfügend**, und `RosterWatcher.php:41,45`
schreibt bei **jedem Beitritt und Abgang** eine Zeile mit
`performedAt`.

Also **heute zeichenbar, ohne neue Tabelle**:
- Spieler über Zeit (aus `join`/`leave`)
- Admin-Aktionen je Tag und Art
- Kills und Überlebenszeit je Spieler (aktueller Stand, Balkendiagramm)

**Neue Tabelle nur nötig für**: Kills *im Verlauf*, weil
`PlayerSnapshot.zombieKills` den Wert überschreibt.

Zwei Fallstricke: `moderation_action` hat **keinen Index auf
`performedAt`** (nur `(server_id, username)`, `ModerationAction.php:20`) —
eine Tagesgruppierung liest sonst die ganze Tabelle. Und
`BridgeStatusReader` hat `MIN_SECONDS_BETWEEN_READS = 2` plus Cache, die
Abtastrate ist also **nicht gleichmäßig**.

### Diagramme: Farben liegen bereit, Bibliothek fehlt

`--chart-1` bis `--chart-5` sind in **beiden** Themen definiert
(`frontend/src/index.css:29-33` und `:74-78`) und als Tailwind-Klassen
verfügbar (`:124-128`). `chart-1` ist absichtlich gleich `--primary`.
Keine Diagramm-Bibliothek in `package.json`, kein `chart.tsx`.

### Avatare: die Daten werden geholt und weggeworfen

- `SteamProfileFetcher.php:55` liest **nur** `personaname` aus einer
  Antwort, die `avatarfull` im selben Objekt trägt. Ein Feld mehr, **kein
  zusätzlicher Aufruf**.
- `GoogleAuthenticator.php:103-104` liest `getId()` und `getName()`;
  `getAvatar()` (der `picture`-Claim) bleibt ungenutzt.
- Kein `avatar`-Feld auf `User` oder `OAuthIdentity`.
- `AvatarImage` existiert in `components/ui/avatar.tsx:25`, wird **nirgends
  benutzt** — die Sidebar zeigt nur Initialen (`app-sidebar.tsx:288-290`).
- `UserPayload.php:19-34` ist die eine Stelle, an der eine Avatar-URL
  auftauchen müsste.

**Was fehlt**: jede Skalierung. Es gibt nur `imagecopy`
(`IconExtractor.php:119`), kein `imagecopyresampled`, kein WebP oder
AVIF, und **keine Prüfung, welche Formate GD hier schreiben kann**. GD
ist außerdem in `composer.json` nicht als Anforderung deklariert, obwohl
es benutzt wird. Zuschneiden ist also komplett neu.

Für die Auslieferung gilt der Fahrzeugmodell-Fall als Vorbild, nicht der
Icon-Fall: `VehicleModelController.php:211` setzt bewusst `setPrivate()`,
damit ein geteilter Zwischenspeicher nichts für das nächste Konto behält.
Ein Avatar ist personenbezogen — also `private`.

### Upload-Wege, die wir nachnutzen

Drei existieren. Der brauchbarste für Avatare ist der Fahrzeugmodell-Weg
(`VehicleModelController.php:153-176`): Endungsliste plus
**Größengrenze** (8 MB, die einzige im Backend). Zu ergänzen wäre eine
Prüfung der echten Bildabmessungen — `getimagesize` kommt nirgends vor,
und ein „Dekompressionsbombe"-Bild ist klein auf Platte und riesig im
Speicher.

`apiFetch` behandelt `FormData` schon korrekt, **inklusive CSRF**
(`api.ts:39-51`). `DropZone` gibt es als Bauteil.
Keine Zuschneide-Bibliothek in `package.json`.

### Der Dateizugriff kann schreiben — hat aber keine Schutzvorrichtungen

`FileBrowserInterface` (`backend/src/Server/Storage/FileBrowserInterface.php`)
hat sieben Methoden, darunter `upload()` und `download()`. **Es fehlen
`delete`, `rename`, `mkdir` und jedes atomare Schreiben.**

Was `upload()` heute *nicht* hat (`ServerFileBrowser.php:161-168`):
keine Größengrenze, **keine Sicherung vor dem Überschreiben**, keine
Sperre, keine Prüfung danach, keine Reihung gleichzeitiger Schreibvorgänge.
Für die Bridge genügt das — eine 274-Werte-Datei zu überschreiben ist eine
andere Größenordnung.

Zwei Fallstricke im Bestand:
- **`readTail` liest vom Dateiende.** Für eine INI muss `$maxBytes`
  größer als die Datei sein, wie `BridgeInstaller.php:69` es mit 262144
  vormacht — sonst fehlt der Anfang.
- `MAX_ENTRIES = 500` schneidet Verzeichnislisten **stillschweigend** ab
  (`ServerFileBrowser.php:21,47`). Ein `mods/`-Ordner mit mehr Einträgen
  wäre unvollständig, ohne dass es jemand merkt.
- Ein echter Fehler: `ServerFileBrowser.php:116` vertauscht die
  Argumente von `StorageException` — die menschliche Meldung landet dort,
  wo der Übersetzungsschlüssel erwartet wird.

`FtpConfig` kennt `basePath`, `luaServerPath` und `logPath` — **keinen
Pfad zum `Server/`-Verzeichnis** und keinen Servernamen. Beides braucht
der Editor, und beides passt als weiteres Feld nach demselben Muster.

### Die Referenz hat genau das, was uns fehlt

Sie ist eine eigenständige Node/React-Anwendung, und sie hat beide
Vorhaben schon gelöst. **Als Inspiration, nie als Kopie** — aber zwei
Dinge sind Gold:

**1. Ein Generator-Skript.** `client/scripts/extract-pz-sandbox-ground-truth.mjs`
erzeugt die Sandbox-Tabelle aus dem Spiel, und
`client/src/lib/__fixtures__/pzSandboxGroundTruth.json` (4676 Zeilen) ist
die geprüfte Vergleichsdatei dazu. Zusammen mit meinem `javap`-Befund
heißt das: **wir erzeugen die Tabelle und prüfen sie gegen eine zweite
Quelle**, statt 274 Werte zu tippen.

Ihre Feldbeschreibung (`serverConfigSchema.ts:6-20`) ist die richtige
Form: `key, label, description, type, options, min, max, default,
category`. Und ein Feld, das ich nicht erfunden hätte:
**`defaultComparable`** — falsch, wenn das Spiel den Wert zur Laufzeit
selbst erzeugt statt die Vorlage zu nehmen.

**2. Ein Katalog teuer bezahlter Fehler** in ihrem Änderungsprotokoll,
jeder genau dort, wo wir hin wollen:

| Fehler | Folge |
|---|---|
| Text in Anführungszeichen **mit Komma** in `SandboxVars.lua` beim ersten Komma abgeschnitten | Datei beschädigt, **Server startete nicht mehr** |
| Verschachtelte `Music`/`Debug`-Tabellen auf oberste Ebene abgeflacht | Werte verloren |
| Nicht zusammenpassende Klammern | brauchte Erkennung **und** geprüfte Reparatur |
| **`reloadoptions` wendet Sandbox-Werte nie an** — es liest nur die Haupt-INI | Panel muss „Neustart nötig" sagen, nicht „übernommen" |
| Java-Option geschrieben, ohne die `SandboxVars`-Tabelle zu erneuern | Mod-Code liest die Tabelle, sah den alten Wert |
| Mod entfernen berührt nur `Mods` | **`WorkshopItems` und `Map` müssen mit** |
| Gegen eigenen Merkzustand verglichen statt gegen `WorkshopItems=` | Abweichung unentdeckt |

Der vierte Punkt ist der wichtigste für die Bedienung — und es ist
genau die Unterscheidung, die `BridgeVersionVerdict.php` für die Bridge
schon trifft: **Datei auf Platte ≠ laufender Zustand.** Dieselbe Denkweise
gehört in den Sandbox-Editor.

Ihre Schutzvorrichtungen fürs Schreiben (`server/services/remoteConfigFiles.js`)
sind ebenfalls übernehmenswert — als Idee, neu geschrieben: Namensmuster,
Endungsliste (`.ini`, `.lua`), 8-MB-Grenze, Listengrenze, Pfadlängengrenze,
und „ein Lesen darf einen frischen Spiegel nachnutzen, ein **Schreiben zieht
immer neu**".

### Was wir für den Workshop noch nicht haben

Ein Steam-API-Schlüssel ist schon konfigurierbar
(`AppSetting::STEAM_API_KEY`, prüfbar über
`POST /settings/steam/test`). **Aber es gibt keinen Workshop-Client** —
kein `GetPublishedFileDetails` im ganzen Backend. Das ist neu zu bauen.

### Berechtigungen und Navigation

18 Berechtigungen existieren, **keine für einen Konfigurationseditor**.
`EditServers` wäre missbraucht: sein Docblock sagt, es bedeutet „die
FTP- und RCON-Zugangsdaten sehen", und es gilt als sensibel. Wer
Sandbox-Werte ändern darf, müsste dann auch Zugangsdaten sehen.

**Vorschlag: zwei neue Fälle**, `servers.config` und `servers.mods`, in
die Gruppe `servers`. Zu beachten: `PermissionVoter::legacyRoleGrants`
gibt einem `ROLE_SERVER_ADMIN` **automatisch alles außer drei** — ein
neuer Fall wäre also sofort erteilt. Wahrscheinlich richtig, aber eine
bewusste Entscheidung.

Die Navigation hat vier Abschnitte (`live`, `world`, `content`,
`diagnostics`) und **keinen für Konfiguration**.

### Die bewährten Oberflächenmuster

Für 274 Werte gibt es drei Vorbilder im Haus, und `react-hook-form` ist
**nicht** das Hausmuster (nur kleine Anmeldeformulare nutzen es):

| Datei | Was übernehmenswert ist |
|---|---|
| `servers/server-detail-page.tsx` | Reiterzustand **in der URL**, Entwurfsmuster (`draft`), und: ein fehlgeschlagener Test verwirft nie die Eingabe |
| `settings/settings-page.tsx` | senkrechte Reiter, `SETTING_KEYS` als Konstantenobjekt, Speichern je Reiter |
| `events/climate-page.tsx` | **das dichteste Wertegitter**: Zeile für Zeile eigener Bearbeitungszustand als `{from, to}`, je Wert eigenes Anwenden und Zurücknehmen |

`@tanstack/react-virtual` ist **schon installiert** — für 274 Zeilen
wichtig.

### Mods erweitern die Sandbox-Werte — vom Nutzer angemerkt

**Das entscheidet die Architektur des Editors.** Das Spiel rechnet damit:
`SandboxVars.lua:4` ruft `getSandboxOptions():initSandboxVars()`, und
jede Option trägt `setCustom()` / `isCustom()`. Mod-Werte sind also
ausdrücklich vorgesehen.

Folge: **Die Datei ist die Wahrheit, die Tabelle nur die Beschreibung.**
Was in der Datei steht und die Tabelle nicht kennt, wird trotzdem
angezeigt — mit Namen, Wert und dem Hinweis „von einem Mod". Genau wie
bei den Fertigkeiten, wo eine Mod-Fertigkeit sichtbar bleibt statt
stillschweigend zu verschwinden.

Ein Editor, der nur die 274 bekannten Werte zeigt, würde Mod-Werte beim
Speichern **löschen**. Das ist die eigentliche Gefahr.

### Zwei Fallstricke, in der Standarddatei nachweisbar

**1. Verschachtelte Tabellen.** `Apocalypse.lua` hat sechs davon —
`Basement`, `Map`, `ZombieLore`, `ZombieConfig`, `MultiplierConfig`
(Zeilen 185–280). Ein Mod legt seine Werte typischerweise genauso ab.
Die Referenz hat sie plattgemacht und dabei Werte verloren; der Parser
muss **verschachtelt** arbeiten.

**2. Text mit Kommas.** `Apocalypse.lua:71`:

```
WorldItemRemovalList = "Base.Hat, Base.Glasses, Base.Maggots, …",
```

Neun Kommas **innerhalb** der Anführungszeichen. Am Komma zu trennen
schneidet nach `Base.Hat` ab — genau der Fehler, der die Referenz einen
nicht startenden Server gekostet hat. **Der Fall steht in der
Standardvorlage**, ist also kein Randfall.

### Neuladen ohne Neustart — im Bytecode geklärt, eine Messfrage bleibt

Vom Nutzer gefragt, und er kannte die Befehle nicht auswendig — musste er
auch nicht, das Spiel weiß es. Drei Befehle existieren, und sie tun
**verschiedene** Dinge:

**`reloadoptions`** (`ReloadOptionsCommand`) — die Referenz hatte recht,
es fasst **die Sandbox-Werte nicht an**. Kein `SandboxOptions` im ganzen
Aufruf. Was es aber wirklich leistet, ist mehr als erwartet:

| Aufruf | Wirkung |
|---|---|
| `ServerOptions.init()` | liest die **Haupt-INI** neu |
| `GameServer.sendOptionsToClients()` | schickt sie an alle Verbundenen |
| `ZombiePopulationManager.onConfigReloaded()` | Zombie-Verwaltung übernimmt |
| `SafetySystemManager.updateOptions()` | PvP-Einstellungen |
| `UdpEngine.SetServerPassword(...)` | Passwort sofort wirksam |
| `initClientCommandFilter()`, `setupSteamGameServer()` | |

**Für die INI ist `reloadoptions` also genau richtig** — und mehr als
„nur neu lesen": die Änderung erreicht die verbundenen Clients.

**`reloadlua <datei>`** (`ReloadLuaCommand`) und **`reloadluaall`**
(`ReloadAllLuaCommand`) rufen beide
`LuaManager.RunLua(pfad, boolean)` — die Datei wird **neu ausgeführt**.
Antworten: `"Lua file reloaded"` bzw. `"Unknown Lua file"`.

**Das ist der aussichtsreiche Weg für die Sandbox**, denn
`SandboxVars.lua` tut nichts anderes als eine Tabelle zuzuweisen und
`getSandboxOptions():initSandboxVars()` zu rufen. Ein Neuausführen würde
also genau das Richtige tun — *wenn* die serverspezifische Datei in der
Liste steht, aus der `RunLua` einen Pfad auflöst. Sie liegt nicht unter
`media/lua`, daher: **offen.**

Das ist eine **Messfrage, keine Lesefrage.** Der Ablauf beim Bau:

1. Wert über FTP in die Sandbox-Datei schreiben
2. `reloadlua <datei>` über RCON — die Antwort unterscheidet schon
   „reloaded" von „Unknown Lua file"
3. Über die **Bridge** zurücklesen (`getSandboxOptions():getOptionByName()`),
   was der laufende Server jetzt hält
4. Zusätzlich prüfen, ob die **`SandboxVars`-Lua-Tabelle** mitgezogen ist
   — die Referenz warnt genau vor dem halben Weg: Java-Option
   geschrieben, Tabelle veraltet, und Mod-Code liest die Tabelle

**Bis das belegt ist, sagt die Oberfläche „Neustart nötig".** Dieselbe
Ehrlichkeit wie `BridgeVersionVerdict`: Datei auf Platte ≠ laufender
Zustand. Und weil die Bridge zurücklesen kann, ist der Zustand **belegbar**
statt behauptet — besser als die Referenz, die es nur vermuten kann.

### Discord: was existiert und was fehlt

**Der Chat liest Discord-Nachrichten heute schon** —
`ChatLine.php:63` erkennt `Got message '…' by author '…' from discord`.
Aber: **nichts merkt sich die Herkunft**, eine Discord-Nachricht sieht im
Panel wie eine Spielnachricht aus.

**Die größte Lücke für eine Brücke:** `ChatLine` erfasst den **Kanal
nicht**. Die Zeile enthält `chat=UI_chat_main_tab_title_id`
(dokumentiert in `ChatLine.php:14-16`), das Muster in `:83` greift nur
`author` und `text`. Ohne den Kanal kann die Brücke nicht entscheiden,
was hinübergeht — und die Referenz warnt zu Recht, dass
Nahbereichs-Chat nach Discord zu spiegeln ein Datenschutzfehler ist.
Das `raw`-Feld bewahrt die Zeile, der Kanal ist also nachrüstbar.

`ChatBroadcaster::sanitise()` ist **statisch und rein**, für den Weg
Discord→Spiel direkt nutzbar. Es entfernt aber **kein** Discord-Markup
und keine Erwähnungen — das kommt hinzu.

**Der Ereignisstrom hat seinen Anschlusspunkt schon**, und der Docblock
von `ModerationRecorder.php:12-27` sagt es wörtlich: *„a notification
feed will later want to see every one of them go past"*. 18 Aktionsarten,
`inputs` und `failed` sind da.

Zwei Fallstricke: `add()` schreibt **ohne** `flush()` (Stapelbetrieb) —
ein Versand-Haken darf die Zeile nicht als festgeschrieben annehmen. Und
`username` ist **nicht immer ein Spieler**: es hält auch einen
Aktionsnamen, ein Befehlsverb oder eine Konstante.

**Was fehlt**: keine Discord-Anbindung, kein Webhook, kein
HTTP-Client-Profil (`http_client.yaml` gibt es nicht), keine
Berechtigung, keine Glocke, kein `Notification`-Modell, **kein
serverbezogener Einstellungsspeicher** (nur `GameServer` +
`FtpConfig` + `RconConfig`).

**Der Bot-Token passt perfekt in ein bestehendes Muster**:
`AppSetting::SECRET_KEYS` plus `CredentialCipher` (libsodium,
umkehrbar), und `credential-field.tsx` zeigt schon „konfiguriert" ohne
den Wert zurückzugeben. Wichtig dabei: ein leeres Feld heißt „unverändert",
also darf das Frontend den Schlüssel dann **nicht mitsenden** — die
PATCH-Route deutet `''` als Löschen.

**Solange alles serverseitig bleibt, ändert sich die CSP nicht.**
Erst ein Discord-Avatar im Browser bräuchte `img-src`.

### Die Glocke: alles muss abgefragt werden

**Kein SSE, kein WebSocket** — nur ein Kommentar in
`docker/apache-vhost.conf:5`, dass lange Verbindungen je einen
FPM-Arbeiter belegen. Alles im Panel fragt ab, im Takt von 3 bis 60
Sekunden. Die Glocke fügt sich ein.

Das Vorbild steht schon im Kopfbalken: `AppUpdateBanner` erscheint nur,
wenn es etwas zu sagen gibt, und sein Docblock trifft genau den Ton:
*„nichts ist kaputt, und einen Betreiber mitten in der Arbeit zu
unterbrechen, um gute Nachrichten zu verkünden, ist unhöflich."*
Toasts sind flüchtig und überleben kein Neuladen — für „ungelesen"
gibt es keine Grundlage, die braucht eine Tabelle.

Einfügepunkt: `app-layout.tsx:29`, die dreiteilige Gruppe rechts.

### Kleine Funde, die Zeit sparen

- **`settings-tabs.ts` existiert nicht** — die Reiter stehen als JSX in
  `settings-page.tsx`, und der Test liest die **Quelldatei** mit
  `/TabsTrigger value="([a-z]+)"/`. Ein Reiter namens `discordBot` wäre
  für den Test unsichtbar; er muss `discord` heißen und in einer Zeile
  in `SAVABLE_TABS`.
- **`src/Schedule.php` ist toter Flex-Rumpf** (kein `strict_types`, keine
  Aufgaben) und sein Transport wird von niemandem konsumiert. Neue
  Aufgaben gehören in `MainSchedule`.
- Der Zwischenspeicher liegt im Dateisystem — ein Discord-Ratenlimit
  darin wäre **je Container**, nicht geteilt.
- `RconClientInterface` ist für Tests als `public` freigegeben
  (`services.yaml:123-130`) — ein `DiscordClientInterface` sollte
  dieselbe Form haben, sonst ist er nicht prüfbar.

## Was die Referenz gelehrt hat

Sie hat all das gebaut und dabei Fehler bezahlt, die in ihrem
Änderungsprotokoll und ihren Kommentaren stehen. **Als Inspiration, nie
als Kopie** — aber diese Erkenntnisse übernehmen wir.

### Die Gruppierung: Sandbox kommt aus dem Spiel, INI ist Handarbeit

**Vom Nutzer verlangt: „Einstellungen die zusammen gehören sollten auch
zusammen bleiben."**

- **Sandbox**: `getPageName()` liefert die Gruppe aus dem Spiel, dazu die
  `section` aus den verschachtelten Tabellen. Kommt geschenkt.
- **INI**: `ServerOption` hat **nur** `getTooltip()` — **keine
  Gruppierung.** Das ist bewusste Handarbeit. Die Referenz hat **20
  Kategorien in 5 Obergruppen** (Identität, Verbindung, Spieler,
  Spielgeschehen, Betrieb) — eine durchdachte Ordnung als Ausgangspunkt,
  die wir selbst prüfen und anpassen.

Ihre Zahlen bestätigen meine Messung: **269 Sandbox-Werte, davon 183 im
Hauptbereich** plus 86 in `MultiplierConfig` (37), `ZombieLore` (29),
`ZombieConfig` (15), `Map` (4), `Basement` (1). Die früher notierten
183 waren also richtig — für den Hauptbereich.

### Der Extraktor ist der Gewinn

`extract-pz-sandbox-ground-truth.mjs` (349 Zeilen) beweist: **Namen,
Standardwerte, Auswahlwerte und Auswahlbeschriftungen in sechs Sprachen
sind mechanisch ableitbar.** Wir schreiben das in PHP nach.

**Was ableitbar ist**: alles oben, plus über `javap` zusätzlich Typ,
`getMin()`, `getMax()` und `getPageName()` — die Referenz hat die
Grenzen **von Hand getippt**, wir bekommen sie aus der Klasse. Da sind
wir besser dran.

**Was Handarbeit bleibt**: Beschreibungen (über die Erklärungstexte des
Spiels, 266 auf Deutsch) und die INI-Kategorien.

Zwei Techniken übernehmen wir:
- **Herkunftsstempel mit `buildid`** (aus `appmanifest_108600.acf`) und
  eine Vergleichsdatei als Prüfschranke — genau wie unsere
  `climate-api.json`.
- **Die Spielübersetzung als Beschriftung**, aber **nur für Namen und
  Auswahloptionen, nie für Beschreibungen**: die Erklärungen des Spiels
  sind für seinen eigenen Bildschirm geschrieben, unsere für dieses Panel.

### Zwölf Erkenntnisse, die wir übernehmen

**Schreiben von Konfigurationsdateien**

1. **Zeilenenden erhalten.** Jedes Speichern hat still CRLF zu LF
   gemacht, bis sie es merkten.
2. **Nur den Wert ersetzen**, nicht die Zeile neu bauen — sonst
   verschwindet das handgeschriebene `PVP = true` beim ersten Speichern
   irgendeines Feldes.
3. **Nach dem Schreiben zurücklesen und je Schlüssel vergleichen.** Wir
   hatten das schon entschieden; sie belegen, dass es nötig ist.
4. **Unbekannte Zeilen unangetastet lassen** — das ist der Mod-Schutz,
   den der Nutzer verlangt hat.
5. **Doppelte Schlüssel verweigern** statt zu raten, und auf einen
   byte-treuen Rohtext-Editor verweisen.
6. **Ein unbekannter Auswahlwert wird nie zurechtgebogen** — stattdessen
   sagen: „Dieser Server steht auf X, was dieses Panel nicht kennt. Der
   Wert bleibt unverändert."

**Sicherungen**

7. **Drei Zustände, nicht zwei**: gesichert / nichts zu sichern (harmlos,
   erste Änderung) / **Sicherung fehlgeschlagen** (gefährlich). Beides
   letzte zu verwechseln führte dazu, dass eine Antwort eine Sicherung
   behauptete, die es nicht gab.
8. **Nach dem Dateinamen sortieren, nicht nach Zeitstempeln** — auf
   ext4 haben zeitgleiche Sicherungen identische Zeitstempel, und dann
   wird die gerade erzeugte als die älteste gelöscht.
9. **Zwei Härtegrade**: bei einer normalen Änderung warnt eine
   fehlgeschlagene Sicherung nur; bei einer **Reparatur** wird der
   Schreibvorgang **verweigert** — ein falsches Ergebnis hat dort keinen
   Rückweg.

**Laufender Server**

10. **Zwei Regeln, nicht eine**: das Überschreiben einer ganzen Datei
    (Sicherung zurückspielen, Vorlage anwenden) wird bei laufendem Server
    **blockiert**; eine gewöhnliche Änderung nur mit „Neustart nötig"
    versehen.
11. **„Kann nicht feststellen" gilt als „läuft"**, nicht als „gestoppt".

**Und die wichtigste, allgemeine**

12. **Ein Wert, der zwei Bedeutungen trägt, ist ein Fehler.** Sie fanden
    dieselbe Form **sechsmal**: Sicherung fehlt vs. fehlgeschlagen;
    Prüfung fehlgeschlagen vs. Server gestoppt; unerreichbar vs. entfernt;
    `undefined` für Erfolg und Fehler. Jede Behebung: den unklaren Fall
    zu einem **eigenen Zustand** machen und im Zweifel in die sichere
    Richtung irren.

Das ist bei uns bereits Hausregel — `failed` ist dreiwertig, `upToDate`
darf `null` sein, die Fähigkeiten kennen „unbekannt". **Es gehört als
ausdrückliche Prüfregel in CLAUDE.md.**

### Discord: die Schutzvorrichtungen, die zählen

Sie betreiben den Bot **im selben Prozess** — was unsere Entscheidung
(supervisord-Eintrag im Panel-Container) bestätigt.

Fünf Dinge, die wir übernehmen:

1. **`allowedMentions: { parse: [] }` global.** Textersetzung allein
   genügt nicht: `escapeMarkdown` fasst `<@&rolleId>` nicht an, ein
   Spielername `<@&adminRolle>` könnte Rollen anpingen. Der Bot muss
   niemals erwähnen — also global aus.
2. **Geheimnisse an der einen REST-Grenze unkenntlich machen**, per
   **exaktem Wertvergleich** gegen die Geheimnisse, die das Panel schon
   hält — nie per Mustererkennung. Und wenn die Suche selbst scheitert:
   **nicht senden**, statt unmaskiert zu senden.
3. **Kanal-Zulassungsliste statt Sperrliste.** Ihre drei Bereiche
   (öffentlich / ohne Rufen / nur Allgemein) und der Satz, der die Regel
   festhält: *„Fraktions-, Zufluchts-, Funk-, Admin- und Flüsterkanäle
   fehlen absichtlich: sie sind im Spiel privat und müssen in Discord
   privat bleiben."*
4. **Einfach-Durchlauf-Ersetzung mit Rückruffunktion** bei Vorlagen —
   sonst wird ein Wert mit `$1` als Rückverweis gelesen, und `{player}`
   überschreibt den Anfang von `{playerCount}`.
5. **Warnschwelle gegen Alarmmüdigkeit**: ein Zustand, der bei jedem
   2-Sekunden-Wiederverbinden umschaltet, erzieht den Betreiber zum
   Wegsehen — schlimmer als kein Signal.

**Wo wir deutlich weiter gehen** (vom Nutzer verlangt):

| Referenz | Wir |
|---|---|
| **Keine einzige Admin-Aktion** wird gemeldet | alle 18 Aktionsarten, **einzeln schaltbar, standardmäßig aus** |
| 7 fest verdrahtete Ereignisse, 9-armiges `switch`, 11 Aufrufstellen | ein **Ereignisstrom** über `ModerationRecorder` |
| Drei feste Rechtestufen | offen (zu entscheiden) |
| Vorlagen **nicht** maskiert — ein Spieler `**x**` schmuggelt Formatierung | maskiert, mit Vorschau |
| Nichts prüft, ob `{tokens}` zum Ereignis passen | ein unbekanntes Token bleibt wörtlich stehen, geprüft |
| Ein Gilden-Server durch Bauart | offen |
| Verlorene Meldung ist verloren | über Messenger mit Wiederholung |

Ein Detail, das wir übernehmen: **Rechteänderungen nur für Stufen prüfen,
die sich wirklich ändern** — die Oberfläche schickt oft alle mit. Und
jeden Discord-Befehl auf die **Panel-Berechtigung abbilden, die sein
HTTP-Zwilling braucht**: `/rcon` darf nicht billiger sein als die
Konsole.

### Zeitplaner: die stärkste Idee der Referenz

**Berechtigungsgleichheit**: eine Funktion bildet jeden geplanten Befehl
auf die Berechtigung ab, die sein interaktives Gegenstück verlangt —
geprüft **beim Anlegen und beim Ausführen**. Ihr Satz dazu:
*„etwas zu planen darf nicht weniger kosten, als es zu tun."*

Weiteres, was zählt:
- **Genau fünf Cron-Felder.** `node-cron` nimmt sechs (mit Sekunden) —
  das lehnen sie ab. Symfony Scheduler nimmt Cron-Ausdrücke ebenfalls.
- **Mindestabstand fünf Minuten**, berechnet über alle aufeinander
  folgenden Zeitpunkte **einschließlich Tageswechsel** — ihr erster
  Versuch prüfte nur `hour === "*"` und ließ „5:58 und 6:00" durch.
- **Zeitzone über `Intl.DateTimeFormat` prüfen**, nicht über die
  Aufzählung gültiger Zonen — die ist enger als das, was tatsächlich
  funktioniert. Eine Zone für die ganze Installation, nicht je Aufgabe.
  Bei ungültiger gespeicherter Zone: zurückfallen **und es sagen**.
- **Die Countdown-Tabelle**: `[{warten, zahl}]` mit Schlafen *vor* dem
  Signal, und Abbruchprüfung in **beiden** Schleifen.
- **Nur ASCII in Neustartmeldungen** — `servermsg` verstümmelt Emoji.
- Ein **fehlgeschlagenes Speichern bricht den Neustart ab.**

**Was wir anders machen**: ihr Aufgabentyp ist ein **freier Text**, per
Präfix erkannt — ein Tippfehler wird stillschweigend ein
Rohkonsolenbefehl. Wir nehmen einen **Aufzählungstyp mit Parametern**.
Und ihr Countdown lebt nur im Speicher: ein Panel-Neustart verliert ihn.

### Mods: was zu übernehmen ist

- **Ohne API-Schlüssel** geht `GetPublishedFileDetails` für Namen und
  Vorschaubilder. Der Schlüssel ist nur für **Textsuche** und
  **Abhängigkeiten** nötig — und ohne ihn führt ein Link zum Workshop.
- **Namen zuerst von der Platte** (`mod.info`, inklusive der B42-Ordner
  `common/`, `42/`, `42.0/`), dann gesammelt von Steam, 100 auf einmal.
- **Der wichtigste Fund: numerische IDs dürfen nie in `Mods=`.**
  Workshop-IDs (Zahlen) gehören in `WorkshopItems=`, Mod-IDs (Text) in
  `Mods=`. Sie prüfen das an **beiden** Enden und haben eine
  Selbstheilung für bestehende Verunreinigung.
- **Die Ladereihenfolge nie alphabetisch sortieren** — ihr Kommentar
  sagt „wäre aktiv schädlich". Nur verschieben, was eine erklärte
  Abhängigkeit erzwingt; sonst die Ordnung des Betreibers erhalten.
- **Ein Mod entfernen berührt `Mods=`, `WorkshopItems=` und `Map=`.**
- **Negativer Zwischenspeicher mit Ablauf** für fehlgeschlagene
  Bildabrufe: ohne ihn lief bei jedem Seitenaufruf der ganze Steam-Weg
  neu, für immer.
- **Zulassungsliste der Bildhosts** gegen SSRF, plus Größengrenze
  **doppelt** geprüft (angekündigt und tatsächlich).

**Was wir anders machen**: 9085 Zeilen in einer Datei mit ~19 Stellen,
die dieselben zwei INI-Zeilen per Regex flicken. Bei uns **ein Objekt**,
das Lesen und Schreiben der Mod-Liste besitzt — und die beiden Zeilen
werden **gemeinsam** geschrieben, nicht als zwei unabhängige
Ersetzungen (ihr Absturz dazwischen hinterlässt einen widersprüchlichen
Zustand).

### Eine Erkenntnis für uns selbst

**„Das ganze Objekt zurücksenden" erzeugt Fehler.** Ihre Oberfläche
schickte bei jedem Speichern alle Einstellungen — Ursache von
**vier** getrennt behobenen Fehlern im Konfigurationseditor allein.
Wir senden nur, was sich geändert hat. Unser `draft`-Muster tut das schon.

---


## Die Reihenfolge auf einen Blick

| # | Schritt | Größe | hängt ab von |
|---|---|---|---|
| **0** | Bridge ohne Neustart laden | klein | — |
| **1** | Statistiken sammeln + Diagramme | mittel | — |
| **2** | Server-Konfigurationseditor | **groß** | 0 (dieselbe Messmethode) |
| **3** | Steam Workshop / Mods | mittel | **2** (INI-Zugriff) |
| **4** | Ereignisstrom | klein | — |
| **5** | Benachrichtigungsglocke | klein | **4** |
| **6** | Discord | **groß** | **4** |
| **7** | Zeitplaner | mittel | 1 (der ddev-Zeitgeber) |
| **8** | Avatare | mittel | — |

Schritt 0 zuerst, weil er jeden folgenden Schritt beschleunigt und die
Messmethode liefert, die Schritt 2 wieder braucht.

# Schritt 0 — Die Bridge ohne Serverneustart laden

**Vom Nutzer gefragt und sofort weitergedacht:** *„Wenn das mit
reloadlua wirklich funktioniert, sollte es automatisch beim Bridge-Upload
ausgeführt werden."*

Das wäre die größte Verbesserung des Arbeitsablaufs im ganzen Plan.
Jeder Bridge-Upload hat diese Woche **fünf** Serverneustarts gekostet,
und drei davon waren fehlgeschlagene Uploads.

## Was belegt ist — im Bytecode, vollständig

`ReloadLuaCommand.Command()`, Anweisung für Anweisung:

```
LuaManager.loaded  (ArrayList) durchlaufen
  → String.endsWith(argument)          ← ein Teilstring genügt
  → loaded.remove(gefundenerPfad)
  → LuaManager.RunLua(gefundenerPfad, true)
  → "Lua file reloaded"
sonst → "Unknown Lua file"
```

Drei Folgerungen, alle wichtig:

1. **`endsWith` genügt.** `reloadlua ZomboidControlBridge.lua` findet
   unsere Datei, egal in welchem Verzeichnis sie liegt. Wir müssen
   keinen Pfad kennen und keinen Servernamen.
2. **`RunLua` bekommt den gefundenen Pfad**, nicht unser Argument. Die
   Datei wird also von der Platte neu ausgeführt — und weil der Upload
   sie vorher ersetzt hat, läuft **der neue Code**.
3. **`RunLuaInternal` trägt den Pfad wieder in `loaded` ein**
   (`LuaManager`, Anweisung 367–371). Ein **zweites** Neuladen
   funktioniert also genauso. **Wiederholbar, nicht einmalig** — das war
   die offene Frage.

`Events.X.Remove` ist ebenfalls bestätigt: das Spiel nutzt es selbst an
vier Stellen (`ISCampingMenu.lua:452`, `FishingRod.lua:389`,
`Bobber.lua:255`, `ISPerkLog.lua:106`).

## Das Problem, das ich dabei gefunden habe

Die Bridge registriert beim Laden **zwei** Ereignisse
(`ZomboidControlBridge.lua:2854` und `:2858`), und `Events.X.Add()`
**ersetzt nicht, es fügt hinzu.** Ein Neuausführen hätte also zwei
`onTick`-Aufrufe je Takt, nach dem zweiten Mal drei — die Schleife liefe
mehrfach, schriebe Dateien mehrfach und verarbeitete jeden Befehl
mehrfach.

**Das wäre schlimmer als ein Neustart.** Und es ist genau die Sorte
Fehler, die still auftritt: nichts stürzt ab, es wird nur alles doppelt
getan.

## Die Lösung: die Bridge neuladbar machen

Ein Wächter im Modulkopf, bevor irgendetwas registriert wird:

```lua
-- Ein reloadlua führt diese Datei erneut aus, und Events.X.Add() fügt
-- hinzu statt zu ersetzen: ohne diesen Wächter liefe onTick nach dem
-- ersten Neuladen doppelt und schriebe jede Datei zweimal.
if ZomboidControlBridge ~= nil and ZomboidControlBridge.registered then
    Events.OnTickEvenPaused.Remove(ZomboidControlBridge.onTick)
end
```

`Events.X.Remove` existiert — es ist die Gegenoperation zu `Add` und wird
in `media/lua/` verwendet. Der Handgriff dabei: die Funktion muss in
einer **globalen** Tabelle liegen, damit der neue Durchlauf die *alte*
Referenz zum Abmelden findet. Eine `local function` wäre nach dem
Neuausführen eine andere Funktion und ließe sich nicht entfernen.

## Der Start muss nachgeholt werden — vom Nutzer verlangt

*„Wenn wir die Bridge mit reloadlua neu laden, muss die Bridge danach in
vollem Umfang und direkt und genau so funktionieren wie sie soll. Also
auch das mit dem Serverstart muss dann funktionieren, obwohl er nie
gestoppt wurde."*

**`OnServerStarted` feuert beim Neuladen nicht** — der Server läuft
längst. Genau dort steht aber alles, was einmal je Start passieren muss
(`ZomboidControlBridge.lua:2858-2874`):

| Was | warum es sonst fehlt |
|---|---|
| `writePlayers`, `writeServerInfo`, `writeSafehouses`, `writeVehicles`, `writeFactions` | das Panel hätte bis zum nächsten Takt alte Daten |
| **`writeItems`** | der Item-Katalog (mit den Mod-Items) fehlte |
| **`writeVehicleCatalogue`** | der Fahrzeugkatalog fehlte |
| `writeProbe` | die Fähigkeitsprüfung fehlte |
| **`readCursor()` / `writeCursor()`** | **die Bridge verlöre ihre Befehlsposition** und würde jeden je gesendeten Befehl erneut ausführen |

Der letzte Punkt ist der gefährlichste: ohne `readCursor()` fängt die
Bridge bei Befehl 1 an. Bei uns wären das über 600 — jeder Ban, jedes
Wetterereignis, jedes Item noch einmal.

**Die Lösung**: der Startblock wird eine **benannte Funktion**, und der
Neulade-Wächter ruft sie direkt auf, wenn er erkennt, dass es ein
Neuladen ist (der Server läuft schon). Also:

```lua
ZomboidControlBridge = ZomboidControlBridge or {}

-- Ein reloadlua führt diese Datei erneut aus. Events.X.Add() fügt hinzu
-- statt zu ersetzen, und OnServerStarted feuert nicht mehr, weil der
-- Server längst läuft. Ohne beides würde onTick doppelt laufen und die
-- Bridge bei Befehl 1 wieder anfangen.
local reloading = ZomboidControlBridge.registered == true

if reloading then
    Events.OnTickEvenPaused.Remove(ZomboidControlBridge.onTick)
end

ZomboidControlBridge.onTick = onTick
ZomboidControlBridge.registered = true
Events.OnTickEvenPaused.Add(onTick)

if reloading then
    onServerStarted()   -- dieselbe Funktion, die OnServerStarted aufruft
else
    Events.OnServerStarted.Add(onServerStarted)
end
```

Der Handgriff: `onTick` muss in einer **globalen** Tabelle liegen, damit
der neue Durchlauf die **alte** Referenz zum Abmelden findet. Eine
`local function` wäre nach dem Neuausführen eine andere Funktion und
ließe sich nicht entfernen — der Abmeldeversuch wäre wirkungslos und die
Verdopplung bliebe.

**Ein Nebeneffekt, der richtig ist und nicht „aufgeräumt" werden darf**:
`SESSION_ID` entsteht auf Modulebene (`:105`), ist nach einem Neuladen
also **neu**. Das Panel nutzt sie, um zu erkennen, ob Item- und
Fahrzeugkataloge noch aktuell sind (`ItemCatalogue::stillCurrent`,
`SpawnableVehicles`). Ein neuer Wert ist **korrekt**: nach einem
Neuladen können sich die Kataloge geändert haben, also werden sie neu
gelesen.

## Woran es gemessen wird

**„In vollem Umfang" heißt: jede Zuständigkeit der Bridge einzeln
prüfen**, nicht nur ob sie antwortet. Am laufenden Server, in dieser
Reihenfolge:

| # | Prüfung | Beweist |
|---|---|---|
| 1 | Bridge mit erhöhter Version hochladen, **kein Neustart** | — |
| 2 | `reloadlua ZomboidControlBridge.lua` über RCON | „reloaded" statt „Unknown Lua file" |
| 3 | `ping` | sie antwortet überhaupt |
| 4 | **Version zurücklesen** | der **neue** Code läuft, nicht der alte |
| 5 | **Einen Befehl senden und zählen** | **keine Verdopplung** — der eigentliche Test |
| 6 | `players.json` prüfen | Erstschreibvorgang nachgeholt |
| 7 | **`items.json` und `vehicle-catalogue.json`** | die Einmal-je-Start-Schreibvorgänge nachgeholt |
| 8 | **Fähigkeitsprüfung (`probe`)** | nachgeholt |
| 9 | **Befehlszeiger** | die Bridge fängt **nicht** bei Befehl 1 an |
| 10 | **Zweimal hintereinander neu laden** | wiederholbar, `loaded` wird korrekt neu gefüllt |
| 11 | Nach 30 Sekunden noch einmal `ping` | die Schleife läuft weiter, ist nicht abgestorben |

Punkt 5 und 9 sind die kritischen: eine Verdopplung fällt sonst erst
auf, wenn jemand die Protokolle liest, und ein zurückgesetzter Zeiger
würde **jeden je gesendeten Befehl erneut ausführen** — bei uns über 600.

`BridgeVersionVerdict` unterscheidet schon zwischen „Datei auf Platte"
und „laufender Mod" — der Nachweis ist also mit vorhandenen Mitteln
führbar.

**Ein Testtor, das dauerhaft bleibt**: ein Wächter, der verlangt, dass
**jedes** `Events.X.Add` in der Bridge ein zugehöriges `Remove` im
Neulade-Wächter hat. Wer künftig ein drittes Ereignis registriert und den
Wächter vergisst, bekommt einen roten Test statt einer stillen
Verdopplung.

## Die Abbruchbedingung — vom Nutzer verlangt

*„Wenn wir nicht sicher sind, dass es funktioniert, dann muss der Server
eben neugestartet werden. Wir müssen absolut sicher sein, dass reloadlua
an der Stelle funktioniert, und nur dann bauen wir das auch so."*

**Das ist die verbindliche Randbedingung dieses Schrittes.** Es wird in
zwei Etappen gebaut, und die zweite kommt **nur**, wenn die erste
vollständig gelingt:

### Etappe A — messen, ohne etwas zu versprechen

Der Neulade-Wächter kommt in die Bridge (er ist ohnehin richtig und
schadet nie), und die elf Prüfungen werden **von Hand** durchlaufen.
Die Oberfläche sagt weiterhin **immer** „Neustart nötig".

**Wenn eine einzige der elf Prüfungen scheitert oder unklar bleibt,
endet der Schritt hier.** Der Wächter bleibt drin, das automatische
Neuladen kommt nicht. Kein „funktioniert wahrscheinlich".

### Etappe B — erst dann automatisieren

Nur wenn **alle elf** Prüfungen sauber sind, und **zweimal
hintereinander reproduziert**:

`BridgeInstaller::install()` ruft nach dem Upload `reloadlua` und
**prüft nach**, statt zu glauben. **Vier Ergebnisse, vier Aussagen** —
nach der „ein Wert, zwei Bedeutungen"-Regel:

| Ergebnis | Was die Oberfläche sagt |
|---|---|
| Neu geladen **und** Version zurückgelesen stimmt | „Bridge aktiv, kein Neustart nötig" |
| `Unknown Lua file` | „Hochgeladen — Neustart nötig" |
| Neu geladen, aber **Version stimmt nicht** | **„Hochgeladen, aber es läuft noch die alte Fassung. Server neu starten."** |
| Bridge antwortet **nicht mehr** | **„Hochgeladen, aber die Bridge antwortet nicht. Server neu starten."** |

Die letzten drei dürfen **niemals** zu „übernommen" verschmelzen. Und im
Zweifel — Zeitüberschreitung, unklare Antwort, kein Rücklesen möglich —
gilt **„Neustart nötig"**. Nach unserer Regel: im Zweifel in die sichere
Richtung irren.

**Die Prüfung ist nicht optional, sondern Teil des Vorgangs.** Ohne ein
erfolgreiches Zurücklesen der Version wird nie „aktiv" gemeldet. Das ist
genau der Fehler, der uns beim Schnee einen Abend gekostet hat:
schreiben, das eigene Schreiben zurücklesen, Erfolg melden — während das
Spiel es überschrieb.

## Warum das Schritt 0 ist

Es ist klein, unabhängig, und macht **jeden folgenden Schritt schneller**
— insbesondere Schritt 2, wo dieselbe Frage für die Sandbox-Datei
gestellt wird. Dieselbe Technik, dieselbe Messung, dieselbe
Abbruchbedingung.

**Vorbehalt**: Etappe A braucht einen Bridge-Upload und **einen**
letzten Neustart, um den Wächter einzubringen. Ob es der letzte war,
entscheidet die Messung — nicht die Hoffnung.

# Schritt 1 — Statistiken: sammeln und zeichnen

**Vom Nutzer entschieden**: so viele Informationen wie möglich sammeln,
ein Jahr Aufbewahrung (einstellbar), **Diagramme sofort mitbauen**.

## Was gesammelt wird

Drei Tabellen, jede mit einem klaren Zweck. Alle drei füllt die
**bestehende** Bridge — keine Bridge-Änderung, kein Upload, kein
Serverneustart.

### `player_stat_sample` — je Spieler und Tag

Eine Zeile je Spieler und Kalendertag, im Tagesverlauf aktualisiert. Aus
`describePlayer`, das alles davon schon liefert:

| Spalte | Quelle | heute persistiert? |
|---|---|---|
| `zombie_kills`, `survivor_kills` | Bridge (0.18) | ja, aber **überschrieben** |
| `hours_survived` | Bridge | ja, überschrieben |
| `health`, `infected` | Bridge | ja, überschrieben |
| `skill_total` (Summe aller Stufen) | aus `skills` errechnet | nein |
| `minutes_online` | aus den Lesevorgängen aufsummiert | nein |

`taken_on` als **Datum**, nicht Zeitstempel: eine Zeile je Tag, im Laufe
des Tages fortgeschrieben. Bei 20 Spielern sind das 7300 Zeilen im Jahr.

**Und `getDieCount()` kommt dazu** — Todesfälle je Spieler, im Spiel
vorhanden (`IsoGameCharacter.getDieCount()`), von der Bridge **noch nicht
gelesen**. Das ist die eine sinnvolle Bridge-Ergänzung; sie wartet auf
den nächsten Upload und blockiert nichts.

### `world_stat_sample` — je Server und Stunde

Die Bridge schreibt schon `serverInfo`, `vehicles`, `safehouses`,
`factions` und das Klima. Eine Zeile je Stunde:

| Spalte | Quelle |
|---|---|
| `players_online` | Spielerliste |
| `vehicles`, `safehouses`, `factions` | die jeweiligen Bridge-Dateien |
| `game_day`, `season` | `serverInfo`, `daysSurvived` |
| `temperature`, `raining`, `snowing` | Klima |

Stündlich: 8760 Zeilen im Jahr und Server. Damit werden „Spieler über
Zeit", „Fahrzeuge über Zeit" und der Wetterverlauf **exakt** statt
abgeleitet.

### Was **keine** neue Tabelle braucht

`moderation_action` ist anfügend und hat `performedAt`. Daraus kommen
sofort, ohne einen neuen Datensatz:

- **Beitritte und Abgänge je Tag** (`join`/`leave` von `RosterWatcher`)
- **Admin-Aktionen je Tag und Art** (alle 18 Arten)
- **Wer was getan hat** — je Konto
- **Fehlgeschlagene Aktionen** (`failed = true`)

⚠️ **Ein Index auf `performedAt` fehlt** (`ModerationAction.php:20`
indexiert nur `(server_id, username)`). Eine Tagesgruppierung liest sonst
die ganze Tabelle. Der Index gehört in dieselbe Migration.

## Wie gesammelt wird

**Kein neuer Zeitgeber.** `BridgeStatusReader` läuft schon bei jedem
Aufruf der Spielerliste; dort wird angefügt. Das ist der einzige Ort, der
die Daten ohnehin in der Hand hat.

Zwei Fallstricke, beide belegt:

- `MIN_SECONDS_BETWEEN_READS = 2` plus Zwischenspeicher — die Abtastung
  ist **nicht gleichmäßig**. Also „je Stunde höchstens einmal" als
  Bedingung, nicht „bei jedem Lesevorgang".
- `STALE_AFTER_SECONDS = 120`: ist die Bridge stumm, gilt jeder als
  offline und `playerCount` wird 0. **Dann darf nichts geschrieben
  werden** — sonst entstehen Nullwerte, die wie ein leerer Server
  aussehen. Das ist die „ein Wert, zwei Bedeutungen"-Regel: „niemand
  online" und „nicht gemessen" sind zwei Zustände.

## Aufbewahrung

`AppSetting::STATS_RETENTION_DAYS`, Standard **365**, Mindestwert wie
gehabt geprüft (`SettingsController::RETENTION_MINIMUM_DAYS = 7`).
`StalePlayerPurger` bekommt die zwei neuen Tabellen dazu — die tägliche
Aufgabe in `MainSchedule` existiert schon.

## Die Diagramme

Sofort mit, wie entschieden. **Neue Abhängigkeit**: `recharts` plus
shadcns `chart.tsx`. Die Farben liegen bereit (`--chart-1` bis `-5`, in
beiden Themen, `chart-1` gleich `--primary`).

Eine neue Seite **Statistiken** im Abschnitt `live`, dazu zwei Karten auf
der Übersicht.

| Diagramm | Form | Quelle |
|---|---|---|
| Spieler über Zeit | Fläche | `world_stat_sample` |
| Kills je Spieler | Balken, sortiert | `player_stat_sample`, aktuell |
| Kills im Verlauf | Linie je Spieler | `player_stat_sample` |
| Admin-Aktionen je Tag und Art | gestapelte Balken | `moderation_action` |
| Beitritte je Wochentag und Stunde | Wärmebild | `moderation_action` |
| Fertigkeitswachstum | Linie | `player_stat_sample.skill_total` |
| Fahrzeuge und Zufluchtsorte | Linie | `world_stat_sample` |
| Wetter- und Temperaturverlauf | Fläche | `world_stat_sample` |

**Ein leeres Diagramm sagt, warum es leer ist** — „noch keine Daten,
Sammlung läuft seit …" statt einer leeren Fläche. Nach unserer Regel:
sagen, warum etwas nicht da ist.

## Dateien

| Was | Wo |
|---|---|
| Entitäten | `backend/src/Entity/PlayerStatSample.php`, `WorldStatSample.php` |
| Anfügen | in `BridgeStatusReader::store()` (`:143`), neue private Methode |
| Abfragen | `backend/src/Repository/…SampleRepository.php` |
| Aufräumen | `StalePlayerPurger::run()` erweitern |
| API | `backend/src/Controller/Api/StatisticsController.php`, `#[IsGranted(ViewPlayers)]` |
| Migration | zwei Tabellen **plus** Index auf `moderation_action.performed_at` |
| Oberfläche | `frontend/src/features/statistics/` — `statistics-page.tsx`, `statistics.ts`, je Diagramm eine Datei |
| Bauteil | `frontend/src/components/ui/chart.tsx` (shadcn) |
| Navigation | ein Eintrag in `server-pages.ts`, Abschnitt `live` |

## Wächter

- **`statistics.test.ts`** — die Kübelbildung (Tag, Stunde, Wochentag) als
  reine Funktion, mit Zeitzonenwechsel und Tageswechsel.
- **Ein Test, der beweist, dass bei stummer Bridge nichts geschrieben
  wird** — der Fehler, der stille Nullwerte erzeugt.
- **Ein Test auf den Index**: die Tagesabfrage muss ihn nutzen.
- Jeder durch Rückbau bewiesen, wie immer.

## Prüfung

Nach Regel 6b **im Browser**, per Klick:

1. Seite öffnen, während noch keine Daten da sind → jedes Diagramm
   erklärt seine Leere.
2. Spielerliste laden (das schreibt eine Probe), Seite neu → erste Werte.
3. Aufbewahrung auf 7 Tage stellen, Aufräum-Aufgabe von Hand starten →
   alte Zeilen weg, neue bleiben.
4. **Der ddev-Zeitgeber fehlt** (`scheduler_main` wird lokal nicht
   konsumiert) — der Aufräum-Lauf ist lokal nur über den Konsolenbefehl
   prüfbar. **Das behebe ich in diesem Schritt mit**, sonst ist Schritt 7
   überhaupt nicht prüfbar.
5. Beide Sprachen, kein Rohschlüssel.

---

---

# Schritt 2 — Server-Konfigurationseditor

## Der Erzeuger zuerst

Ein Konsolenbefehl `app:config:schema` liest die **lokale
Spielinstallation** und schreibt zwei PHP-Klassen — dieselbe Methode wie
bei `CharacterDefinitions.php`, aber diesmal **mit** dem Erzeuger im
Repository (den die Referenz hat und wir bisher nicht).

Quellen und was sie liefern:

| Quelle | liefert |
|---|---|
| `media/lua/shared/Sandbox/Apocalypse.lua` | 274 Namen + Standardwerte, **verschachtelte Tabellen erhalten** |
| `zombie.SandboxOptions` (javap) | Typ je Option, `getMin()`, `getMax()`, `getNumValues()`, **`getPageName()`** = Gruppe |
| `zombie.network.ServerOptions` (javap) | 144 INI-Optionen mit Typ |
| `Translate/DE/Sandbox.json` | 1082 Einträge: Namen, **Auswahlbeschriftungen** (`Sandbox_X_option1`), 266 Erklärungen |
| `Translate/EN/Sandbox.json` | dasselbe auf Englisch |
| `appmanifest_108600.acf` | **`buildid`** für den Herkunftsstempel |

Erzeugt werden `SandboxSchema.php` und `ServerIniSchema.php`, plus eine
Vergleichsdatei `tests/Fixtures/config-schema.json` als **Prüfschranke**
— genau wie `climate-api.json`.

**Was Handarbeit bleibt**: die **INI-Gruppierung**. `ServerOption` hat
nur `getTooltip()`, keine Gruppe. Vom Nutzer verlangt: *„Einstellungen
die zusammen gehören sollten auch zusammen bleiben."* Die 20 Kategorien
der Referenz in 5 Obergruppen sind der Ausgangspunkt, den wir prüfen und
anpassen — als Konstantentabelle mit einem Test, der verlangt, dass
**jede** der 144 Optionen genau einer Kategorie zugeordnet ist.

## Die Dateien finden, nie raten

**Vom Nutzer angemerkt**: die Namen hängen vom Servernamen ab.

`FtpConfig` bekommt ein Feld `configPath` (wie `luaServerPath` und
`logPath` schon), und darin wird gesucht:

- `*.ini` → die Haupt-INI
- `*_SandboxVars.lua` → die Sandbox-Werte
- `*_spawnregions.lua`, `*_spawnpoints.lua`

**Mehrere Treffer sind ein eigener Zustand**, kein Fehler: der Betreiber
wählt. Kein Treffer sagt, wo gesucht wurde.

## Der Parser

Von Hand, aber **mit einem echten Lua-Prüfer im Test**. Die Referenz
nutzt `fengari` (eine Lua-VM) nur in Tests, um zu beweisen, dass die
Regex-Schreibvorgänge gültiges Lua ergeben. Wir haben `luac` bereits
lokal (heute installiert) — `luac -p` auf das Ergebnis ist der gleiche
Beweis, billiger.

Drei Anforderungen, jede durch einen Fall in der Standarddatei belegt:

1. **Verschachtelte Tabellen** — sechs davon in `Apocalypse.lua`
   (`Basement`, `Map`, `ZombieLore`, `ZombieConfig`, `MultiplierConfig`).
   Mods legen ihre Werte genauso ab.
2. **Text mit Kommas** — `Apocalypse.lua:71` hat neun Kommas in einer
   Zeichenkette. Am Komma trennen beschädigt die Datei; die Referenz hat
   damit einen Server lahmgelegt.
3. **Schlüsselgleichheit über Abschnitte** — `Farming` existiert
   zweimal (`settings.Farming` als Fertigkeitswachstum 1–5,
   `MultiplierConfig.Farming` als XP-Faktor 0,001–1000). `Strength`
   ebenso. Der Schlüssel ist **immer** `abschnitt.name`, nie nur `name`.

## Schreiben: Sicherung, Prüflesen, fünf Versionen

**Vom Nutzer entschieden.** Der Ablauf:

1. Datei lesen (mit `readTail` und einem `$maxBytes` **größer als die
   Datei** — `readTail` liest vom Ende, `BridgeInstaller.php:69` macht
   es mit 262144 vor)
2. Sicherung schreiben: `<name>.bak-<ISO-Zeitstempel>`
3. **Nur die Werte ersetzen**, die sich geändert haben — Zeilenenden,
   Einrückung und Abstände um `=` bleiben, wie sie sind
4. Schreiben
5. **Zurücklesen und je geänderten Schlüssel vergleichen**
6. Bei Abweichung: Sicherung zurückspielen und melden

**Fünf Sicherungen bleiben liegen** und sind im Panel wiederherstellbar.
Sortiert **nach dem Dateinamen**, nicht nach Zeitstempeln — auf ext4
haben zeitgleiche Sicherungen identische Zeitstempel, und dann wird die
gerade erzeugte als älteste gelöscht (Referenz-Erfahrung).

**Drei Zustände für die Sicherung**: gesichert / nichts zu sichern
(harmlos, erste Änderung) / **fehlgeschlagen** (gefährlich). Die letzten
beiden zu verwechseln hat bei der Referenz zu einer Antwort geführt, die
eine Sicherung behauptete, die es nicht gab.

**Zwei Härtegrade**: eine gewöhnliche Änderung wird bei fehlgeschlagener
Sicherung nur mit einer Warnung geschrieben; eine **Wiederherstellung**
oder ein Vorlagen-Anwenden wird **verweigert**.

`FileBrowserInterface` bekommt dafür `delete()` (für das Aufräumen alter
Sicherungen) und `listDirectory` reicht für den Rest.
**`MAX_ENTRIES = 500` schneidet still ab** (`ServerFileBrowser.php:21`)
— das muss ein sichtbarer Zustand werden.

## Mod-Werte niemals verlieren

**Vom Nutzer verlangt, und die eigentliche Gefahr.** Das Spiel sieht
Mod-Werte ausdrücklich vor (`initSandboxVars()`, `isCustom()`).

**Die Datei ist die Wahrheit, das Schema nur die Beschreibung.** Was in
der Datei steht und das Schema nicht kennt:

- wird **angezeigt**, mit Namen, Wert und dem Hinweis „von einem Mod"
- wird **bearbeitbar**, mit dem Typ, den der Wert selbst nahelegt
- wird beim Speichern **unangetastet durchgeschrieben**, wenn niemand
  ihn geändert hat

Ein **unbekannter Auswahlwert** wird nie zurechtgebogen: *„Dieser Server
steht auf 7, was dieses Panel nicht kennt. Der Wert bleibt unverändert,
solange Sie hier nichts anderes wählen."*

## Drei Werte mit eigenem Auswähler

**Vom Nutzer verlangt** für die Startitems, plus zwei, die dasselbe
Argument haben:

| INI-Wert | Auswähler | Vorlagen |
|---|---|---|
| `spawnItems` | **der Item-Auswähler** aus `features/items/`, als Modal | ja — „Anfänger", „Bewaffnet", „Arzt" … |
| `spawnPoint` | die Karte | — |
| `workshopItems` | **kommt aus Schritt 3** | — |

Die Vorlagen nach dem Muster von `MAIL_PRESETS`
(`settings.ts:74-90`), das genau diese Form schon hat.

## Übernehmen, ohne Neustart?

**Zwei getrennte Fälle, beide zu messen** (Schritt 0 liefert die
Methode):

| Datei | Befehl | belegt |
|---|---|---|
| **Haupt-INI** | `reloadoptions` | **ja** — `ServerOptions.init()` + `sendOptionsToClients()` + Zombie-Verwaltung + PvP + Passwort |
| **Sandbox** | `reloadlua <datei>` | **offen** — hängt daran, ob die Datei in `LuaManager.loaded` steht |

Beim Sandbox-Fall zusätzlich prüfen, ob **die Lua-Tabelle *und* die
Java-Optionen** erneuert sind — die Referenz warnt vor dem halben Weg:
Java-Option geschrieben, Tabelle veraltet, und Mod-Code liest die
Tabelle.

**Bis belegt: „Neustart nötig".** Und die Bridge kann zurücklesen, also
ist der Zustand **belegbar** statt behauptet.

## Laufender Server

- Eine **gewöhnliche Änderung** wird geschrieben, mit „Neustart nötig"
  oder „übernommen", je nach Messung.
- Eine **ganze Datei überschreiben** (Sicherung zurück, Vorlage
  anwenden) wird bei laufendem Server **verweigert**.
- **„Kann nicht feststellen" gilt als „läuft".**

## Dateien

| Was | Wo |
|---|---|
| Erzeuger | `backend/src/Command/GenerateConfigSchemaCommand.php` |
| Schemata | `backend/src/Server/Config/SandboxSchema.php`, `ServerIniSchema.php` (erzeugt) |
| INI-Gruppen | `backend/src/Server/Config/IniCategories.php` (Handarbeit) |
| Parser | `Server/Config/IniFile.php`, `SandboxFile.php` — je Lesen/Ändern/Schreiben |
| Finden | `Server/Config/ConfigFileLocator.php` |
| Sicherung | `Server/Config/ConfigBackup.php` |
| Übernehmen | `Server/Config/ConfigReloader.php` — kapselt `reloadoptions`/`reloadlua` und das Zurücklesen |
| API | `Controller/Api/ServerConfigController.php` |
| Berechtigung | `Permission::EditServerConfig = 'servers.config'`, Gruppe `servers` |
| Entität | `FtpConfig` + `configPath` (Migration) |
| Oberfläche | `frontend/src/features/config/` — `config-page.tsx`, `sandbox-tab.tsx`, `ini-tab.tsx`, `value-row.tsx`, `backup-list.tsx` |
| Navigation | neuer Abschnitt oder unter `diagnostics` — **zu entscheiden beim Bau** |

Für 274 Zeilen: `@tanstack/react-virtual` ist **schon installiert**.
Bearbeitungszustand je Zeile als `{from, to}`, wie `climate-page.tsx` es
vormacht. **Nur senden, was sich geändert hat.**

## Wächter

- **Schema gegen die Vergleichsdatei** — bricht, wenn ein Spielupdate
  Werte ändert (beabsichtigt, wie `DocumentationTest`).
- **Jede der 144 INI-Optionen hat genau eine Kategorie.**
- **Parser-Rundlauf**: lesen → schreiben → `luac -p` → wieder lesen,
  Ergebnis identisch. Mit den drei Fällen: verschachtelte Tabelle,
  Zeichenkette mit Kommas, `Farming` in zwei Abschnitten.
- **Ein unbekannter Wert überlebt einen Speichervorgang** — der
  Mod-Schutz, durch Rückbau bewiesen.
- **Zeilenenden bleiben** (CRLF-Datei bleibt CRLF).
- **Handgeschriebene Abstände bleiben** (`PVP = true` wird nicht
  `PVP=true`).

## Prüfung

Im Browser, per Klick:

1. Datei finden lassen → beide Dateien mit Namen.
2. Einen Wert ändern → Sicherung entsteht, Wert steht drin, Zurücklesen
   bestätigt.
3. **Eine Zeile von Hand über FTP einfügen, die das Schema nicht kennt**
   → wird angezeigt, überlebt das Speichern.
4. Sicherung wiederherstellen → alter Wert zurück.
5. `spawnItems` über den Item-Auswähler setzen.
6. Nach dem Speichern: sagt die Oberfläche „übernommen" oder „Neustart
   nötig" — und ist es wahr? Über die Bridge zurücklesen.

---

# Schritt 3 — Steam Workshop und Mod-Verwaltung

Baut auf dem INI-Zugriff aus Schritt 2.

## Ein Objekt besitzt die drei Zeilen

`Mods=`, `WorkshopItems=` und `Map=` gehören **einem** Wertobjekt
(`ModList`), das sie liest, ändert und **gemeinsam** schreibt. Die
Referenz flickt sie an ~19 Stellen per Regex und schreibt sie als zwei
unabhängige Ersetzungen — ein Absturz dazwischen hinterlässt einen
widersprüchlichen Zustand.

**Der wichtigste Fund**: numerische IDs dürfen **nie** in `Mods=`.
Workshop-IDs (Zahlen) gehören in `WorkshopItems=`, Mod-IDs (Text) in
`Mods=`. Geprüft an **beiden** Enden, mit Selbstheilung für bestehende
Verunreinigung.

**Ein Mod entfernen berührt alle drei Zeilen** — auch `Map=`, was die
Referenz erst nachträglich gelernt hat.

## Namen und Metadaten

**Ohne API-Schlüssel**: `ISteamRemoteStorage/GetPublishedFileDetails/v1/`
liefert Namen und Vorschaubilder. Der Schlüssel (den wir schon
konfigurierbar haben, `AppSetting::STEAM_API_KEY`) ist nur nötig für:

- **Textsuche** (`IPublishedFileService/QueryFiles`)
- **Abhängigkeiten** (`required_items` über `GetDetails`)

Ohne Schlüssel: ein Link zum Workshop statt einer Suche — nach unserer
Regel „sag, warum etwas nicht geht".

**Namen zuerst von der Platte**: `<workshop>/content/108600/<id>/mods/<name>/mod.info`,
und die **B42-Unterordner `common/`, `42/`, `42.0/`** mitprüfen. Dann
gesammelt von Steam, 100 auf einmal.

**Ein Zwischenspeicher mit Ablauf**, und **negativ mit Ablauf** für
fehlgeschlagene Abrufe — ohne den lief bei der Referenz bei jedem
Seitenaufruf der ganze Steam-Weg neu, für immer.

Vorschaubilder: **Zulassungsliste der Hosts** gegen SSRF, Größengrenze
**doppelt** geprüft (angekündigt und tatsächlich), Auslieferung über uns
(`img-src 'self'` bleibt wahr).

## Ladereihenfolge

**Nie alphabetisch.** Nur verschieben, was eine erklärte Abhängigkeit
(`require=` in `mod.info`) erzwingt; sonst die Ordnung des Betreibers
erhalten. Zurückgegeben wird, **was sich bewegt hat und warum** — plus
Zyklen und fehlende Abhängigkeiten als eigene Zustände.

Anders als die Referenz liegt das **serverseitig**, damit auch ein
Schreibvorgang ohne die Oberfläche geprüft ist.

## Dateien

`Server/Mods/ModList.php` (das Wertobjekt), `WorkshopClient.php`,
`ModInfoReader.php` (Platte), `LoadOrder.php`,
`Controller/Api/ModController.php`, `Permission::ManageMods = 'servers.mods'`,
`frontend/src/features/mods/`.

## Wächter

Eine numerische ID landet nie in `Mods=`; eine Entfernung berührt alle
drei Zeilen; die Reihenfolge bleibt, wenn keine Abhängigkeit sie
erzwingt; ein Zyklus wird gemeldet statt aufgelöst.

---

# Schritt 4 — Der Ereignisstrom

Das Fundament für Glocke **und** Discord. Zweimal gebaut wäre es zweimal
gepflegt.

`ModerationRecorder` **ist** der Anschlusspunkt, und sein Docblock sagt
es (`:12-27`). Ein Haken dort verteilt jedes Ereignis an angemeldete
Empfänger.

**Zwei Fallstricke, beide belegt:**

- **`add()` schreibt ohne `flush()`** (Stapelbetrieb, `:102`) — ein
  Empfänger darf die Zeile **nicht als festgeschrieben annehmen**. Also:
  Versand erst nach dem `flush()`, über einen Doctrine-Nachlauf oder
  einen Messenger-Auftrag.
- **`username` ist nicht immer ein Spieler** — es hält auch einen
  Aktionsnamen (`EventController.php:142`), ein Befehlsverb
  (`ConsoleController.php:121`) oder eine Konstante
  (`VehicleSpawnController.php:101`). Ein Ereignis braucht also ein
  ausdrückliches Feld „worauf sich das bezieht".

Dazu die Ereignisse, die **nicht** aus `ModerationAction` kommen:
Bridge stumm/wieder da, Server nicht erreichbar, Panel- und
Bridge-Aktualisierung.

`Server/Events/PanelEvent.php` als Wertobjekt, `PanelEventDispatcher`,
und **ein Index auf `moderation_action.performed_at`** (kommt schon in
Schritt 1).

---

# Schritt 5 — Die Benachrichtigungsglocke

**Abfragend**, wie alles hier — kein SSE, kein WebSocket (der
Apache-Kommentar warnt, dass lange Verbindungen je einen FPM-Arbeiter
belegen).

Einfügepunkt: `app-layout.tsx:29`, die dreiteilige Gruppe rechts.
Vorbild ist `AppUpdateBanner` — es erscheint **nur, wenn es etwas zu
sagen gibt**, und sein Docblock trifft den Ton: *„nichts ist kaputt, und
einen Betreiber mitten in der Arbeit zu unterbrechen, um gute
Nachrichten zu verkünden, ist unhöflich."*

Toasts sind flüchtig; „ungelesen" braucht eine Tabelle:
`PanelNotification` (Konto, Ereignis, gelesen, Zeitpunkt) — **je Konto**,
denn was Sie gelesen haben, hat ein anderer nicht.

Inhalt: Panel- und Bridge-Aktualisierungen, Bridge stumm, Server
unerreichbar, und aus dem Strom die Ereignisse, die der Betreiber
**einzeln wählt** — dieselbe Tabelle wie bei Discord, damit man es nicht
zweimal einstellt.

---

# Schritt 6 — Discord, im Detail geplant

**Der wichtigste Fund der Planung**: Die Discord-API braucht für fast
alles **keinen Dauerprozess**. Das teilt den Bot in drei Hälften mit
ganz verschiedenem Betriebsrisiko:

| Was | Weg | Prozess? |
|---|---|---|
| Meldungen **nach** Discord | Bot-Token, `POST /channels/{id}/messages` | **nein** |
| **Slash-Befehle** | HTTP-Endpunkt, Discord ruft **uns** | **nein** |
| Chat **aus** Discord | Gateway (WebSocket) | **ja** |
| Chat **ins** Discord | aus dem Chat-Log, das wir schon lesen | **nein** |

**Entschieden**: das Gateway kommt **nur für den Chat-Empfang**. Fällt
dieser Prozess aus, laufen Meldungen und Befehle weiter — bei der
Referenz (alles in einem Prozess) wäre dann alles still.

## Slash-Befehle ohne Gateway

Discord schickt jede Interaktion als **HTTP POST** an eine URL, die wir
angeben. Wir prüfen eine **Ed25519-Signatur** über
`X-Signature-Ed25519` + `X-Signature-Timestamp` + rohem Rumpf und
antworten. **`ext-sodium` ist bei uns Pflichtabhängigkeit** und kann
Ed25519 — es kommt keine Bibliothek dazu.

Drei Dinge, die Discord dabei erzwingt und die eine Prüfregel wert sind:

1. **Ohne funktionierende Signaturprüfung nimmt Discord die URL nicht
   an** — und prüft danach regelmäßig nach. Fällt die Prüfung durch,
   wird der Endpunkt **abgeschaltet**.
2. Ein `PING` (Typ 1) muss mit `PONG` (Typ 1) beantwortet werden.
3. **Drei Sekunden** Antwortzeit. Ein RCON-Aufruf kann länger dauern,
   also: sofort „wird ausgeführt" antworten und das Ergebnis
   nachliefern (Discord nennt das eine verzögerte Antwort). Genau der
   Fall, den unser Messenger schon bedient.

Der Endpunkt ist eine gewöhnliche Symfony-Route — **ohne** Anmeldung,
aber die Signatur *ist* die Anmeldung. Das gehört ausdrücklich in die
Sicherheitsregeln, weil es die erste öffentliche Route wäre.

## Die Befehlsordnung folgt dem Panel

**Entschieden**: Unterbefehle nach den Bereichen, die das Panel hat.
Discord erlaubt **eine** Verschachtelungsebene und 25 Optionen je Ebene
— das reicht genau.

| Befehl | Unterbefehle |
|---|---|
| `/spieler` | `kick`, `bannen`, `entbannen`, `heilen`, `teleport`, `zugriffsstufe`, `fertigkeit`, `eigenschaft`, `xp`, `zustand`, `item`, `notiz` |
| `/welt` | `zeit`, `datum`, `tageslicht`, `sichtweite`, `strom`, `wasser` |
| `/wetter` | `regen`, `sturm`, `schnee`, `blizzard`, `nebel`, `wolken`, `wind`, `temperatur`, `klar`, `zurücksetzen` |
| `/geräusch` | `donner`, `schuss`, `alarm`, `hubschrauber`, `blitz` |
| `/zombies` | `horde`, `entfernen` |
| `/fahrzeug` | `spawnen`, `liste` |
| `/server` | `status`, `speichern`, `nachricht`, `spieler`, `stoppen` |
| `/stats` | `spieler`, `kills`, `online` |

**Das deckt alle 35 Ereignisse und 22 Spielerrouten ab**, in acht
Befehlen statt über fünfzig.

### Autovervollständigung ist der eigentliche Gewinn

Auswahllisten sind auf **25 Einträge** begrenzt — für 5000 Items, 224
Fahrzeuge oder 47 Fertigkeiten unbrauchbar. **Autovervollständigung
nicht**: Discord fragt uns während des Tippens.

| Feld | was vorgeschlagen wird |
|---|---|
| Spielername | die **wirklich gerade online** sind, mit Spielzeit |
| Item | aus dem Katalog, den die Bridge geschrieben hat |
| Fahrzeug | die 224 mit ihren echten Namen |
| Fertigkeit | die 35, gruppiert |
| Eigenschaft | die 97, ohne die ausgeschlossenen |

Damit tippt niemand `Base.Trousers_SuitWhite` von Hand — dieselbe Regel
wie im Panel: *„Type nothing you could click."*

Wichtig: die Autovervollständigung hat **dasselbe Drei-Sekunden-Fenster**
und darf nicht auf die Bridge warten. Sie liest nur aus Datenbank und
Zwischenspeicher.

## Berechtigungen: der Grundsatz der Referenz, übernommen

**Etwas per Discord zu tun darf nicht billiger sein, als es im Panel zu
tun.** Eine Funktion bildet jeden Unterbefehl auf die
Panel-Berechtigung ab, die sein HTTP-Zwilling verlangt — geprüft
**beim Anlegen der Rechte und bei jeder Ausführung**.

Zwei Dinge, die die Referenz gelernt hat:

- **Discords eigene `default_member_permissions` nur setzen, wenn keine
  Panel-Rolle konfiguriert ist.** Sonst versteckt Discord den Befehl vor
  genau den Rollen, denen das Panel ihn erlaubt hat.
- **Nur Stufen prüfen, die sich wirklich ändern** — die Oberfläche
  schickt beim Speichern oft alle mit.

Wir gehen darüber hinaus: die Referenz hat **drei feste Stufen**
(alle/Moderator/Admin) und kann eine vierrollige Gilde nicht abbilden.
Wir bilden **je Unterbefehl auf Discord-Rollen** ab — mehrere erlaubt.

Offen, und beim Bau zu klären: **wie ein Discord-Nutzer auf ein
Panel-Konto abgebildet wird.** Die Referenz tut es nicht, deshalb steht
im Protokoll nur `[Discord] name`. Wir haben `OAuthIdentity` und
könnten eine Discord-Verknüpfung anbieten — dann steht im
Moderationsprotokoll ein echtes Konto. **Vorschlag**: Aktionen ohne
verknüpftes Konto werden mit dem Discord-Namen als `reason` erfasst und
`performedBy = null`, wie es `join`/`leave` schon tun.

## Meldungen: ein Token, Kanal je Ereignisart

**Entschieden.** Ein Webhook kann nur einen Kanal; mit dem Bot-Token
sendet `POST /channels/{id}/messages` überall hin, und **der Bot listet
die Kanäle zur Auswahl** — Sie kopieren keine URLs.

Je Ereignisart: ein Schalter, ein Kanal, eine Vorlage.

**Zwei Familien, und die zweite ist der Punkt, an dem wir die Referenz
klar überholen — sie meldet keine einzige Admin-Aktion:**

| Familie | Ereignisse |
|---|---|
| **Server** | Bridge stumm/wieder da, Server nicht erreichbar, Spieler beigetreten/verlassen, geplanter Neustart, Sicherung fertig |
| **Admin-Aktionen** | alle **18** Arten aus `ModerationAction`: kick, ban, unban, Zugriffsstufe, Konsole, Rundruf, Items, Teleport, Ereignis, Fähigkeit, XP, Heilen, Zustand, Eigenschaft, Fertigkeit |

**Alle Admin-Aktionen standardmäßig aus.** „X hat sich 500 Schuss
Munition gegeben" in einen öffentlichen Kanal zu senden, ist ein anderer
Vorgang als eine Neustart-Ankündigung — und der Abschnitt sagt das auch.

### Die Vorlagen

Je Ereignis ein editierbarer Text mit den Variablen, die **dieses**
Ereignis führt. Aus `ModerationAction` kommen: `{player}`, `{admin}`,
`{server}`, `{reason}`, `{detail}` (die Serverantwort), `{time}`, und
`{inputs}` — „Regen bei 70" statt nur „Regen".

Vier Regeln, jede aus einem bezahlten Fehler der Referenz:

1. **Einfach-Durchlauf mit Rückruffunktion.** Sonst wird ein Wert mit
   `$1` als Rückverweis gelesen, und `{player}` frisst den Anfang von
   `{playerCount}`.
2. **Ein unbekanntes Token bleibt wörtlich stehen** und wird beim
   Speichern angemerkt — die Referenz prüft das nicht, ein `{foo}`
   überlebt dort unbemerkt.
3. **Werte maskieren, Vorlage nicht.** Die Referenz maskiert die
   Vorlagen *gar nicht*, ein Spieler `**x**` schmuggelt also Formatierung
   in den Text des Betreibers. Wir maskieren die **eingesetzten Werte**
   und lassen das `**fett**` des Betreibers wirken.
4. **`allowed_mentions: {parse: []}` global.** Textersetzung genügt
   nicht: `<@&rolleId>` ist kein Markup, und ein Spielername
   `<@&adminRolle>` könnte Rollen anpingen. Der Bot muss nie erwähnen.

Dazu **eine Vorschau mit Beispielwerten** und **„Testnachricht senden"
je Ereignis** — die Referenz hat nur einen globalen Test, und den Text,
den man gerade bearbeitet, zu prüfen ist der nützliche Fall.

### Geheimnisse nie versehentlich senden

Von der Referenz, und es ist die klügste Vorsichtsmaßnahme dort: **an
der einen HTTP-Grenze** jeden ausgehenden Rumpf gegen die Geheimnisse
prüfen, die das Panel hält — RCON-Passwort, FTP-Passwort, Bot-Token,
Server-Beitrittspasswort — per **exaktem Wertvergleich**, nie per
Mustererkennung („ein Muster, das rät, wie ein Geheimnis aussieht, ist
ein neuer Fehler"). Und wenn die Prüfung selbst scheitert: **nicht
senden**.

Der Fall ist real: `/server status` oder ein Konsolen-Rundruf könnte eine
Serverantwort enthalten, die ein Passwort führt.

## Der Chat — die einzige Stelle mit Dauerprozess

### Spiel → Discord: kein Prozess nötig

Wir lesen die Chat-Logdatei schon (`ChatController`, `LogTailer`). Die
Meldung geht über denselben Token-Weg wie alle anderen.

**Aber `ChatLine` muss zuerst den Kanal erfassen.** Die Zeile enthält
`chat=UI_chat_main_tab_title_id` (dokumentiert in `ChatLine.php:14-16`),
das Muster in `:83` greift nur `author` und `text`. **Ohne den Kanal
darf die Brücke nicht gebaut werden** — sonst spiegelt sie Nahbereichs-,
Fraktions- und Flüsterchat nach Discord, und das ist ein
Datenschutzfehler, nicht nur ein Schönheitsfehler.

**Zulassungsliste, nicht Sperrliste**, mit dem Satz der Referenz als
Begründung im Code: *Fraktions-, Zufluchts-, Funk-, Admin- und
Flüsterkanäle fehlen absichtlich — sie sind im Spiel privat und müssen
in Discord privat bleiben.* Drei Bereiche zur Wahl: öffentlich / ohne
Rufen / nur Allgemein.

Zwei Details: `[Discord] `-Zeilen werden verworfen (sonst Echo), und die
Neustart-Countdown-Zeilen ebenfalls — **außer** „JETZT" und
„ABGEBROCHEN", denn genau die will man in Discord sehen.

### Discord → Spiel: hier braucht es das Gateway

Ein fünfter supervisord-Prozess mit `discord-php/DiscordPHP`
(ReactPHP, die einzige ernsthafte PHP-Wahl). Er tut **nur eines**:
Nachrichten aus dem gewählten Kanal lesen und über
`ChatBroadcaster::sanitise()` — schon statisch und rein — als
`servermsg` weitergeben.

**Vorbehalte, die in die Planung gehören:**

- Die Bibliothek rät zu **unbegrenztem Speicher**. In einem von Coolify
  überwachten Container ist das gefährlich. Also: enge Intents (nur
  `GuildMessages` + `MessageContent`), `--time-limit` und
  `--memory-limit` wie bei den anderen Arbeitern, und **kein
  Gilden-Cache**, den wir nicht brauchen.
- `ext-uv` oder `ext-event` machen die Ereignisschleife schneller, sind
  aber **nicht nötig** — bei wenigen Nachrichten genügt
  `stream_select`. Eine PECL-Erweiterung ins Bild zu holen ist
  Bauzeit und Größe; wir fangen ohne an und messen.
- **Der Prozess darf ausfallen, ohne dass etwas anderes stillsteht** —
  das ist der Grund für die Dreiteilung.
- **Pro Nutzer begrenzen** (die Referenz: 5 Nachrichten in 10 Sekunden),
  Erwähnungen und Emoji in lesbaren Text auflösen, Länge begrenzen.
- **Das RCON-Ergebnis prüfen, nicht nur die Verbindung.** „Verbunden"
  heißt nicht „Befehl erfolgreich" — und die Warnung an den
  Discord-Nutzer höchstens einmal pro Minute.

## Datenmodell

**Es gibt keinen serverbezogenen Einstellungsspeicher** — nur
`GameServer` + `FtpConfig` + `RconConfig`. Also neu:

| Entität | Zweck |
|---|---|
| `DiscordConfig` | je Server: Gilden-ID, Chat-Kanal, Chat-Bereich, Bot aktiv |
| `DiscordNotification` | je Server und Ereignisart: Schalter, **Kanal-ID**, Vorlage |
| `DiscordCommandRight` | je Unterbefehl: erlaubte Discord-Rollen |

Der **Token** ist panelweit, nicht je Server: `AppSetting`, in
`SECRET_KEYS`, verschlüsselt über `CredentialCipher`, und
`credential-field.tsx` zeigt „konfiguriert" ohne den Wert
zurückzugeben. **Achtung**: ein leeres Feld bedeutet „unverändert",
also darf das Frontend den Schlüssel dann nicht mitsenden — die
PATCH-Route deutet `''` als Löschen.

Der **öffentliche Schlüssel** (für die Signaturprüfung) ist kein
Geheimnis und darf im Klartext liegen.

## Dateien

| Was | Wo |
|---|---|
| Client | `backend/src/Server/Discord/DiscordClientInterface.php` + `RestDiscordClient` — **als Schnittstelle mit `public: true`**, wie `RconClientInterface` (`services.yaml:123-130`), sonst nicht prüfbar |
| Signatur | `Server/Discord/InteractionSignature.php` — reine Funktion über `sodium_crypto_sign_verify_detached` |
| Endpunkt | `Controller/Api/DiscordInteractionController.php` — **öffentlich**, die Signatur ist die Anmeldung |
| Befehle | `Server/Discord/Commands/` — je Bereich eine Klasse, alle über eine Registrierung |
| Rechte | `Server/Discord/CommandCapabilities.php` — die Abbildung Befehl → Panel-Berechtigung, **eine** Wahrheitsquelle |
| Vorlagen | `Server/Discord/MessageTemplate.php` — Einsetzen und Maskieren, rein und prüfbar |
| Maskierung | `Server/Discord/SecretRedaction.php` |
| Versand | `Message/SendDiscordNotification.php` + Handler, in `messenger.yaml` auf `async` geroutet |
| Anschluss | Haken in `ModerationRecorder` — **beachten: `add()` schreibt ohne `flush()`** |
| Gateway | `Command/DiscordGatewayCommand.php` + fünfter Eintrag in `docker/supervisord.conf` |
| Chat-Kanal | `Server/Chat/ChatLine.php` erweitern (**Voraussetzung für die Brücke**) |
| Oberfläche | `frontend/src/features/discord/` — Reiter `discord` (klein geschrieben!) plus eine eigene Seite für die Ereignistabelle |
| Berechtigung | `Permission::ManageDiscord = 'discord.manage'`, Gruppe `communication` |

## Wächter

- **Signaturprüfung**: gültige Signatur wird akzeptiert, **manipulierter
  Rumpf abgelehnt**, `PING` gibt `PONG`. Ohne das schaltet Discord den
  Endpunkt ab — der Test ist also nicht optional.
- **Berechtigungsgleichheit**: ein Test, der **jeden** Unterbefehl
  durchläuft und verlangt, dass er eine Panel-Berechtigung nennt. Ein
  neuer Befehl ohne Eintrag lässt ihn fehlschlagen.
- **Vorlagen**: `$1` im Wert wird kein Rückverweis; `{player}` frisst
  `{playerCount}` nicht; ein unbekanntes Token bleibt stehen; ein
  Spielername `**x**` schmuggelt keine Formatierung.
- **Maskierung**: ein Rumpf mit dem RCON-Passwort wird nicht gesendet;
  scheitert die Prüfung selbst, wird **auch nicht** gesendet.
- **Kanal-Zulassungsliste**: eine Flüster- und eine Fraktionszeile
  gehen **nicht** nach Discord. Durch Rückbau bewiesen.
- **Standardmäßig aus**: ein Test, dass jede Admin-Aktionsart ohne
  Zutun ausgeschaltet ist.

## Prüfung

Nach Regel 6b im Browser, und einiges davon braucht eine echte Gilde:

1. Token eintragen → der Bot listet die Kanäle; je Ereignis einen wählen.
2. Signatur: eine echte Interaktion von Discord (die Entwicklerkonsole
   sendet eine), plus ein von Hand verfälschter Rumpf → abgelehnt.
3. `/spieler kick` im Discord, mit **Autovervollständigung** über echte
   Spielernamen → der Spieler ist weg, und das Panel zeigt die Aktion im
   Protokoll.
4. Ein Befehl **ohne** die Panel-Berechtigung → abgelehnt, mit
   verständlichem Grund.
5. Admin-Aktion im Panel auslösen → erscheint im gewählten Kanal, mit
   dem gewählten Text.
6. Eine Aktionsart abschalten → keine Meldung mehr.
7. Gateway starten, in Discord schreiben → erscheint im Spiel; Gateway
   **beenden** → Befehle und Meldungen laufen weiter.
8. Beide Sprachen.

---

# Schritt 7 — Zeitplaner

**Voraussetzung, die in Schritt 1 erledigt wird**: `scheduler_main` wird
in ddev von niemandem konsumiert, geplante Aufgaben feuern lokal also
nie. Ohne das ist nichts prüfbar.

## Die stärkste Idee der Referenz, übernommen

**Berechtigungsgleichheit**: eine Funktion bildet jede geplante Aufgabe
auf die Berechtigung ab, die ihr interaktives Gegenstück verlangt —
geprüft **beim Anlegen und beim Ausführen**. *Etwas zu planen darf nicht
weniger kosten, als es zu tun.*

## Was wir anders machen

Ihr Aufgabentyp ist ein **freier Text**, per Präfix erkannt — ein
Tippfehler wird stillschweigend ein Rohkonsolenbefehl. Wir nehmen einen
**Aufzählungstyp mit Parametern**: `{type: 'broadcast', params: {...}}`.

Aufgabenarten: Rundruf, Speichern, Ereignis auslösen (alle 35),
Sandbox-Wert setzen, Neustart-Warnung, Sicherung. **Stoppen ja, starten
nie** — und die Oberfläche sagt das, statt einen Knopf anzubieten, der
den Server endgültig auslässt.

## Details, die zählen

- **Genau fünf Cron-Felder** — sechs (mit Sekunden) ablehnen.
- **Mindestabstand fünf Minuten**, über **alle** aufeinanderfolgenden
  Zeitpunkte einschließlich Tageswechsel. Der erste Versuch der Referenz
  ließ „5:58 und 6:00" durch.
- **Zeitzone über `\IntlDateFormatter` prüfen**, nicht über eine
  Aufzählung — die ist enger als das, was funktioniert. **Eine** Zone
  für die Installation. Bei ungültiger gespeicherter Zone: zurückfallen
  **und es sagen**.
- **Countdown als Tabelle** `[{warten, zahl}]`, Schlafen **vor** dem
  Signal, Abbruchprüfung in **beiden** Schleifen.
- **Nur ASCII** in Neustartmeldungen — `servermsg` verstümmelt Emoji.
- Ein **fehlgeschlagenes Speichern bricht den Neustart ab.**
- **Verlauf je Aufgabe begrenzen**, nicht global — bei der Referenz
  verdrängt eine gesprächige Aufgabe den Verlauf aller anderen.

Neue Aufgaben gehören in `MainSchedule`; **`src/Schedule.php` ist toter
Flex-Rumpf und wird gelöscht.**

---

# Schritt 8 — Avatare

## Die Daten werden schon geholt und weggeworfen

- `SteamProfileFetcher.php:55` liest **nur** `personaname` aus einer
  Antwort, die `avatarfull` im selben Objekt trägt. **Ein Feld mehr,
  kein zusätzlicher Aufruf.**
- `GoogleAuthenticator.php:103-104` liest `getId()` und `getName()`;
  `getAvatar()` (der `picture`-Claim) bleibt ungenutzt.

## Was neu ist

**Jede Skalierung.** Es gibt nur `imagecopy`
(`IconExtractor.php:119`), kein `imagecopyresampled`, kein WebP oder
AVIF, und **keine Prüfung, welche Formate GD hier schreiben kann**. GD
ist außerdem in `composer.json` **nicht als Anforderung deklariert**,
obwohl es benutzt wird — das gehört korrigiert.

Also: `ImageOptimiser` neu, mit einer **Fähigkeitsprüfung** beim Start
(`imagetypes()`), und die Formate, die dieses Deployment kann.

## Entscheidungen, die stehen

- **Zuschnitt wählt der Betreiber**, in einem quadratischen Rahmen mit
  Zoom und Verschieben. Ein serverseitiger Mittenschnitt rät bei den
  meisten Fotos falsch.
- **Der Server skaliert**, nie der Browser: ein Zuschnitt im Browser ist
  umgehbar, und die Datei muss ohnehin begrenzt werden.
- **Neu kodieren, nicht durchreichen** — das entfernt EXIF (inklusive
  GPS aus Handyfotos) und entschärft eine Datei, die behauptet, ein PNG
  zu sein.
- **Größen**: 128 px für Menüs, 256 px für hochauflösende Anzeige,
  512 px als größte. Kein 1920-px-Bild — das Panel zeigt nie eines.
- **Abmessungen prüfen**, nicht nur Bytes: eine „Dekompressionsbombe"
  ist klein auf Platte und riesig im Speicher. `getimagesize` kommt im
  Backend **nirgends** vor.
- **Privat ausliefern**, nach dem Vorbild von
  `VehicleModelController.php:211` (`setPrivate()`, mit der Begründung,
  dass ein geteilter Zwischenspeicher nichts für das nächste Konto
  behalten darf). Nicht wie die Icons (`setPublic()` + `immutable`).
- **Vom Anbieter geholte Bilder werden gespiegelt**, nie verlinkt:
  `img-src 'self'` bleibt wahr, und Valve und Google erfahren nichts
  über die Nutzung des Panels.

Reihenfolge der Anzeige: hochgeladen → vom Anbieter → Initialen. Es gibt
immer etwas, und nie ein kaputtes Bild.

`AvatarImage` existiert schon (`components/ui/avatar.tsx:25`) und wird
**nirgends benutzt** — die Sidebar zeigt nur Initialen.
`UserPayload.php:19-34` ist die eine Stelle für die URL.

## Wohin die Entscheidungen wandern

---
