/**
 * Display names the panel supplies where the game has none.
 *
 * The game translates 253 of the 270 sandbox options and **none** of the
 * 144 INI options — `UI_ServerOption_*` exists only as `_tooltip`, with
 * two exceptions. So the technical key was the only thing left to show,
 * and a label reading `SafehouseAllowTrepass` above an explanation of
 * what it does is a riddle with the answer printed underneath.
 *
 * These fill the gap. The key stays visible in the row beneath, so
 * nobody loses the identifier a forum post or a wiki page names.
 *
 * The game's own translation always wins: this table is consulted only
 * when it has nothing, which keeps the panel's wording out of the way
 * of the game's.
 */

/** Sandbox options the game leaves untranslated. */
export const SANDBOX_LABELS: Record<string, { de: string; en: string }> = {
  ZombieVoronoiNoise: { de: 'Verteilung nach Voronoi-Muster', en: 'Voronoi noise distribution' },
  'ZombieLore.DoorOpeningPercentage': { de: 'Anteil türöffnender Zombies', en: 'Share that opens doors' },
  'ZombieLore.FenceThumpersRequired': { de: 'Zombies für einen Zauneinbruch', en: 'Zombies needed to break a fence' },
  'ZombieLore.FenceDamageMultiplier': { de: 'Zaunschaden-Faktor', en: 'Fence damage multiplier' },
  SkillBookLoot: { de: 'Fachbücher', en: 'Skill books' },
  RecipeResourceLoot: { de: 'Rezepte und Bauteile', en: 'Recipes and components' },
  GeneratorTileRange: { de: 'Reichweite eines Generators', en: 'Generator range' },
  GeneratorVerticalPowerRange: { de: 'Etagen, die ein Generator versorgt', en: 'Floors a generator powers' },
  ClayLakeChance: { de: 'Ton an Seen', en: 'Clay by lakes' },
  ClayRiverChance: { de: 'Ton an Flüssen', en: 'Clay by rivers' },
  DayNightCycle: { de: 'Tag-und-Nacht-Wechsel', en: 'Day and night cycle' },
  ClimateCycle: { de: 'Klimawechsel', en: 'Climate cycle' },
  FogCycle: { de: 'Nebelwechsel', en: 'Fog cycle' },
  'MultiplierConfig.Glassmaking': { de: 'Glasbläserei-Multiplikator', en: 'Glassmaking multiplier' },
  AnimalTrackChance: { de: 'Tierspuren', en: 'Animal tracks' },
  AnimalPathChance: { de: 'Wildwechsel', en: 'Animal paths' },
  Version: { de: 'Dateiformat-Version', en: 'File format version' },
}

/**
 * Every INI option, since the game names none of them.
 *
 * Grouped as the game's own settings screen groups them, so a name can
 * be checked against its neighbours -- the ten AntiCheat options and the
 * six DisableRadio ones are only useful if they read differently from
 * one another at a glance.
 */
export const INI_LABELS: Record<string, { de: string; en: string }> = {
  // Details
  'DefaultPort': { de: 'UDP-Port', en: 'UDP port' },
  'PublicName': { de: 'Servername im Browser', en: 'Name in the server browser' },
  'PublicDescription': { de: 'Serverbeschreibung', en: 'Server description' },
  'Public': { de: 'Im Server-Browser listen', en: 'List in the server browser' },
  'Password': { de: 'Serverpasswort', en: 'Server password' },
  'PauseEmpty': { de: 'Zeit anhalten, wenn leer', en: 'Pause time when empty' },
  'ResetID': { de: 'Reset-Kennung', en: 'Reset ID' },

  // Steam
  'UDPPort': { de: 'Steam-UDP-Port', en: 'Steam UDP port' },
  'MaxAccountsPerUser': { de: 'Konten pro Steam-Benutzer', en: 'Accounts per Steam user' },
  'SteamScoreboard': { de: 'Steam-Namen und Avatare', en: 'Steam names and avatars' },

  // Backups
  'BackupsCount': { de: 'Aufbewahrte Backups', en: 'Backups kept' },
  'BackupsOnStart': { de: 'Backup beim Start', en: 'Backup on start' },
  'BackupsOnVersionChange': { de: 'Backup bei Versionswechsel', en: 'Backup on version change' },
  'BackupsPeriod': { de: 'Backup-Intervall', en: 'Backup interval' },

  // Players
  'MaxPlayers': { de: 'Maximale Spieler', en: 'Maximum players' },
  'Open': { de: 'Offener Zugang', en: 'Open to unregistered players' },
  'DropOffWhiteListAfterDeath': { de: 'Nach Tod von Zugangsliste entfernen', en: 'Remove from access list on death' },
  'DisplayUserName': { de: 'Benutzername über dem Kopf', en: 'User name above the head' },
  'ShowFirstAndLastName': { de: 'Vor- und Nachname anzeigen', en: 'Show first and last name' },
  'SpawnItems': { de: 'Startausrüstung', en: 'Starting items' },
  'PingLimit': { de: 'Ping-Grenze', en: 'Ping limit' },
  'ServerPlayerID': { de: 'Server-Spielerkennung', en: 'Server player ID' },
  'SleepAllowed': { de: 'Schlafen erlauben', en: 'Allow sleeping' },
  'SleepNeeded': { de: 'Schlaf ist nötig', en: 'Sleep is needed' },
  'PlayerRespawnWithSelf': { de: 'Respawn am Sterbeort', en: 'Respawn where you died' },
  'PlayerRespawnWithOther': { de: 'Respawn beim Mitspieler', en: 'Respawn at a co-op partner' },
  'RemovePlayerCorpsesOnCorpseRemoval': { de: 'Spielerleichen mitentfernen', en: 'Remove player corpses too' },
  'TrashDeleteAll': { de: 'Mülleimer restlos leeren', en: 'Empty bins completely' },
  'PVPMeleeWhileHitReaction': { de: 'Zuschlagen während Treffer', en: 'Swing while being hit' },
  'MouseOverToSeeDisplayName': { de: 'Name nur bei Mauszeiger', en: 'Name only on mouse-over' },
  'UsernameDisguises': { de: 'Namen tarnen erlauben', en: 'Allow name disguises' },
  'HideDisguisedUserName': { de: 'Getarnten Namen verbergen', en: 'Hide a disguised name' },
  'HidePlayersBehindYou': { de: 'Spieler außer Sicht verbergen', en: 'Hide players out of sight' },
  'PlayerBumpPlayer': { de: 'Spieler schubsen sich', en: 'Players bump each other' },
  'MapRemotePlayerVisibility': { de: 'Mitspieler auf der Karte', en: 'Other players on the map' },
  'AllowCoop': { de: 'Koop-Splitscreen erlauben', en: 'Allow co-op splitscreen' },

  // Admin
  'ClientCommandFilter': { de: 'Nicht protokollierte Befehle', en: 'Commands excluded from the log' },
  'ClientActionLogs': { de: 'Protokollierte Aktionen', en: 'Logged actions' },
  'PerkLogs': { de: 'Skill-Änderungen protokollieren', en: 'Log skill changes' },
  'DisableRadioStaff': { de: 'Funk für Team sperren', en: 'Block radio for all staff' },
  'DisableRadioAdmin': { de: 'Funk für Admins sperren', en: 'Block radio for admins' },
  'DisableRadioGM': { de: 'Funk für Spielleiter sperren', en: 'Block radio for game masters' },
  'DisableRadioOverseer': { de: 'Funk für Aufseher sperren', en: 'Block radio for overseers' },
  'DisableRadioModerator': { de: 'Funk für Moderatoren sperren', en: 'Block radio for moderators' },
  'DisableRadioInvisible': { de: 'Funk für Unsichtbare sperren', en: 'Block radio while invisible' },

  // Fire
  'NoFire': { de: 'Feuer weltweit deaktiviert', en: 'Fire disabled everywhere' },

  // PVP
  'PVP': { de: 'Spieler gegen Spieler', en: 'Player versus player' },
  'SafetySystem': { de: 'Sicherheitssystem', en: 'Safety system' },
  'ShowSafety': { de: 'PvP-Symbol über dem Kopf', en: 'PvP icon above the head' },
  'SafetyToggleTimer': { de: 'Umschaltdauer', en: 'Toggle duration' },
  'SafetyCooldownTimer': { de: 'Abklingzeit', en: 'Cooldown before toggling again' },
  'PVPMeleeDamageModifier': { de: 'PvP-Nahkampfschaden', en: 'PvP melee damage' },
  'PVPFirearmDamageModifier': { de: 'PvP-Schusswaffenschaden', en: 'PvP firearm damage' },

  // Loot
  'SafehousePreventsLootRespawn': { de: 'Kein Loot-Respawn in Zufluchtsorten', en: 'No loot respawn in safehouses' },
  'ItemNumbersLimitPerContainer': { de: 'Gegenstände pro Behälter', en: 'Items per container' },

  // War
  'War': { de: 'Fraktionskrieg erlauben', en: 'Allow faction war' },
  'WarStartDelay': { de: 'Vorlauf bis Kriegsbeginn', en: 'Delay before war starts' },
  'WarDuration': { de: 'Kriegsdauer', en: 'War duration' },
  'WarSafehouseHitPoints': { de: 'Trefferpunkte der Zuflucht', en: 'Safehouse hit points' },

  // Faction
  'Faction': { de: 'Fraktionen erlauben', en: 'Allow factions' },
  'FactionDaySurvivedToCreate': { de: 'Überlebte Tage zum Gründen', en: 'Days survived to found one' },
  'FactionPlayersRequiredForTag': { de: 'Mitglieder für ein Kürzel', en: 'Members needed for a tag' },

  // Safehouse
  'AdminSafehouse': { de: 'Admins dürfen beanspruchen', en: 'Admins may claim' },
  'PlayerSafehouse': { de: 'Spieler dürfen beanspruchen', en: 'Players may claim' },
  'SafehouseAllowTrepass': { de: 'Betreten durch Fremde', en: 'Non-members may enter' },
  'SafehouseAllowFire': { de: 'Feuerschaden möglich', en: 'Can be damaged by fire' },
  'SafehouseAllowLoot': { de: 'Plündern erlauben', en: 'Allow looting' },
  'SafehouseAllowRespawn': { de: 'Respawn in der Zuflucht', en: 'Respawn in the safehouse' },
  'SafehouseDaySurvivedToClaim': { de: 'Überlebte Tage zum Beanspruchen', en: 'Days survived to claim' },
  'SafeHouseRemovalTime': { de: 'Frist bis zum Verfall', en: 'Time until the claim lapses' },
  'DisableSafehouseWhenOwnerConnected': { de: 'Schutz nur bei Abwesenheit', en: 'Protected only when away' },
  'SafehouseAllowNonResidential': { de: 'Nichtwohngebäude erlauben', en: 'Allow non-residential buildings' },
  'SafehouseDisableDisguises': { de: 'Tarnung im Innern sperren', en: 'Block disguises inside' },

  // Chat
  'GlobalChat': { de: 'Globaler Chat', en: 'Global chat' },
  'AnnounceDeath': { de: 'Tod eines Spielers melden', en: 'Announce a player\'s death' },
  'AnnounceAnimalDeath': { de: 'Tod eines Tieres melden', en: 'Announce an animal\'s death' },
  'ServerWelcomeMessage': { de: 'Begrüßungsnachricht', en: 'Welcome message' },
  'ChatMessageCharacterLimit': { de: 'Zeichen pro Nachricht', en: 'Characters per message' },
  'ChatMessageSlowModeTime': { de: 'Wartezeit zwischen Nachrichten', en: 'Wait between messages' },

  // RCON
  'RCONPort': { de: 'RCON-Port', en: 'RCON port' },
  'RCONPassword': { de: 'RCON-Passwort', en: 'RCON password' },

  // Discord
  'DiscordEnable': { de: 'Discord-Anbindung', en: 'Discord integration' },
  'DiscordToken': { de: 'Bot-Token', en: 'Bot token' },
  'DiscordChatChannel': { de: 'Chat-Kanal', en: 'Chat channel' },
  'DiscordLogChannel': { de: 'Protokoll-Kanal', en: 'Log channel' },
  'DiscordCommandChannel': { de: 'Befehls-Kanal', en: 'Command channel' },

  // UPnP
  'UPnP': { de: 'Portweiterleitung per UPnP', en: 'Port forwarding via UPnP' },

  // Other
  'DoLuaChecksum': { de: 'Prüfsumme der Spieldateien', en: 'Checksum of the game files' },
  'AllowDestructionBySledgehammer': { de: 'Abriss mit Vorschlaghammer', en: 'Demolition with a sledgehammer' },
  'SledgehammerOnlyInSafehouse': { de: 'Abriss nur in eigener Zuflucht', en: 'Demolition only in your own safehouse' },
  'SaveWorldEveryMinutes': { de: 'Speicherintervall der Welt', en: 'World save interval' },
  'FastForwardMultiplier': { de: 'Zeitraffer beim Schlafen', en: 'Time speed while everyone sleeps' },
  'AllowNonAsciiUsername': { de: 'Nicht-ASCII-Benutzernamen', en: 'Non-ASCII user names' },

  // Vehicles
  'SpeedLimit': { de: 'Geschwindigkeitsbegrenzung', en: 'Speed limit' },

  // Voice
  'VoiceEnable': { de: 'Sprachchat', en: 'Voice chat' },
  'VoiceMinDistance': { de: 'Minimale Hördistanz', en: 'Minimum audible distance' },
  'VoiceMaxDistance': { de: 'Maximale Hördistanz', en: 'Maximum audible distance' },
  'Voice3D': { de: 'Richtungshören', en: 'Directional audio' },

  // Advanced
  'PVPLogToolChat': { de: 'PvP-Protokoll im Admin-Chat', en: 'PvP log in the admin chat' },
  'PVPLogToolFile': { de: 'PvP-Protokoll in Datei', en: 'PvP log to a file' },
  'ChatStreams': { de: 'Verfügbare Chat-Kanäle', en: 'Available chat channels' },
  'SwitchZombiesOwnershipEachUpdate': { de: 'Zombie-Hoheit jeden Tick wechseln', en: 'Switch zombie ownership each tick' },
  'SpawnPoint': { de: 'Fester Startpunkt', en: 'Fixed spawn point' },
  'SafetyDisconnectDelay': { de: 'Nachlauf beim Verbindungsabbruch', en: 'Delay after a disconnect' },
  'Mods': { de: 'Mod-Lade-IDs', en: 'Mod load IDs' },
  'Map': { de: 'Kartenordner', en: 'Map folders' },
  'DenyLoginOnOverloadedServer': { de: 'Anmeldung bei Überlast ablehnen', en: 'Refuse login when overloaded' },
  'MaxSafezoneSize': { de: 'Maximale Sicherheitszone', en: 'Maximum safezone size' },
  'WebhookAddress': { de: 'Slack-Webhook-URL', en: 'Slack webhook URL' },
  'KnockedDownAllowed': { de: 'Niederschlagen erlauben', en: 'Allow knockdowns' },
  'SneakModeHideFromOtherPlayers': { de: 'Schleichen verbirgt vor Spielern', en: 'Sneaking hides from players' },
  'UltraSpeedDoesnotAffectToAnimals': { de: 'Zeitraffer ohne Tiere', en: 'Fast forward excludes animals' },
  'WorkshopItems': { de: 'Workshop-IDs', en: 'Workshop IDs' },
  'SteamVAC': { de: 'Steam-VAC-Schutz', en: 'Steam VAC protection' },
  'LoginQueueEnabled': { de: 'Warteschlange beim Anmelden', en: 'Login queue' },
  'LoginQueueConnectTimeout': { de: 'Zeitlimit in der Warteschlange', en: 'Login queue timeout' },
  'server_browser_announced_ip': { de: 'Angekündigte IP-Adresse', en: 'Announced IP address' },
  'BloodSplatLifespanDays': { de: 'Haltbarkeit von Blutspritzern', en: 'Lifespan of blood splatter' },
  'BanKickGlobalSound': { de: 'Ton bei Bann und Kick', en: 'Sound on ban and kick' },
  'CarEngineAttractionModifier': { de: 'Motorlärm zieht Zombies an', en: 'Engine noise attracts zombies' },
  'DisableVehicleTowing': { de: 'Fahrzeuge abschleppen sperren', en: 'Block towing vehicles' },
  'DisableTrailerTowing': { de: 'Anhänger abschleppen sperren', en: 'Block towing trailers' },
  'DisableBurntTowing': { de: 'Ausgebrannte abschleppen sperren', en: 'Block towing burnt-out wrecks' },
  'BadWordListFile': { de: 'Datei mit verbotenen Wörtern', en: 'File of forbidden words' },
  'GoodWordListFile': { de: 'Datei mit erlaubten Wörtern', en: 'File of permitted words' },
  'BadWordPolicy': { de: 'Maßnahme bei Schimpfwort', en: 'Action on a bad word' },
  'BadWordReplacement': { de: 'Ersatztext für Schimpfwörter', en: 'Replacement for bad words' },
  'AntiCheatSafety': { de: 'Anti-Cheat gesamt', en: 'Anti-cheat overall' },
  'AntiCheatSpeed': { de: 'Anti-Cheat Bewegungstempo', en: 'Anti-cheat movement speed' },
  'AntiCheatNoClip': { de: 'Anti-Cheat Wanddurchgang', en: 'Anti-cheat no-clip' },
  'AntiCheatHit': { de: 'Anti-Cheat Treffer', en: 'Anti-cheat hits' },
  'AntiCheatPacketException': { de: 'Anti-Cheat Paketprüfung', en: 'Anti-cheat packet checks' },
  'AntiCheatPermission': { de: 'Anti-Cheat Befugnisse', en: 'Anti-cheat permissions' },
  'AntiCheatXP': { de: 'Anti-Cheat Erfahrungspunkte', en: 'Anti-cheat experience points' },
  'AntiCheatSafeHouse': { de: 'Anti-Cheat Zufluchtsorte', en: 'Anti-cheat safehouses' },
  'AntiCheatPlayer': { de: 'Anti-Cheat Spielerdaten', en: 'Anti-cheat player data' },
  'AntiCheatChecksum': { de: 'Anti-Cheat Prüfsummen', en: 'Anti-cheat checksums' },
  'MultiplayerStatisticsPeriod': { de: 'Statistik-Intervall', en: 'Statistics interval' },
  'DisableScoreboard': { de: 'Spielerliste deaktivieren', en: 'Disable the scoreboard' },
  'HideAdminsInPlayerList': { de: 'Admins in der Liste verbergen', en: 'Hide admins in the player list' },
  'MaxPacketsPerSecond': { de: 'Pakete pro Sekunde', en: 'Packets per second' },
  'ShowCoordinates': { de: 'Koordinaten anzeigen', en: 'Show coordinates' },
  'Seed': { de: 'Welt-Seed', en: 'World seed' },
  'UsePhysicsHitReaction': { de: 'Physikalische Trefferreaktion', en: 'Physics-based hit reaction' },
}
