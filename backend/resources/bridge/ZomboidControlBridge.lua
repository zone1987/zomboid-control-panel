--[[
    ZomboidControl bridge — server side.

    Writes JSON snapshots of server state into files the control panel
    reads over FTP or SFTP. Lua on the server has no HTTP client and no
    sockets, so files are the only way out.

    Output lands in the Zomboid data folder, under Lua/ZomboidControl:

        players.json    who is connected, with position and condition
        server.json     time, weather and how long the server has been up
        safehouses.json claimed safehouses and their members
        vehicles.json   loaded vehicles: position, facing, paint and wear
        factions.json   factions and who belongs to them

    Since 0.8.0 it also reads. The panel writes numbered command files
    into the same directory and the bridge answers each with a result
    file, so the panel can ask the server to do something rather than
    only watch what it wrote.

    Install: upload to media/lua/server on the dedicated server, then
    restart it. The panel uploads this file for you.
]]

local BRIDGE_VERSION = "0.21.0"

-- getFileWriter writes into ~/Zomboid/Lua, which is documented.
-- getModFileWriter targets the mod's own common/ directory instead, and
-- its behaviour for a mod without one is not established.
local PLAYERS_FILE = "ZomboidControl/players.json"
local SERVER_FILE = "ZomboidControl/server.json"
local SAFEHOUSES_FILE = "ZomboidControl/safehouses.json"
local VEHICLES_FILE = "ZomboidControl/vehicles.json"
local FACTIONS_FILE = "ZomboidControl/factions.json"
-- Written once per server start: the catalogue only changes when mods do,
-- and it is several thousand entries.
local ITEMS_FILE = "ZomboidControl/items.json"

--- Which vehicles this server can spawn, and the liveries of each.
--- Separate from vehicles.json, which is the ones placed in the world.
local VEHICLE_CATALOGUE_FILE = "ZomboidControl/vehicle-catalogue.json"
-- Diagnostic: which media files the server actually has, so the panel
-- knows whether icons can be extracted at all.
local PROBE_FILE = "ZomboidControl/probe.json"

-- The command queue. The panel writes commands/cmd-<seq>.json and the
-- bridge answers with results/res-<seq>.json; each side owns its files
-- and only reads the other's.
local COMMAND_FILE = "ZomboidControl/commands/cmd-%d.json"
local RESULT_FILE = "ZomboidControl/results/res-%d.json"
local CURSOR_FILE = "ZomboidControl/cursor.json"
-- What the panel declares it has written, read only to recover from a
-- desync -- see resyncCursor.
local PANEL_CURSOR_FILE = "ZomboidControl/panel-cursor.json"

local COMMAND_PROTOCOL = "queue-v1"

-- How long to wait on a missing sequence number before suspecting the
-- two sides have drifted apart rather than that nothing was sent.
local SECONDS_BEFORE_RESYNC = 10
local SECONDS_BETWEEN_COMMAND_CHECKS = 1
-- A tick reads at most this many commands, so a backlog drains over
-- several ticks instead of stalling one.
local MAX_COMMANDS_PER_TICK = 20

-- Build 42 has no server-side event for a player joining or leaving:
-- OnPlayerConnect and OnPlayerDisconnect do not exist, and OnConnected
-- and OnDisconnect fire in the client only. The roster is therefore
-- checked on every tick -- reading a size and a name per player is
-- cheap -- and the file is written the moment it differs.
--
-- Intervals are counted in real seconds rather than ticks. GameServer
-- compiles in FPS = 10 and holds it with a 100 ms limiter, but that is a
-- ceiling rather than a rate: under load a cycle takes longer and OnTick
-- fires less often. Measured on a live server, 10 per second while empty
-- and 5 with a player on it.
--
-- getTimestamp wraps System.currentTimeMillis, so it is wall-clock time,
-- unaffected by tick rate, pause or the sandbox day length. Note that
-- getGametimeTimestamp is in-game time despite the similar name, and the
-- Every* events hang off the same in-game clock -- neither is usable for
-- a real-time interval.
local SECONDS_BETWEEN_FULL_WRITES = 3

-- Time and weather are two object reads -- getGameTime and
-- getClimateManager -- so they cost about what the roster costs and are
-- written often enough that the panel shows a change it just made rather
-- than the state from a minute ago.
local SECONDS_BETWEEN_WORLD_WRITES = 10

-- Safehouses, vehicles and factions each walk a list, and the vehicle
-- walk covers the whole world. They change slowly and are worth the
-- longer interval.
local SECONDS_BETWEEN_SLOW_WRITES = 60

local lastPlayerWrite = 0
local lastWorldWrite = 0
local lastSlowWrite = 0
local lastRoster = ""

-- Identifies this run of the server. Written into every file, so the
-- panel can tell a restart from a routine update without comparing
-- timestamps or file sizes: a different id means everything the server
-- loaded was loaded afresh, mods included.
local SESSION_ID = tostring(getTimestampMs())

local function escape(text)
    if text == nil then return "" end

    text = tostring(text)
    text = text:gsub("\\", "\\\\")
    text = text:gsub("\"", "\\\"")
    text = text:gsub("\n", "\\n")
    text = text:gsub("\r", "\\r")
    text = text:gsub("\t", "\\t")

    return text
end

local function writeFile(name, contents)
    local writer = getFileWriter(name, true, false)

    if writer == nil then
        print("[ZomboidControl] Could not open " .. name .. " for writing.")
        return
    end

    writer:write(contents)
    writer:close()
end

--- Reads a file line by line; nil when it is not there.
local function readFile(name)
    local reader = getFileReader(name, false)

    if reader == nil then
        return nil
    end

    local lines = {}
    local line = reader:readLine()

    while line ~= nil do
        lines[#lines + 1] = line
        line = reader:readLine()
    end

    reader:close()

    return table.concat(lines, "\n")
end

--- The smallest JSON reader that covers what the panel sends.
---
--- Only flat objects of strings, numbers and booleans are supported --
--- that is the whole command shape -- so there is no recursion and no
--- array handling to get wrong.
---
--- Strings are scanned character by character rather than matched: Lua
--- patterns have no alternation, so "(.-)" stops at an escaped quote
--- and truncates the value without saying so.
local function decodeFlatObject(text)
    if text == nil then
        return nil
    end

    local fields = {}
    local position = 1
    local length = #text

    local function readString()
        -- Called with position on the opening quote.
        local pieces = {}
        position = position + 1

        while position <= length do
            local character = text:sub(position, position)

            if character == '"' then
                position = position + 1

                return table.concat(pieces)
            end

            if character == "\\" then
                local escaped = text:sub(position + 1, position + 1)

                if escaped == "n" then
                    pieces[#pieces + 1] = "\n"
                elseif escaped == "r" then
                    pieces[#pieces + 1] = "\r"
                elseif escaped == "t" then
                    pieces[#pieces + 1] = "\t"
                else
                    pieces[#pieces + 1] = escaped
                end

                position = position + 2
            else
                pieces[#pieces + 1] = character
                position = position + 1
            end
        end

        return table.concat(pieces)
    end

    while position <= length do
        local character = text:sub(position, position)

        if character == '"' then
            local key = readString()

            -- Skip whitespace and the colon.
            while position <= length and text:sub(position, position):match("[%s:]") do
                position = position + 1
            end

            local next = text:sub(position, position)

            if next == '"' then
                fields[key] = readString()
            elseif next == "t" and text:sub(position, position + 3) == "true" then
                fields[key] = true
                position = position + 4
            elseif next == "f" and text:sub(position, position + 4) == "false" then
                fields[key] = false
                position = position + 5
            else
                local number = text:match("^(-?%d+%.?%d*)", position)

                if number ~= nil then
                    fields[key] = tonumber(number)
                    position = position + #number
                else
                    position = position + 1
                end
            end
        else
            position = position + 1
        end
    end

    return fields
end

--- Skills the character has actually trained; level 0 entries are
--- skipped so the payload stays small.
---
--- Walks the perk table the way the game's own ISPerkLog does. The
--- character's perkList is not usable from Lua: PerkInfo.perk is a
--- public field with no getter, so it reads nil and every entry was
--- dropped silently.
local function describeSkills(player)
    local ok, entries = pcall(function()
        local found = {}

        for index = 0, Perks.getMaxIndex() - 1 do
            local id = Perks.fromIndex(index)
            local perk = PerkFactory.getPerk(id)
            local level = player:getPerkLevel(id)

            -- A perk without a parent is a category heading rather
            -- than a skill; the game's own character sheet skips those.
            -- Compared by id, because Perks.None is a static field and
            -- a nil there must not discard every skill.
            local parent = perk ~= nil and perk:getParent() or nil
            local isCategory = parent == nil or parent:getId() == "None"

            if perk ~= nil and level ~= nil and level > 0 and not isCategory then
                table.insert(found, string.format(
                    "\"%s\":%d",
                    escape(perk:getId()),
                    level
                ))
            end
        end

        return found
    end)

    if not ok or entries == nil then
        return "{}"
    end

    return "{" .. table.concat(entries, ",") .. "}"
end

local function describeTraits(player)
    local traits = player:getCharacterTraits()

    if traits == nil then
        return "[]"
    end

    local known = traits:getKnownTraits()

    if known == nil then
        return "[]"
    end

    local entries = {}

    for i = 0, known:size() - 1 do
        local trait = known:get(i)

        if trait ~= nil then
            table.insert(entries, string.format("\"%s\"", escape(trait:getName())))
        end
    end

    return "[" .. table.concat(entries, ",") .. "]"
end

local function describePlayer(player)
    local body = player:getBodyDamage()

    local parts = {
        string.format("\"username\":\"%s\"", escape(player:getUsername())),
        -- getSteamID returns a long; %d keeps all 17 digits, and it is
        -- quoted because JSON consumers lose precision past 2^53.
        string.format("\"steamId\":\"%d\"", player:getSteamID()),
        string.format("\"x\":%.1f", player:getX()),
        string.format("\"y\":%.1f", player:getY()),
        string.format("\"z\":%.1f", player:getZ()),
        string.format("\"health\":%.3f", player:getHealth()),
        string.format("\"hoursSurvived\":%.1f", player:getHoursSurvived()),
        -- What a leaderboard needs, and both are plain ints on
        -- IsoGameCharacter. Free here: the roster is already being walked.
        string.format("\"zombieKills\":%d", player:getZombieKills()),
        string.format("\"survivorKills\":%d", player:getSurvivorKills()),
        string.format("\"accessLevel\":\"%s\"", escape(player:getAccessLevel())),
    }

    if body ~= nil then
        -- Both isInfected() and IsInfected() exist and differ. The
        -- lowercase one is the plain "is this character infected" flag.
        table.insert(parts, string.format("\"infected\":%s", tostring(body:isInfected())))
        table.insert(parts, string.format("\"infectionLevel\":%.3f", body:getApparentInfectionLevel()))
    end

    table.insert(parts, string.format("\"skills\":%s", describeSkills(player)))
    table.insert(parts, string.format("\"traits\":%s", describeTraits(player)))

    return "{" .. table.concat(parts, ",") .. "}"
end

--- Just the names, joined. Comparing this against the previous tick is
--- what stands in for the join and leave events the API does not have.
local function rosterOf(players)
    if players == nil then
        return ""
    end

    local names = {}

    for i = 0, players:size() - 1 do
        local player = players:get(i)

        if player ~= nil then
            table.insert(names, player:getUsername())
        end
    end

    return table.concat(names, "\30")
end

local function writePlayers(players)
    local entries = {}

    if players ~= nil then
        for i = 0, players:size() - 1 do
            local player = players:get(i)

            if player ~= nil then
                table.insert(entries, describePlayer(player))
            end
        end
    end

    writeFile(PLAYERS_FILE, string.format(
        "{\"bridgeVersion\":\"%s\",\"sessionId\":\"%s\",\"generatedAt\":%d,\"playerCount\":%d,\"players\":[%s]}",
        BRIDGE_VERSION,
        SESSION_ID,
        getTimestamp(),
        #entries,
        table.concat(entries, ",")
    ))
end

--- Every way of asking, in order, stopping at the first that answers.
local function readMaxPlayers()
    local options = getServerOptions()

    if options == nil then
        return nil
    end

    local attempts = {
        function() return options:getInteger("MaxPlayers") end,
        function() return tonumber(options:getOption("MaxPlayers")) end,
        function() return options:getMaxPlayers() end,
    }

    for _, ask in ipairs(attempts) do
        local ok, value = pcall(ask)

        if ok and type(value) == "number" then
            return value
        end
    end

    return nil
end

local function writeServerInfo()
    local time = getGameTime()
    local climate = getClimateManager()

    local parts = {
        string.format("\"bridgeVersion\":\"%s\"", BRIDGE_VERSION),
        string.format("\"sessionId\":\"%s\"", SESSION_ID),
        string.format("\"generatedAt\":%d", getTimestamp()),
    }

    -- Each block is attempted on its own: one method that turns out to
    -- need an argument on the server should cost its own field, not the
    -- whole file.
    if time ~= nil then
        local ok, value = pcall(function()
            return string.format(
                "\"gameTime\":{\"year\":%d,\"month\":%d,\"day\":%d,\"hour\":%d,\"minute\":%d,\"daysSurvived\":%d}",
                time:getYear(), time:getMonth(), time:getDay(),
                time:getHour(), time:getMinutes(), time:getDaysSurvived()
            )
        end)

        if ok then table.insert(parts, value) end
    end

    if climate ~= nil then
        local ok, value = pcall(function()
            return string.format(
                "\"weather\":{\"temperature\":%.1f,\"raining\":%s,\"snowing\":%s,"
                    .. "\"windSpeed\":%.1f,\"maxWindSpeed\":%.1f,\"season\":\"%s\"}",
                climate:getTemperature(),
                tostring(climate:isRaining()),
                tostring(climate:isSnowing()),
                climate:getWindspeedKph(),
                -- The game's own ceiling, so the panel's wind control can
                -- speak km/h instead of an abstract 0..100 that reads as a
                -- different number than the one it then displays.
                climate:getMaxWindspeedKph(),
                escape(climate:getSeasonName())
            )
        end)

        if ok then table.insert(parts, value) end
    end

    -- The bare global getMaxPlayers() takes an argument on the server and
    -- throws "Not enough arguments" without one -- its documented
    -- zero-argument form is client-side. The options table is read
    -- instead, and each candidate is tried separately so a failure costs
    -- one field rather than the whole file.
    local maxPlayers = readMaxPlayers()

    if maxPlayers ~= nil then
        table.insert(parts, string.format("\"maxPlayers\":%d", maxPlayers))
    end

    writeFile(SERVER_FILE, "{" .. table.concat(parts, ",") .. "}")
end

local function describeSafehouse(house)
    local members = {}
    local players = house:getPlayers()

    if players ~= nil then
        for i = 0, players:size() - 1 do
            local name = players:get(i)

            if name ~= nil then
                table.insert(members, string.format("\"%s\"", escape(name)))
            end
        end
    end

    return string.format(
        "{\"title\":\"%s\",\"owner\":\"%s\",\"x\":%d,\"y\":%d,\"w\":%d,\"h\":%d,\"members\":[%s]}",
        escape(house:getTitle()),
        escape(house:getOwner()),
        house:getX(), house:getY(), house:getW(), house:getH(),
        table.concat(members, ",")
    )
end

local function writeSafehouses()
    local list = SafeHouse.getSafehouseList()
    local entries = {}

    if list ~= nil then
        for i = 0, list:size() - 1 do
            local house = list:get(i)

            if house ~= nil then
                -- One malformed safehouse should not cost the whole list.
                local ok, entry = pcall(describeSafehouse, house)

                if ok then table.insert(entries, entry) end
            end
        end
    end

    writeFile(SAFEHOUSES_FILE, string.format(
        "{\"bridgeVersion\":\"%s\",\"sessionId\":\"%s\",\"generatedAt\":%d,\"safehouses\":[%s]}",
        BRIDGE_VERSION,
        SESSION_ID,
        getTimestamp(),
        table.concat(entries, ",")
    ))
end

--- Reports what the server actually has on disk under media/.
---
--- Written once at startup so the panel can tell whether icons are
--- reachable at all: a hosted server often ships without graphics, and
--- guessing from the outside is unreliable.
local function writeProbe()
    -- Paths need the "media/" prefix: without it nothing opens, with it
    -- media/inventory/BerettaClip.png reads back its 420 bytes.
    local candidates = {
        "media/texturepacks/UI.pack",
        "media/texturepacks/UI2.pack",
        "media/texturepacks/ApComUI.pack",
        "media/ui/Item_Bag_Schoolbag.png",
        "media/textures/WorldItems/Item_Bag.png",
        "media/inventory/BerettaClip.png",
        "media/items/items.xml",
    }

    local parts = {}

    for _, name in ipairs(candidates) do
        local size = -1
        local ok, stream = pcall(getGameFilesInput, name)

        if ok and stream ~= nil then
            local gotSize, value = pcall(function() return stream:available() end)
            size = (gotSize and type(value) == "number") and value or 0
            pcall(function() stream:close() end)
        end

        table.insert(parts, string.format("\"%s\":%d", escape(name), size))
    end

    -- Where the game unpacked itself, which says whether the texture
    -- packs are simply somewhere else.
    local roots = {}

    for _, ask in ipairs({
        { "cacheDir", function() return getCacheDir() end },
        { "activeMods", function() return #getActivatedMods() end },
    }) do
        local got, value = pcall(ask[2])
        table.insert(roots, string.format(
            "\"%s\":\"%s\"",
            ask[1],
            escape(got and tostring(value) or "?")
        ))
    end

    -- Proves the whole path end to end: read a known binary file and
    -- write it back out where the panel can fetch it. If this arrives
    -- byte-identical, icons can travel the same way.
    local copied = -1
    local okCopy = pcall(function()
        local input = getGameFilesInput("media/inventory/BerettaClip.png")

        if input == nil then
            return
        end

        local output = getFileOutput("ZomboidControl/copy-test.png")

        if output == nil then
            pcall(function() input:close() end)
            return
        end

        local written = 0

        while true do
            local byte = input:read()

            if byte == nil or byte < 0 then
                break
            end

            output:write(byte)
            written = written + 1
        end

        input:close()
        output:close()
        copied = written
    end)

    if not okCopy then
        copied = -2
    end

    writeFile(PROBE_FILE, string.format(
        "{\"bridgeVersion\":\"%s\",\"generatedAt\":%d,\"copyTestBytes\":%d,\"roots\":{%s},\"mediaFiles\":{%s}}",
        BRIDGE_VERSION,
        getTimestamp(),
        copied,
        table.concat(roots, ","),
        table.concat(parts, ",")
    ))
end

--- Every item the server knows, base game and mods alike.
---
--- Icons are named, not embedded: Lua has only a text writer, so it
--- cannot read or copy a PNG. The name is what the panel needs to find
--- the picture elsewhere.
local function describeItem(item)
    local fullType = item:getFullName()

    if fullType == nil or fullType == "" then
        return nil
    end

    local parts = {
        string.format("\"type\":\"%s\"", escape(fullType)),
        string.format("\"name\":\"%s\"", escape(item:getDisplayName())),
    }

    -- Translated through the server's own language files, which are
    -- present even on an installation without graphics.
    local ok, translated = pcall(getItemNameFromFullType, fullType)

    if ok and translated ~= nil and translated ~= "" then
        parts[2] = string.format("\"name\":\"%s\"", escape(translated))
    end

    local optional = {
        { "icon", function() return item:getIcon() end, "%s" },
        { "category", function() return item:getDisplayCategory() end, "%s" },
        { "itemType", function() return item:getItemType() end, "%s" },
        { "module", function() return item:getModuleName() end, "%s" },
    }

    for _, field in ipairs(optional) do
        local got, value = pcall(field[2])

        if got and value ~= nil and tostring(value) ~= "" then
            table.insert(parts, string.format("\"%s\":\"%s\"", field[1], escape(value)))
        end
    end

    local gotWeight, weight = pcall(function() return item:getActualWeight() end)

    if gotWeight and type(weight) == "number" then
        table.insert(parts, string.format("\"weight\":%.3f", weight))
    end

    return "{" .. table.concat(parts, ",") .. "}"
end

local function writeItems()
    local items = getAllItems()

    if items == nil then
        return
    end

    local writer = getFileWriter(ITEMS_FILE, true, false)

    if writer == nil then
        print("[ZomboidControl] Could not open " .. ITEMS_FILE .. " for writing.")
        return
    end

    writer:write(string.format(
        "{\"bridgeVersion\":\"%s\",\"sessionId\":\"%s\",\"generatedAt\":%d,\"items\":[",
        BRIDGE_VERSION,
        SESSION_ID,
        getTimestamp()
    ))

    -- Written incrementally rather than joined in memory: five thousand
    -- entries in one Lua string is a lot of garbage to make at once.
    local written = 0

    for i = 0, items:size() - 1 do
        local item = items:get(i)

        if item ~= nil then
            local ok, entry = pcall(describeItem, item)

            if ok and entry ~= nil then
                if written > 0 then
                    writer:write(",")
                end

                writer:write(entry)
                written = written + 1
            end
        end
    end

    writer:write(string.format("],\"itemCount\":%d}", written))
    writer:close()

    print("[ZomboidControl] Wrote " .. written .. " items.")
end

--- One spawnable vehicle: which script it is and which model it uses.
---
--- Deliberately not its textures. The panel already holds those, keyed
--- by script name, generated from the game's own vehicle scripts -- and
--- the artwork they name has to be uploaded by the operator anyway,
--- since it is The Indie Stone's. What only the server knows is which
--- vehicles exist, mods included, and that is what this reports.
local function describeVehicleScript(name, script)
    local parts = { string.format("\"script\":\"%s\"", escape(name)) }

    -- getModel() hands back a Model object, not a string: the file name
    -- is on it, and printing the object gives a Java identity hash.
    local gotModel, model = pcall(function() return script:getModel() end)

    if gotModel and model ~= nil then
        local gotFile, file = pcall(function() return model:getFile() end)

        if gotFile and file ~= nil and tostring(file) ~= "" then
            table.insert(parts, string.format("\"model\":\"%s\"", escape(tostring(file))))
        end

        local gotScale, scale = pcall(function() return model:getScale() end)

        if gotScale and type(scale) == "number" and scale > 0 then
            table.insert(parts, string.format("\"scale\":%.4f", scale))
        end
    end

    local gotName, full = pcall(function() return script:getFullName() end)

    if gotName and full ~= nil and tostring(full) ~= "" then
        table.insert(parts, string.format("\"fullName\":\"%s\"", escape(tostring(full))))
    end

    return "{" .. table.concat(parts, ",") .. "}"
end

--- Every vehicle this server can spawn, written incrementally.
---
--- getAllVehicles() is the list the server actually has, mods included,
--- which is why the panel asks rather than shipping a table of its own.
local function writeVehicleCatalogue()
    local ok, names = pcall(getAllVehicles)

    if not ok or names == nil then
        return
    end

    local manager = nil
    local gotManager, found = pcall(getScriptManager)

    if gotManager then
        manager = found
    end

    local writer = getFileWriter(VEHICLE_CATALOGUE_FILE, true, false)

    if writer == nil then
        print("[ZomboidControl] Could not open " .. VEHICLE_CATALOGUE_FILE .. " for writing.")
        return
    end

    writer:write(string.format(
        "{\"bridgeVersion\":\"%s\",\"sessionId\":\"%s\",\"generatedAt\":%d,\"vehicles\":[",
        BRIDGE_VERSION,
        SESSION_ID,
        getTimestamp()
    ))

    local written = 0

    for i = 0, names:size() - 1 do
        local name = names:get(i)

        if name ~= nil and tostring(name) ~= "" then
            local script = nil

            if manager ~= nil then
                local gotScript, found2 = pcall(function()
                    return manager:getVehicle(tostring(name))
                end)

                if gotScript then
                    script = found2
                end
            end

            local entry

            if script ~= nil then
                local described, value = pcall(describeVehicleScript, tostring(name), script)
                entry = described and value or nil
            end

            -- A script the manager will not hand over still belongs in
            -- the list: it can be spawned by name even undrawable.
            if entry == nil then
                entry = string.format("{\"script\":\"%s\"}", escape(tostring(name)))
            end

            if written > 0 then
                writer:write(",")
            end

            writer:write(entry)
            written = written + 1
        end
    end

    writer:write(string.format("],\"vehicleCount\":%d}", written))
    writer:close()

    print("[ZomboidControl] Wrote " .. written .. " vehicle scripts.")
end

--- Wraps a write so a fault in one file cannot stop the others.

--- The list of loaded vehicles, from whichever source answers.
--- Calls a getter and keeps the answer only if it is a number.
---
--- Through pcall rather than "if vehicle.getX then": a Java method is
--- not a truthy Lua field, and guarding that way reported a live server
--- with 21 vehicles as having none.
local function number(object, getter)
    local ok, value = pcall(getter, object)

    return (ok and type(value) == "number") and value or nil
end

--- A number for JSON, or the literal null.
local function decimal(value, places)
    if value == nil then
        return "null"
    end

    return string.format("%." .. places .. "f", value)
end

local function indexable(list)
    -- Usable if it answers size(). The game's own code iterates a
    -- cell's vehicles with size() and get(i-1) -- see
    -- client/Vehicles/ISUI/ISVehicleBloodUI.lua -- so answering size()
    -- is what marks a source this build exposes to Lua.
    --
    -- get() is deliberately not probed. An empty list has no element 0,
    -- so probing it threw and the source was thrown away: with nobody
    -- near a vehicle every source looked broken and the panel was told
    -- "none" rather than "none loaded".
    if list == nil then
        return false
    end

    local ok, size = pcall(function() return list:size() end)

    return ok and type(size) == "number"
end

--- The vehicles this server has loaded, and where they came from.
---
--- Three sources, because which of them a dedicated server exposes to
--- Lua is not settled: no shipped Lua file enumerates vehicles
--- server-side at all, and VehicleManager appears nowhere in media/lua.
--- The source is reported to the panel so an empty list can be told
--- apart from an unreadable one.
local function vehicleList()
    local tried = {}

    for _, ask in ipairs({
        { "cell", function() return getCell():getVehicles() end },
        { "manager", function() return VehicleManager.instance:getVehicles() end },
        { "world", function() return getWorld():getCell():getVehicles() end },
    }) do
        local ok, list = pcall(ask[2])

        if ok and indexable(list) then
            return list, ask[1], tried
        end

        tried[#tried + 1] = string.format(
            "%s:%s",
            ask[1],
            ok and (list == nil and "nil" or "not-indexable") or "threw"
        )
    end

    return nil, "none", tried
end

--- Every vehicle the server currently has loaded.
---
--- Position prefers the square and falls back to the vehicle's own
--- coordinates, so a loaded vehicle is never dropped for want of one.
---
--- Written on the slow interval with the rest of the world state:
--- vehicles move, but not so fast that three seconds would show
--- anything the panel could not read a minute later.
local function writeVehicles()
    local entries = {}
    local vehicles, from, tried = vehicleList()

    if vehicles ~= nil then
        for i = 0, vehicles:size() - 1 do
            local vehicle = vehicles:get(i)

            if vehicle ~= nil then
                local square = vehicle:getSquare()
                -- Read back rather than assumed: a script name is not
                -- guaranteed, and neither is a readable fuel level.
                local script = vehicle:getScriptName()
                local fuel = number(vehicle, vehicle.getRemainingFuelPercentage)

                local running = false
                local engineOk, engineValue = pcall(vehicle.isEngineRunning, vehicle)

                if engineOk then
                    running = engineValue == true
                end

                -- getAngleY, not getAngleZ: the physics transform puts
                -- world height on its y axis, so rotation about y is
                -- the way the vehicle faces on the ground. x and z are
                -- pitch and roll.
                --
                -- Wrapped into 0..360 so it matches what the panel
                -- computes from the save file's quaternion; getAngleY
                -- itself returns a signed Euler angle.
                local angle = number(vehicle, vehicle.getAngleY)

                if angle ~= nil then
                    angle = (angle % 360 + 360) % 360
                end

                -- The paint. Only the live object has it: in the save
                -- file it sits behind the part list, whose entries
                -- carry nested inventory items of variable length.
                local hue = number(vehicle, vehicle.getColorHue)
                local saturation = number(vehicle, vehicle.getColorSaturation)
                local value = number(vehicle, vehicle.getColorValue)
                local rust = number(vehicle, vehicle.getRust)
                local skin = number(vehicle, vehicle.getSkinIndex)

                -- getX/getY on the vehicle itself when it has no
                -- square: a loaded vehicle would otherwise be dropped
                -- without a word.
                local x = square ~= nil and square:getX() or vehicle:getX()
                local y = square ~= nil and square:getY() or vehicle:getY()
                local z = square ~= nil and square:getZ() or vehicle:getZ()

                entries[#entries + 1] = string.format(
                    '{"id":%d,"script":"%s","x":%d,"y":%d,"z":%d,'
                    .. '"angle":%s,"fuel":%s,"engineRunning":%s,'
                    .. '"hue":%s,"saturation":%s,"value":%s,'
                    .. '"rust":%s,"skin":%s}',
                    vehicle:getId() or 0,
                    escape(script or "unknown"),
                    x or 0,
                    y or 0,
                    z or 0,
                    decimal(angle, 1),
                    decimal(fuel, 1),
                    running and "true" or "false",
                    decimal(hue, 4),
                    decimal(saturation, 4),
                    decimal(value, 4),
                    decimal(rust, 4),
                    skin ~= nil and string.format("%d", skin) or "null"
                )
            end
        end
    end

    writeFile(VEHICLES_FILE, string.format(
        '{"bridgeVersion":"%s","sessionId":"%s","generatedAt":%d,'
        .. '"source":"%s","loaded":%d,"tried":"%s","vehicles":[%s]}',
        BRIDGE_VERSION,
        SESSION_ID,
        getTimestamp(),
        from,
        vehicles ~= nil and vehicles:size() or -1,
        escape(table.concat(tried, ",")),
        table.concat(entries, ",")
    ))
end


--- The factions and who is in them.
---
--- Not a map layer of its own -- a faction has no position -- but the
--- panel shows it beside the safehouses, and both come from the same
--- slow write.
local function writeFactions()
    local entries = {}
    local factions = Faction.getFactions()

    if factions ~= nil then
        for i = 0, factions:size() - 1 do
            local faction = factions:get(i)
            local members = {}
            local players = faction:getPlayers()

            if players ~= nil then
                for m = 0, players:size() - 1 do
                    members[#members + 1] = '"' .. escape(players:get(m)) .. '"'
                end
            end

            entries[#entries + 1] = string.format(
                '{"name":"%s","owner":"%s","tag":"%s","members":[%s]}',
                escape(faction:getName() or ""),
                escape(faction:getOwner() or ""),
                escape(faction:getTag() or ""),
                table.concat(members, ",")
            )
        end
    end

    writeFile(FACTIONS_FILE, string.format(
        '{"bridgeVersion":"%s","sessionId":"%s","generatedAt":%d,"factions":[%s]}',
        BRIDGE_VERSION, SESSION_ID, getTimestamp(), table.concat(entries, ",")
    ))
end

local function attempt(what, write, ...)
    local ok, err = pcall(write, ...)

    if not ok then
        print("[ZomboidControl] Failed to write " .. what .. ": " .. tostring(err))
    end
end


-- ---------------------------------------------------------------------
-- Command queue
-- ---------------------------------------------------------------------

-- The highest command this bridge has processed. Persisted so a restart
-- does not replay everything the panel ever sent.
local lastCommandSeq = 0
local lastCommandCheck = 0
-- When the next expected file first went missing, so a genuine gap can
-- be told from an idle queue.
local waitingSince = 0

local function writeCursor()
    writeFile(CURSOR_FILE, string.format(
        '{"protocol":"%s","sessionId":"%s","lastCommandSeq":%d,"bridgeVersion":"%s"}',
        COMMAND_PROTOCOL,
        SESSION_ID,
        lastCommandSeq,
        BRIDGE_VERSION
    ))
end

local function readCursor()
    local fields = decodeFlatObject(readFile(CURSOR_FILE))

    if fields ~= nil and type(fields.lastCommandSeq) == "number" then
        lastCommandSeq = math.floor(fields.lastCommandSeq)
    end
end

--- Answers one command.
---
--- Written before the next command is read, so a crash loses at most the
--- answer rather than leaving the panel waiting on a number that will
--- never appear.
local function writeResult(seq, ok, message, data)
    writeFile(string.format(RESULT_FILE, seq), string.format(
        '{"protocol":"%s","sessionId":"%s","seq":%d,"ok":%s,"message":"%s","data":%s,"at":%d}',
        COMMAND_PROTOCOL,
        SESSION_ID,
        seq,
        ok and "true" or "false",
        escape(message or ""),
        data or "null",
        getTimestamp()
    ))
end

local function findPlayer(name)
    if name == nil or name == "" then
        return nil
    end

    local players = getOnlinePlayers()

    for i = 0, players:size() - 1 do
        local player = players:get(i)

        if player:getUsername() == name then
            return player
        end
    end

    return nil
end

--- What the panel may ask for.
---
--- Each handler returns ok, message, data. A handler confirms its work by
--- reading the value back where that is safe: a setter that does not
--- throw has not necessarily done anything.

--- The three encoders the surroundings handler needs.
---
--- Written out rather than a generic serialiser: the shapes are fixed
--- and a generic one would be more code than the three together.
local function encodeItems(items)
    local parts = {}

    for fullType, entry in pairs(items) do
        parts[#parts + 1] = string.format(
            '{"type":"%s","name":"%s","count":%d,"x":%d,"y":%d}',
            escape(fullType), escape(entry.name), entry.count, entry.x, entry.y
        )
    end

    return "[" .. table.concat(parts, ",") .. "]"
end

local function encodeContainers(containers)
    local parts = {}

    for _, entry in ipairs(containers) do
        parts[#parts + 1] = string.format(
            '{"x":%d,"y":%d,"type":"%s","count":%d,"contents":[%s]}',
            entry.x, entry.y, escape(entry.type), entry.count,
            table.concat(entry.contents or {}, ",")
        )
    end

    return "[" .. table.concat(parts, ",") .. "]"
end

local function encodeRooms(rooms)
    local parts = {}

    for name, squares in pairs(rooms) do
        parts[#parts + 1] = string.format('{"name":"%s","squares":%d}', escape(name), squares)
    end

    return "[" .. table.concat(parts, ",") .. "]"
end

local handlers = {}

handlers.ping = function()
    return true, "pong", string.format('{"players":%d}', getOnlinePlayers():size())
end

handlers.setTime = function(command)
    local hour = tonumber(command.hour)

    if hour == nil or hour < 0 or hour > 24 then
        return false, "hour must be between 0 and 24"
    end

    local time = getGameTime()
    time:setTimeOfDay(hour)

    -- Read back: setTimeOfDay writes the field this getter reads, so the
    -- confirmation is real rather than hopeful.
    local now = time:getTimeOfDay()

    if math.abs(now - hour) > 0.5 then
        return false, string.format("time is %.2f after asking for %.2f", now, hour)
    end

    return true, "time set", string.format('{"timeOfDay":%.2f}', now)
end

handlers.setDate = function(command)
    local day = tonumber(command.day)
    local month = tonumber(command.month)

    local time = getGameTime()

    if month ~= nil then
        if month < 1 or month > 12 then
            return false, "month must be between 1 and 12"
        end

        -- The engine counts months from zero; the panel counts from one.
        time:setMonth(math.floor(month) - 1)
    end

    if day ~= nil then
        if day < 1 or day > 31 then
            return false, "day must be between 1 and 31"
        end

        time:setDay(math.floor(day))
    end

    return true, "date set", string.format(
        '{"day":%d,"month":%d}',
        time:getDay(),
        time:getMonth() + 1
    )
end

handlers.startRain = function(command)
    local intensity = tonumber(command.intensity) or 50

    if intensity < 0 or intensity > 100 then
        return false, "intensity must be between 0 and 100"
    end

    local climate = getClimateManager()
    climate:transmitServerStartRain(intensity / 100)

    -- transmitServerStartRain runs the climate update before returning,
    -- so this reads the new value rather than the previous tick's.
    return true, "rain started", string.format(
        '{"intensity":%.2f}',
        climate:getPrecipitationIntensity()
    )
end

handlers.stopRain = function()
    local climate = getClimateManager()
    climate:transmitServerStopRain()

    if climate:isRaining() then
        return false, "the server still reports rain"
    end

    return true, "rain stopped"
end

handlers.setClimateValue = function(command)
    local index = tonumber(command.index)
    local value = tonumber(command.value)

    if index == nil or index < 0 or index > 12 then
        return false, "index must be between 0 and 12"
    end

    if value == nil then
        return false, "value is required"
    end

    local float = getClimateManager():getClimateFloat(math.floor(index))

    if float == nil then
        return false, "no climate value at that index"
    end

    float:setEnableAdmin(true)
    float:setAdminValue(value)

    -- getFinalValue is not refreshed until the next game tick, so it
    -- would prove nothing here. getAdminValue is a plain field read of
    -- what was just written, after the engine's own clamping.
    local applied = float:getAdminValue()

    return true, "climate value set", string.format(
        '{"index":%d,"value":%.4f,"asked":%.4f}',
        math.floor(index),
        applied,
        value
    )
end

--- Hands a climate value back to the game.
--
-- setClimateValue pins a value with setEnableAdmin(true), which holds it
-- there until something releases it. There is no season-appropriate
-- number the panel could set instead: the game already knows it, so
-- releasing the pin is what "back to normal" means.
handlers.releaseClimate = function(command)
    local index = tonumber(command.index)

    if index == nil or index < 0 or index > 12 then
        return false, "index must be between 0 and 12"
    end

    local float = getClimateManager():getClimateFloat(math.floor(index))

    if float == nil then
        return false, "no climate value at that index"
    end

    float:setEnableAdmin(false)

    return true, "climate value released", string.format(
        '{"index":%d,"admin":%s,"value":%.4f}',
        math.floor(index),
        tostring(float:isEnableAdmin()),
        float:getFinalValue()
    )
end

--- Makes the precipitation snow, and makes it stay snow.
--
-- setPrecipitationIsSnow writes ClimateBool.finalValue and nothing else,
-- and getPrecipitationIsSnow reads that same field back -- so a read-back
-- there only proves the write happened, never that the game kept it. The
-- next calculate() tick recomputes finalValue from the season and the
-- snow is gone, which is exactly what a warm-season server showed: -6C
-- and still raining.
--
-- The admin override is what holds. It is also the route the game's own
-- admin panel takes (ISAdmPanelClimate.lua:418).
handlers.setSnow = function(command)
    local snowing = command.snowing == true or command.snowing == "true"
    local climate = getClimateManager()
    local isSnow = climate:getClimateBool(0)

    if isSnow == nil then
        return false, "the server has no snow flag"
    end

    isSnow:setEnableAdmin(true)
    isSnow:setAdminValue(snowing)

    -- finalValue too, so the current tick shows it rather than waiting
    -- for the next climate update.
    climate:setPrecipitationIsSnow(snowing)

    local kept = isSnow:getAdminValue()

    if kept ~= snowing then
        return false, "the server would not change the precipitation type", string.format(
            '{"asked":%s,"snowing":%s,"temperature":%.1f}',
            tostring(snowing),
            tostring(kept),
            climate:getTemperature()
        )
    end

    return true, snowing and "precipitation is snow" or "precipitation is rain", string.format(
        '{"snowing":%s,"pinned":%s,"temperature":%.1f}',
        tostring(kept),
        tostring(isSnow:isEnableAdmin()),
        climate:getTemperature()
    )
end

handlers.startBlizzard = function()
    local climate = getClimateManager()

    -- The game's own winter storm rather than a stack of climate values:
    -- it sets the precipitation, the wind and the temperature together,
    -- the way the season does.
    climate:triggerWinterIsComingStorm()

    return true, "winter storm triggered", string.format(
        '{"snowing":%s,"windSpeed":%.1f}',
        tostring(climate:isSnowing()),
        climate:getWindspeedKph()
    )
end

handlers.stopWeather = function()
    local climate = getClimateManager()

    -- stopWeatherAndThunder, not transmitServerStopRain: the RCON
    -- stopweather leaves the thunder running, and "stop" should stop it.
    climate:stopWeatherAndThunder()

    return true, "weather stopped", string.format(
        '{"raining":%s,"snowing":%s}',
        tostring(climate:isRaining()),
        tostring(climate:isSnowing())
    )
end

--- The panel's names for the thirteen climate floats, by index.
--
-- The game keeps its own names (getName() returns "TEMPERATURE"), but it
-- offers no lookup by name -- getClimateFloat takes an index only -- so
-- the pairing lives here and in BridgeCommand::CLIMATE_VALUES, which a
-- test holds together.
local CLIMATE_FLOATS = {
    [0] = "desaturation", [1] = "globalLight", [2] = "nightStrength",
    [3] = "precipitation", [4] = "temperature", [5] = "fog",
    [6] = "wind", [7] = "windAngle", [8] = "clouds",
    [9] = "ambient", [10] = "viewDistance", [11] = "daylight",
    [12] = "humidity",
}

--- Every climate value at once, so the panel can show what it is about
--- to change rather than only what it set last.
--
-- Each float reports its own min and max as well as its value: the game
-- declares them (setup() overrides three of the thirteen), setAdminValue
-- clamps to them silently, and a panel guessing them would offer a
-- slider whose end does something other than it says.
handlers.readClimate = function()
    local climate = getClimateManager()
    local parts = {}

    for index = 0, 12 do
        local float = climate:getClimateFloat(index)
        local name = CLIMATE_FLOATS[index]

        if float ~= nil and name ~= nil then
            table.insert(parts, string.format(
                '"%s":{"index":%d,"value":%.4f,"admin":%s,"adminValue":%.4f,'
                    .. '"min":%.4f,"max":%.4f}',
                name,
                index,
                float:getFinalValue(),
                tostring(float:isEnableAdmin()),
                float:getAdminValue(),
                float:getMin(),
                float:getMax()
            ))
        end
    end

    -- The one climate boolean the game keeps, and it carries its own
    -- admin override like a float does.
    local isSnow = climate:getClimateBool(0)
    local snowPinned = "null"

    if isSnow ~= nil then
        -- getFinalValue exists on ClimateFloat but NOT on ClimateBool,
        -- where finalValue is protected with a setter only. The manager's
        -- own getter reads that same field.
        snowPinned = string.format(
            '{"value":%s,"admin":%s,"adminValue":%s}',
            tostring(climate:getPrecipitationIsSnow()),
            tostring(isSnow:isEnableAdmin()),
            tostring(isSnow:getAdminValue())
        )
    end

    return true, "climate read", string.format(
        '{"values":{%s},"precipitationIsSnow":%s,"windSpeedKph":%.1f,'
            .. '"maxWindSpeedKph":%.1f,"snowing":%s,"raining":%s,'
            .. '"thunderStorming":%s,"season":"%s","seasonProgression":%.3f,'
            .. '"airMass":%.3f,"frontStrength":%.3f}',
        table.concat(parts, ","),
        snowPinned,
        climate:getWindspeedKph(),
        climate:getMaxWindspeedKph(),
        tostring(climate:isSnowing()),
        tostring(climate:isRaining()),
        tostring(climate:getIsThunderStorming()),
        escape(climate:getSeasonName()),
        climate:getSeasonProgression(),
        climate:getAirMass(),
        climate:getFrontStrength()
    )
end

--- Releases every pinned climate value at once.
--
-- resetAdmin() is the game's own "hand it all back", which the admin
-- panel's own reset uses. Thirteen separate releases would leave the
-- world half-pinned if one failed.
handlers.resetClimate = function()
    local climate = getClimateManager()

    climate:resetAdmin()

    local pinned = 0

    for index = 0, 12 do
        local float = climate:getClimateFloat(index)

        if float ~= nil and float:isEnableAdmin() then
            pinned = pinned + 1
        end
    end

    return true, "climate reset", string.format('{"stillPinned":%d}', pinned)
end

--- Hands the snow flag back to the game.
--
-- setSnow pins it, and a pinned flag holds through the season change --
-- so releasing it is how the world goes back to deciding for itself.
handlers.releaseSnow = function()
    local isSnow = getClimateManager():getClimateBool(0)

    if isSnow == nil then
        return false, "the server has no snow flag"
    end

    isSnow:setEnableAdmin(false)

    return true, "snow flag released", string.format(
        '{"admin":%s,"value":%s}',
        tostring(isSnow:isEnableAdmin()),
        tostring(getClimateManager():getPrecipitationIsSnow())
    )
end

--- One of the game's own weather stages, with a duration in game hours.
--
-- This is the route the game's own admin panel takes
-- (ISAdmPanelWeather.lua:174/181/188): triggerCustomWeatherStage with
-- the stage constant. A blizzard, a tropical storm and a plain storm are
-- the same call with a different stage, so they are one handler.
--
-- The stage numbers are read from WeatherPeriod when it is reachable and
-- fall back to the values javap reports for build 42. A module-level
-- WeatherPeriod.X would run at load time, and a missing class there
-- would take the whole bridge down rather than one handler.
local WEATHER_STAGES = {
    showers = 1, heavyPrecip = 2, storm = 3, clearing = 4,
    moderate = 5, drizzle = 6, blizzard = 7, tropical = 8,
}

local function stageNumber(name)
    local constants = {
        showers = "STAGE_SHOWERS", heavyPrecip = "STAGE_HEAVY_PRECIP",
        storm = "STAGE_STORM", clearing = "STAGE_CLEARING",
        moderate = "STAGE_MODERATE", drizzle = "STAGE_DRIZZLE",
        blizzard = "STAGE_BLIZZARD", tropical = "STAGE_TROPICAL_STORM",
    }

    local ok, value = pcall(function()
        return WeatherPeriod[constants[name]]
    end)

    if ok and type(value) == "number" then
        return value
    end

    return WEATHER_STAGES[name]
end

handlers.triggerWeatherStage = function(command)
    local stage = command.stage ~= nil and stageNumber(command.stage) or nil
    local duration = tonumber(command.duration) or 4

    if stage == nil then
        return false, "unknown weather stage"
    end

    if duration < 1 or duration > 240 then
        return false, "duration must be between 1 and 240 game hours"
    end

    local climate = getClimateManager()

    if not climate:triggerCustomWeatherStage(stage, duration) then
        return false, "the server refused the weather stage"
    end

    return true, "weather stage triggered", string.format(
        '{"stage":"%s","duration":%.1f,"raining":%s,"snowing":%s,"windSpeed":%.1f}',
        escape(tostring(command.stage)),
        duration,
        tostring(climate:isRaining()),
        tostring(climate:isSnowing()),
        climate:getWindspeedKph()
    )
end

--- Generates a weather front, warm or cold, at a chosen strength.
--
-- The game's own "generate weather" (ISAdmPanelWeather.lua:196): unlike a
-- stage this lets the simulation decide what actually arrives, which is
-- how the world produces weather when nobody interferes.
handlers.generateWeather = function(command)
    local strength = tonumber(command.strength) or 0.5
    local warm = command.front ~= "cold"

    if strength < 0.1 or strength > 1 then
        return false, "strength must be between 0.1 and 1"
    end

    if not getClimateManager():triggerCustomWeather(strength, warm) then
        return false, "the server refused to generate weather"
    end

    return true, "weather generated", string.format(
        '{"strength":%.2f,"front":"%s"}',
        strength,
        warm and "warm" or "cold"
    )
end

handlers.playSound = function(command)
    local radius = tonumber(command.radius) or 100
    local volume = tonumber(command.volume) or 100
    local x = tonumber(command.x)
    local y = tonumber(command.y)

    if x == nil or y == nil then
        local player = findPlayer(command.player)

        if player == nil then
            return false, "needs coordinates or the name of an online player"
        end

        x = player:getX()
        y = player:getY()
    end

    addSound(nil, x, y, tonumber(command.z) or 0, radius, volume)

    return true, "sound placed", string.format('{"x":%d,"y":%d}', x, y)
end

--- Heals a player completely.
--
-- The route the game itself takes, from server Lua
-- (ClientCommands.lua:551-559 and :596): every body part is restored
-- one at a time and each is synchronised, because BodyDamage's own
-- RestoreToFullHealth exists but the game does not use it -- and the
-- part-by-part loop is the path that is proven to reach clients.
--
-- The stiffness is cleared as well, which the game does in the same
-- handler: a healed character that still aches reads as a half-done job.
handlers.healPlayer = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    local damage = player:getBodyDamage()

    if damage == nil then
        return false, "the player has no body damage to heal"
    end

    local parts = damage:getBodyParts()
    local healed = 0

    for i = 1, parts:size() do
        local part = parts:get(i - 1)

        if part ~= nil then
            part:RestoreToFullHealth()
            -- The mask the game passes, which syncs every field of the
            -- part rather than a chosen few.
            syncBodyPart(part, 0xFFFFFFFFFFF)
            healed = healed + 1
        end
    end

    local fitness = player:getFitness()

    if fitness ~= nil then
        -- Same names the game clears in its own heal.
        for _, group in ipairs({ "Cardio", "Strength", "Aerobics" }) do
            pcall(function()
                fitness:removeStiffnessValue(group)
            end)
        end
    end

    return true, "player healed", string.format(
        '{"player":"%s","parts":%d,"health":%.3f,"infected":%s}',
        escape(player:getUsername()),
        healed,
        player:getHealth(),
        tostring(damage:isInfected())
    )
end

--- The character statistics, by the game's own registry.
--
-- Build 42 replaced the individual setters with set(CharacterStat,
-- float), and CharacterStat is a class with a REGISTRY rather than an
-- enum -- so a stat is looked up by its id and carries its own minimum
-- and maximum. The panel never hard-codes those: a stat clamps to them
-- silently, exactly as the climate values do.
--- The twenty-four statistics the game registers, by their own ids.
--
-- From CharacterStat's public constants in build 42. Named here because
-- ORDERED_STATS is a Java array rather than a list, which Lua cannot
-- walk -- and because a registry read at load time would tie the bridge
-- to whichever mods had registered by then.
local CHARACTER_STATS = {
    "Anger", "Boredom", "Discomfort", "Endurance", "Fatigue", "Fitness",
    "FoodSickness", "Hunger", "Idleness", "Intoxication", "Morale",
    "NicotineWithdrawal", "Pain", "Panic", "Poison", "Sanity", "Sickness",
    "Stress", "Temperature", "Thirst", "Unhappiness", "Wetness",
    "ZombieFever", "ZombieInfection",
}

local function characterStat(id)
    if type(id) ~= "string" or id == "" then
        return nil
    end

    local ok, stat = pcall(function()
        return CharacterStat.getById(id)
    end)

    return ok and stat or nil
end

handlers.readPlayerStats = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    local stats = player:getStats()

    if stats == nil then
        return false, "the player has no statistics"
    end

    local parts = {}

    -- The twenty-four by name rather than through ORDERED_STATS, which
    -- javap shows to be a Java **array** (CharacterStat[]) -- :size() and
    -- :get() do not exist on one, and nothing in the game's own Lua
    -- iterates it, so it would fail on the live server. getById is the
    -- documented lookup and each name is a public constant.
    for _, id in ipairs(CHARACTER_STATS) do
        local stat = characterStat(id)

        if stat ~= nil then
            table.insert(parts, string.format(
                '"%s":{"value":%.4f,"min":%.4f,"max":%.4f,"default":%.4f}',
                escape(stat:getId()),
                stats:get(stat),
                stat:getMinimumValue(),
                stat:getMaximumValue(),
                stat:getDefaultValue()
            ))
        end
    end

    local weight = "null"
    local nutrition = player:getNutrition()

    if nutrition ~= nil then
        weight = string.format("%.1f", nutrition:getWeight())
    end

    local profession = "null"
    local descriptor = player:getDescriptor()

    if descriptor ~= nil then
        local job = descriptor:getCharacterProfession()

        if job ~= nil then
            profession = string.format('"%s"', escape(job:getName()))
        end
    end

    return true, "statistics read", string.format(
        '{"player":"%s","stats":{%s},"weight":%s,"profession":%s}',
        escape(player:getUsername()),
        table.concat(parts, ","),
        weight,
        profession
    )
end

--- Resolves a perk by its id, the way describeSkills walks them.
---
--- Perks.FromString exists but is not used: it takes the *display* name
--- while the panel sends the id, and the two differ for four perks
--- (Woodwork is shown as Carpentry, PlantScavenging as Foraging).
local function perkById(id)
    for index = 0, Perks.getMaxIndex() - 1 do
        local candidate = Perks.fromIndex(index)
        local perk = PerkFactory.getPerk(candidate)

        if perk ~= nil and perk:getId() == id then
            return candidate, perk
        end
    end

    return nil, nil
end

--- Sets one skill to an exact level.
---
--- Goes through level0 + setXPToLevel rather than setPerkLevelDebug,
--- which writes PerkInfo.level directly and then only calls
--- GameClient.sendPerks when GameClient.client is true — so on a server
--- the level would change with the player's own sheet never told.
---
--- LevelPerk(perk) with one argument spends one of the player's real
--- unspent skill points per call; the two-argument overload does not.
--- That distinction comes from the reference bridge's own notes and is
--- confirmed by the two overloads existing on IsoGameCharacter.
--- Everything the character sheet shows per skill.
---
--- Four numbers the level alone cannot give: how much XP sits inside
--- the current level and how much the next one needs (so a bar can show
--- the remainder), the profession/trait boost the game colours the name
--- by (0..3, gold at 3), and the book multiplier, which is > 0 only
--- while a read book is still in effect.
---
--- getXpForLevel and getMultiplier are methods; XPMultiplier's own
--- fields are not reachable, which is why the float is asked for
--- directly rather than the object.
handlers.readSkillDetail = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    local ok, entries = pcall(function()
        local xp = player:getXp()
        local found = {}

        for index = 0, Perks.getMaxIndex() - 1 do
            local candidate = Perks.fromIndex(index)
            local perk = PerkFactory.getPerk(candidate)

            local parent = perk ~= nil and perk:getParent() or nil
            local isCategory = parent == nil or parent:getId() == "None"

            if perk ~= nil and not isCategory then
                local level = player:getPerkLevel(candidate)

                -- Total XP the character holds in this skill.
                local held = xp ~= nil and xp:getXP(candidate) or 0

                -- What this level started at and what the next needs.
                local floorXp = level > 0 and perk:getTotalXpForLevel(level) or 0
                local nextXp = level < 10 and perk:getTotalXpForLevel(level + 1) or floorXp

                local boost = 0
                local multiplier = 0

                if xp ~= nil then
                    pcall(function() boost = xp:getPerkBoost(candidate) or 0 end)
                    pcall(function() multiplier = xp:getMultiplier(candidate) or 0 end)
                end

                table.insert(found, string.format(
                    '"%s":{"level":%d,"xp":%.1f,"levelFloor":%.1f,"nextLevel":%.1f,"boost":%d,"multiplier":%.2f}',
                    escape(perk:getId()),
                    level,
                    held,
                    floorXp,
                    nextXp,
                    boost,
                    multiplier
                ))
            end
        end

        return found
    end)

    if not ok or entries == nil then
        return false, "the server would not report the skills"
    end

    return true, "skills read", "{\"skills\":{" .. table.concat(entries, ",") .. "}}"
end

handlers.setSkillLevel = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    local id = tostring(command.skill or "")
    local wanted = tonumber(command.level)

    if id == "" or wanted == nil then
        return false, "a skill and a level are needed"
    end

    wanted = math.floor(wanted)

    if wanted < 0 or wanted > 10 then
        return false, "a level runs from 0 to 10"
    end

    local ok, result = pcall(function()
        local candidate, perk = perkById(id)

        if candidate == nil then
            return { found = false }
        end

        local before = player:getPerkLevel(candidate)

        -- Back to nothing first, so going down works as well as up.
        player:level0(candidate)

        if wanted > 0 then
            for _ = 1, wanted do
                player:LevelPerk(candidate, false)
            end

            -- Lands the within-level XP exactly on the boundary instead
            -- of leaving it wherever the loop stopped.
            local xp = player:getXp()

            if xp ~= nil then
                pcall(function() xp:setXPToLevel(candidate, wanted) end)
            end
        end

        return {
            found = true,
            before = before,
            after = player:getPerkLevel(candidate),
            name = perk:getName(),
        }
    end)

    if not ok or result == nil then
        return false, "the server would not take that level"
    end

    if not result.found then
        return false, string.format("the game has no skill called %s", id)
    end

    if result.after ~= wanted then
        return false, string.format(
            "the level stayed at %d instead of %d",
            result.after,
            wanted
        )
    end

    return true, "skill level set", string.format(
        '{"skill":"%s","before":%d,"after":%d}',
        escape(id),
        result.before,
        result.after
    )
end

--- Adds raw experience to one skill.
---
--- addXpNoMultiplier is the one route that reaches the player: it tests
--- GameServer.server and hands off to GameServer.addXp, which finds the
--- player's connection and calls NetworkPlayerAI.updateXpChecker. The
--- character's own XP:AddXP would change the number server-side with
--- the client never told.
handlers.addSkillXp = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    local id = tostring(command.skill or "")
    local amount = tonumber(command.amount)

    if id == "" or amount == nil then
        return false, "a skill and an amount are needed"
    end

    if amount <= 0 or amount > 1000000 then
        return false, "the amount is out of range"
    end

    local multiplied = command.multiplied == true or command.multiplied == "true"

    local ok, result = pcall(function()
        local candidate, perk = perkById(id)

        if candidate == nil then
            return { found = false }
        end

        local xp = player:getXp()
        local before = xp ~= nil and xp:getXP(candidate) or 0
        local levelBefore = player:getPerkLevel(candidate)

        if multiplied then
            addXp(player, candidate, amount)
        else
            addXpNoMultiplier(player, candidate, amount)
        end

        return {
            found = true,
            xpBefore = before,
            xpAfter = xp ~= nil and xp:getXP(candidate) or 0,
            levelBefore = levelBefore,
            levelAfter = player:getPerkLevel(candidate),
            name = perk:getName(),
        }
    end)

    if not ok or result == nil then
        return false, "the server would not take that experience"
    end

    if not result.found then
        return false, string.format("the game has no skill called %s", id)
    end

    return true, "experience added", string.format(
        '{"skill":"%s","level":%d,"levelBefore":%d,"xp":%.1f}',
        escape(id),
        result.levelAfter,
        result.levelBefore,
        result.xpAfter
    )
end

--- Adds or removes one character trait.
---
--- The three steps are the game's own, from
--- client/ISUI/PlayerStats/ISPlayerStatsUI.lua:591-597: add the trait,
--- then modifyTraitXPBoost so its skill bonuses actually apply, then
--- push what can be pushed. `add` alone leaves a trait that is listed
--- but does nothing.
---
--- The client's own display does not update until it reconnects:
--- SyncXp is guarded by GameClient.client and is a no-op on a server,
--- and the ExtraInfo packet carries roles and cheats but no traits. The
--- panel says so rather than implying the player sees it at once.
handlers.setTrait = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    local name = tostring(command.trait or "")

    if name == "" then
        return false, "no trait was named"
    end

    local adding = command.adding == true or command.adding == "true"

    local ok, result = pcall(function()
        -- CharacterTrait.get takes a ResourceLocation, not a string;
        -- the static fields are unreachable from Lua.
        local trait = CharacterTrait.get(ResourceLocation.of(name))

        if trait == nil then
            return { found = false }
        end

        local traits = player:getCharacterTraits()

        if traits == nil then
            return { found = true, applied = false, why = "no trait list" }
        end

        if adding then
            traits:add(trait)
        else
            traits:remove(trait)
        end

        -- Without this the trait is inert: its XP boosts are held on the
        -- character, not on the trait.
        pcall(function() player:modifyTraitXPBoost(trait, not adding) end)

        -- The one server-side broadcast that exists. It carries no
        -- traits, so this is for anything else riding along rather than
        -- for the trait itself.
        pcall(function()
            if sendPlayerExtraInfo ~= nil then
                sendPlayerExtraInfo(player)
            end
        end)

        return { found = true, applied = traits:get(trait) == adding }
    end)

    if not ok or result == nil then
        return false, "the server would not take that trait"
    end

    if not result.found then
        return false, string.format("the game has no trait called %s", name)
    end

    if not result.applied then
        return false, "the trait did not change"
    end

    return true, adding and "trait added" or "trait removed", string.format(
        '{"trait":"%s","adding":%s,"visibleAfterReconnect":true}',
        escape(name),
        tostring(adding)
    )
end

--- Every trait the character has, by the game's own name for it.
handlers.readTraits = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    return true, "traits read", string.format(
        '{"traits":%s,"profession":%s}',
        describeTraits(player),
        (function()
            local ok, name = pcall(function()
                local desc = player:getDescriptor()
                local prof = desc ~= nil and desc:getCharacterProfession() or nil

                return prof ~= nil and prof:getName() or nil
            end)

            if not ok or name == nil then
                return "null"
            end

            return string.format('"%s"', escape(name))
        end)()
    )
end

handlers.setPlayerStat = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    local stat = characterStat(command.stat)

    if stat == nil then
        return false, "there is no statistic by that name"
    end

    local value = tonumber(command.value)

    if value == nil then
        return false, "value is required"
    end

    local low, high = stat:getMinimumValue(), stat:getMaximumValue()

    if value < low or value > high then
        return false, string.format(
            "%s must be between %.2f and %.2f",
            escape(stat:getId()),
            low,
            high
        )
    end

    local stats = player:getStats()

    if stats == nil then
        return false, "the player has no statistics"
    end

    stats:set(stat, value)

    -- Read back rather than trusting set()'s own boolean, which is
    -- false when the value did not *change* -- setting Boredom to 0
    -- while it is already 0 returned false and was reported as a
    -- refusal, which it is not. What the stat holds afterwards is the
    -- only honest answer.
    local applied = stats:get(stat)

    if math.abs(applied - value) > 0.0001 then
        return false, "the server would not take that value", string.format(
            '{"player":"%s","stat":"%s","value":%.4f,"asked":%.4f}',
            escape(player:getUsername()),
            escape(stat:getId()),
            applied,
            value
        )
    end

    return true, "statistic set", string.format(
        '{"player":"%s","stat":"%s","value":%.4f,"asked":%.4f}',
        escape(player:getUsername()),
        escape(stat:getId()),
        applied,
        value
    )
end

--- The weight, which lives on the nutrition rather than the stats.
handlers.setPlayerWeight = function(command)
    local player = findPlayer(command.player)

    if player == nil then
        return false, "that player is not online"
    end

    local weight = tonumber(command.weight)

    if weight == nil or weight < 30 or weight > 200 then
        return false, "weight must be between 30 and 200"
    end

    local nutrition = player:getNutrition()

    if nutrition == nil then
        return false, "the player has no nutrition"
    end

    nutrition:setWeight(weight)

    return true, "weight set", string.format(
        '{"player":"%s","weight":%.1f,"asked":%.1f}',
        escape(player:getUsername()),
        nutrition:getWeight(),
        weight
    )
end

--- The two climate colours, read as RGBA for inside and outside.
--
-- COLOR_GLOBAL_LIGHT is 0 and COLOR_NEW_FOG is 1, from ClimateManager's
-- own constants. Each carries an admin override like a float does, and
-- each holds two colours: what the world looks like outdoors and what it
-- looks like under a roof.
--
-- Note getAlphaFloat rather than getA: Color has getR/getG/getB but no
-- getA, which would fail at run time on the live server the way the snow
-- flag did.
local function colourParts(info)
    if info == nil then
        return "null"
    end

    local outside = info:getExterior()
    local inside = info:getInterior()

    -- Named rgb rather than colour: `colour` is this bridge's name for a
    -- ClimateColor, and these getters live on zombie.core.Color. Keeping
    -- the two apart is what lets a test check each call against the right
    -- class.
    local function rgba(rgb)
        if rgb == nil then
            return "null"
        end

        return string.format(
            '{"r":%.3f,"g":%.3f,"b":%.3f,"a":%.3f}',
            rgb:getR(),
            rgb:getG(),
            rgb:getB(),
            rgb:getAlphaFloat()
        )
    end

    return string.format('{"exterior":%s,"interior":%s}', rgba(outside), rgba(inside))
end

local CLIMATE_COLOURS = { [0] = "globalLight", [1] = "fog" }

handlers.readClimateColours = function()
    local climate = getClimateManager()
    local parts = {}

    for index = 0, 1 do
        local colour = climate:getClimateColor(index)
        local name = CLIMATE_COLOURS[index]

        if colour ~= nil and name ~= nil then
            table.insert(parts, string.format(
                '"%s":{"index":%d,"admin":%s,"value":%s,"adminValue":%s}',
                name,
                index,
                tostring(colour:isEnableAdmin()),
                colourParts(colour:getFinalValue()),
                colourParts(colour:getAdminValue())
            ))
        end
    end

    return true, "climate colours read", string.format('{"colours":{%s}}', table.concat(parts, ","))
end

--- Sets one climate colour, indoors and out.
handlers.setClimateColour = function(command)
    local index = command.name == "globalLight" and 0 or command.name == "fog" and 1 or nil

    if index == nil then
        return false, "name must be globalLight or fog"
    end

    local colour = getClimateManager():getClimateColor(index)

    if colour == nil then
        return false, "no climate colour at that index"
    end

    local function channel(key, fallback)
        local value = tonumber(command[key])

        if value == nil then
            return fallback
        end

        return math.max(0, math.min(1, value))
    end

    local r, g, b, a = channel("r", 1), channel("g", 1), channel("b", 1), channel("a", 1)

    colour:setEnableAdmin(true)
    -- Exterior and interior together, in that order, which is what the
    -- eight-argument setAdminValue takes.
    colour:setAdminValue(r, g, b, a, r, g, b, a)

    return true, "climate colour set", string.format(
        '{"name":"%s","admin":%s,"adminValue":%s}',
        tostring(command.name),
        tostring(colour:isEnableAdmin()),
        colourParts(colour:getAdminValue())
    )
end

handlers.releaseClimateColour = function(command)
    local index = command.name == "globalLight" and 0 or command.name == "fog" and 1 or nil

    if index == nil then
        return false, "name must be globalLight or fog"
    end

    local colour = getClimateManager():getClimateColor(index)

    if colour == nil then
        return false, "no climate colour at that index"
    end

    colour:setEnableAdmin(false)

    return true, "climate colour released", string.format(
        '{"name":"%s","admin":%s}',
        tostring(command.name),
        tostring(colour:isEnableAdmin())
    )
end

--- A lightning strike at a point on the map.
--
-- RCON's lightning takes a player name and nothing else, so a strike
-- could never be placed. triggerThunderEvent(x, y, strike, flash, rumble)
-- takes the coordinates and the three parts separately -- which is the
-- one call the game itself makes from server Lua
-- (server/ClientCommands.lua:634), so this is the proven path rather
-- than an inferred one.
handlers.strikeLightning = function(command)
    local x = tonumber(command.x)
    local y = tonumber(command.y)

    if x == nil or y == nil then
        return false, "x and y are required"
    end

    if x < 0 or x > 20000 or y < 0 or y > 20000 then
        return false, "the coordinates are outside the world"
    end

    local storm = getClimateManager():getThunderStorm()

    if storm == nil then
        return false, "the server has no thunderstorm"
    end

    -- Each part is separate: a rumble alone is distant thunder, a flash
    -- is lightning without damage, and a strike sets fire to what it hits.
    local strike = command.strike == true or command.strike == "true"
    local flash = command.flash ~= false and command.flash ~= "false"
    local rumble = command.rumble ~= false and command.rumble ~= "false"

    storm:triggerThunderEvent(math.floor(x), math.floor(y), strike, flash, rumble)

    return true, "lightning triggered", string.format(
        '{"x":%d,"y":%d,"strike":%s,"flash":%s,"rumble":%s}',
        math.floor(x),
        math.floor(y),
        tostring(strike),
        tostring(flash),
        tostring(rumble)
    )
end

--- How many days into the apocalypse the world currently is.
--
-- The formula is the game's own, from ISVehicleMenu.lua:1089: the world
-- age in days plus thirty days for each month the scenario started after
-- the outbreak. Utilities are on while that number is below their shut
-- modifier, so this is what a switch has to move around.
local function apocalypseDay()
    local sandbox = getSandboxOptions()

    return getGameTime():getWorldAgeHours() / 24 + (sandbox:getTimeSinceApo() - 1) * 30
end

--- The option names the game uses for the two utilities.
--
-- Reached by name through getOptionByName and set through
-- SandboxOptions::set(String, Object), because the public *fields*
-- (elecShutModifier and friends) are not reachable from Lua: indexing
-- one returns null, and the live server answered "attempted index:
-- getValueAsObject of non-table: null". Methods are.
local UTILITY_OPTIONS = {
    power = { name = "ElecShutModifier", read = "getElecShutModifier" },
    water = { name = "WaterShutModifier", read = "getWaterShutModifier" },
}

--- Reads whether a utility is still running, and its cut-off day.
local function utilityState(which)
    local option = UTILITY_OPTIONS[which]

    if option == nil then
        return nil
    end

    local sandbox = getSandboxOptions()
    local day = apocalypseDay()
    local shutAt = sandbox[option.read](sandbox)

    if type(shutAt) ~= "number" then
        shutAt = tonumber(tostring(shutAt))
    end

    if shutAt == nil then
        return nil
    end

    -- -1 is the game's own "never": the utility stays on forever.
    local on = shutAt == -1 or day < shutAt

    return on, shutAt, day
end

--- Turns the power or the water on or off.
--
-- There is no on/off flag in the game: a utility runs until the world is
-- older than its shut modifier, in days. So switching it off means
-- moving that day into the past, and switching it on means moving it
-- beyond today -- which is what the panel does rather than pretending a
-- boolean exists.
--
-- Off is set to the current day floor, so the cut-off is now rather than
-- retroactive by an arbitrary amount. On is set to -1, the game's own
-- "never shuts off", so it cannot lapse again a day later.
handlers.setUtility = function(command)
    local which = command.utility
    local option = UTILITY_OPTIONS[which]

    if option == nil then
        return false, "utility must be power or water"
    end

    local wanted = command.on == true or command.on == "true"
    local day = apocalypseDay()

    -- Off is the current day floored, so the cut-off is now rather than
    -- retroactive by an arbitrary amount. On is -1, the game's own
    -- "never shuts off", so it cannot lapse again a day later.
    getSandboxOptions():set(option.name, wanted and -1 or math.floor(day))

    -- Read back rather than trust the setter: the same read the panel
    -- polls with, so a success here means the panel will agree.
    local on, shutAt = utilityState(which)

    if on == nil then
        return false, "the server would not report the utility back"
    end

    if on ~= wanted then
        return false, "the server would not change the utility", string.format(
            '{"utility":"%s","on":%s,"shutAt":%s,"day":%.1f}',
            escape(tostring(which)),
            tostring(on),
            tostring(shutAt),
            day
        )
    end

    return true, wanted and "utility switched on" or "utility switched off", string.format(
        '{"utility":"%s","on":%s,"shutAt":%s,"day":%.1f}',
        escape(tostring(which)),
        tostring(on),
        tostring(shutAt),
        day
    )
end

--- What the utilities are doing, and when they are due to stop.
handlers.readUtilities = function()
    -- By name, not by field: indexing SandboxOptions' public
    -- elecShutModifier from Lua returns null, which is what "attempted
    -- index: getValueAsObject of non-table" was. utilityState goes
    -- through getElecShutModifier() instead.
    local powerOn, powerShutAt, day = utilityState("power")
    local waterOn, waterShutAt = utilityState("water")

    if powerOn == nil or waterOn == nil then
        return false, "the server would not report its utilities"
    end

    return true, "utilities read", string.format(
        '{"day":%.1f,"power":{"on":%s,"shutAt":%s},"water":{"on":%s,"shutAt":%s}}',
        day,
        tostring(powerOn),
        tostring(powerShutAt),
        tostring(waterOn),
        tostring(waterShutAt)
    )
end

handlers.setSafehouseRespawn = function(command)
    local title = command.title

    if title == nil or title == "" then
        return false, "title is required"
    end

    local houses = SafeHouse.getSafehouseList()

    for i = 0, houses:size() - 1 do
        local house = houses:get(i)

        if house:getTitle() == title then
            house:setRespawnInSafehouse(command.enabled == true)

            return true, "safehouse updated", string.format(
                '{"title":"%s","respawn":%s}',
                escape(title),
                house:isRespawnInSafehouse() and "true" or "false"
            )
        end
    end

    return false, "no safehouse with that title"
end


--- What is actually on the ground around a point.
---
--- The one thing a rendered map can never show: tiles are a picture of
--- the world as it shipped, this is the world as it is.
---
--- Two things to know before reading the result.
---
--- getGridSquare returns nil for a square that is not loaded AND for one
--- that does not exist -- the game gives no way to tell those apart, and
--- its own code just checks for nil (ClientCommands.lua does exactly
--- that). So "scanned" counts the squares that were really there, and a
--- much smaller number than asked for means the area is not loaded.
---
--- There is no flag saying a thing was built by a player. The whole
--- IsoObject and IsoThumpable surface was searched and nothing of the
--- sort exists, so this reports what is there and does not guess where
--- it came from.
handlers.readSurroundings = function(command)
    local radius = math.floor(tonumber(command.radius) or 8)

    if radius < 1 or radius > 20 then
        return false, "radius must be between 1 and 20"
    end

    -- How much of each container to list. Twelve is enough to see what
    -- a crate is for; everything is what a stocktake needs.
    local perContainer = command.fullContents == true and 1000 or 12

    local centreX, centreY, centreZ

    if command.x ~= nil and command.y ~= nil then
        centreX = math.floor(tonumber(command.x) or 0)
        centreY = math.floor(tonumber(command.y) or 0)
        centreZ = math.floor(tonumber(command.z) or 0)
    else
        local player = findPlayer(command.player)

        if player == nil then
            return false, "needs coordinates or the name of an online player"
        end

        centreX = math.floor(player:getX())
        centreY = math.floor(player:getY())
        centreZ = math.floor(player:getZ())
    end

    local cell = getCell()

    if cell == nil then
        return false, "the world is not loaded"
    end

    local scanned = 0
    local items = {}
    local containers = {}
    local rooms = {}
    local zombies = 0

    for x = centreX - radius, centreX + radius do
        for y = centreY - radius, centreY + radius do
            local square = cell:getGridSquare(x, y, centreZ)

            if square ~= nil then
                scanned = scanned + 1

                -- getZombieCount is in the API index but the game never
                -- calls it from Lua anywhere, so it is tried rather than
                -- trusted: one bad square counts as none rather than
                -- taking the whole scan down.
                local ok, counted = pcall(square.getZombieCount, square)

                if ok and type(counted) == "number" then
                    zombies = zombies + counted
                end

                local room = square:getRoom()

                if room ~= nil then
                    local name = room:getName()

                    if name ~= nil and name ~= "" then
                        rooms[name] = (rooms[name] or 0) + 1
                    end
                end

                -- Loose items on the floor.
                local floor = square:getWorldObjects()

                for i = 0, floor:size() - 1 do
                    local item = floor:get(i):getItem()

                    if item ~= nil then
                        local key = item:getFullType()
                        local entry = items[key]

                        if entry == nil then
                            items[key] = { count = 1, name = item:getDisplayName(), x = x, y = y }
                        else
                            entry.count = entry.count + 1
                        end
                    end
                end

                -- Anything with a container: crates, fridges, shelves.
                local objects = square:getObjects()

                for i = 0, objects:size() - 1 do
                    local object = objects:get(i)
                    local container = object:getContainer()

                    if container ~= nil then
                        local held = container:getItems()
                        local contents = {}
                        local total = 0

                        if held ~= nil then
                            total = held:size()

                            -- Capped per container unless the caller
                            -- asks for everything: a scan of many full
                            -- crates answers with more JSON than a
                            -- glance needs, but something reading the
                            -- world for real wants all of it.
                            for h = 0, math.min(total, perContainer) - 1 do
                                local item = held:get(h)

                                if item ~= nil then
                                    contents[#contents + 1] = string.format(
                                        '{"type":"%s","name":"%s"}',
                                        escape(item:getFullType() or ""),
                                        escape(item:getDisplayName() or "")
                                    )
                                end
                            end
                        end

                        containers[#containers + 1] = {
                            x = x,
                            y = y,
                            type = container:getType() or "container",
                            count = total,
                            contents = contents,
                        }
                    end
                end
            end
        end
    end

    local asked = (radius * 2 + 1) * (radius * 2 + 1)

    return true, "read", string.format(
        '{"centre":{"x":%d,"y":%d,"z":%d},"radius":%d,'
        .. '"squares":{"asked":%d,"loaded":%d},'
        .. '"zombies":%d,"items":%s,"containers":%s,"rooms":%s}',
        centreX, centreY, centreZ, radius,
        asked, scanned,
        zombies,
        encodeItems(items),
        encodeContainers(containers),
        encodeRooms(rooms)
    )
end

local function runCommand(seq, command)
    local action = command.action

    if action == nil then
        writeResult(seq, false, "no action given")
        return
    end

    local handler = handlers[action]

    if handler == nil then
        writeResult(seq, false, "unknown action: " .. tostring(action))
        return
    end

    local ok, result, message, data = pcall(handler, command)

    if not ok then
        -- result holds the error when pcall fails.
        writeResult(seq, false, "the handler failed: " .. tostring(result))
        return
    end

    writeResult(seq, result, message, data)
end

--- Recovers when the two sides have drifted apart.
---
--- The panel's declared position is only ever read to move *forward*. A
--- stale read can only be lower than the truth, never invented higher,
--- so a forward-only jump cannot replay a command that already ran --
--- whereas following it backward could.
local function resyncCursor()
    local fields = decodeFlatObject(readFile(PANEL_CURSOR_FILE))

    if fields == nil or type(fields.nextCommandSeq) ~= "number" then
        return false
    end

    local panelHighWater = math.floor(fields.nextCommandSeq) - 1

    if panelHighWater <= lastCommandSeq then
        return false
    end

    print(string.format(
        "[ZomboidControl] Command cursor resynced from %d to %d.",
        lastCommandSeq,
        panelHighWater
    ))

    lastCommandSeq = panelHighWater
    writeCursor()

    return true
end

local function readCommands()
    local processed = 0

    while processed < MAX_COMMANDS_PER_TICK do
        local seq = lastCommandSeq + 1
        local contents = readFile(string.format(COMMAND_FILE, seq))

        if contents == nil then
            -- Nothing waiting. That is the normal case; only a long wait
            -- is worth suspecting a desync over.
            if waitingSince == 0 then
                waitingSince = getTimestamp()
            elseif getTimestamp() - waitingSince >= SECONDS_BEFORE_RESYNC then
                waitingSince = 0

                if resyncCursor() then
                    -- Try again straight away at the new position.
                    return readCommands()
                end
            end

            return
        end

        waitingSince = 0

        -- Moved on before the handler runs: a handler that crashes the
        -- tick must not run again on the next one.
        lastCommandSeq = seq
        processed = processed + 1

        local command = decodeFlatObject(contents)

        if command == nil then
            writeResult(seq, false, "the command file could not be read")
        else
            runCommand(seq, command)
        end

        writeCursor()
    end
end

local function onTick()
    local now = getTimestamp()
    local players = getOnlinePlayers()
    local roster = rosterOf(players)

    -- Somebody joined or left: write at once rather than waiting for the
    -- interval, which is the whole point of checking every tick.
    if roster ~= lastRoster then
        lastRoster = roster
        lastPlayerWrite = now
        attempt("players", writePlayers, players)
    elseif now - lastPlayerWrite >= SECONDS_BETWEEN_FULL_WRITES then
        lastPlayerWrite = now
        attempt("players", writePlayers, players)
    end

    if now - lastCommandCheck >= SECONDS_BETWEEN_COMMAND_CHECKS then
        lastCommandCheck = now
        attempt("commands", readCommands)
    end

    -- Time and weather on their own interval: the panel sets the weather
    -- and then shows it, so a minute of staleness reads as the command
    -- having failed.
    if now - lastWorldWrite >= SECONDS_BETWEEN_WORLD_WRITES then
        lastWorldWrite = now
        attempt("server info", writeServerInfo)
    end

    -- The list walks, which are what actually cost something.
    if now - lastSlowWrite >= SECONDS_BETWEEN_SLOW_WRITES then
        lastSlowWrite = now
        attempt("safehouses", writeSafehouses)
        attempt("vehicles", writeVehicles)
        attempt("factions", writeFactions)
    end
end

-- Everything a server start has to do once, as a named function: a
-- reloadlua does not fire OnServerStarted, so the reload guard below
-- calls this directly instead.
local function onServerStarted()
    attempt("players", writePlayers, getOnlinePlayers())
    attempt("server info", writeServerInfo)
    attempt("safehouses", writeSafehouses)
    attempt("vehicles", writeVehicles)
    attempt("factions", writeFactions)
    -- Once per start: mods are loaded by now, so the catalogue includes
    -- whatever they added.
    attempt("items", writeItems)
    attempt("vehicleCatalogue", writeVehicleCatalogue)
    attempt("probe", writeProbe)

    -- Picks up where the last run left off, so a restart does not replay
    -- every command the panel ever sent.
    readCursor()
    writeCursor()
end

-- A reloadlua re-executes this file on a server that never stopped, and
-- the game rewires the registrations itself: RunLua(path, true) sets
-- LuaCompiler.rewriteEvents, so every LuaClosure built during the reload
-- goes through LuaEventManager.reroute, which REPLACES a callback whose
-- prototype filename and name both match. Registering again is
-- therefore correct and does not double up.
--
-- Two consequences, both load-bearing:
--   * Events.X.Remove must NOT be called here. While rewriteEvents is
--     set, Event$Remove.call returns before touching the callback list,
--     so the remove is a silent no-op and the following Add appends a
--     second callback that reroute can no longer replace -- the exact
--     doubling it is meant to prevent.
--   * reroute matches on prototype.name, so both handlers are named
--     functions rather than inline anonymous ones.
ZomboidControlBridge = ZomboidControlBridge or {}

local reloaded = ZomboidControlBridge.registered == true

ZomboidControlBridge.onTick = onTick
ZomboidControlBridge.onServerStarted = onServerStarted
ZomboidControlBridge.version = BRIDGE_VERSION
ZomboidControlBridge.registered = true

-- OnTickEvenPaused rather than OnTick: the panel has to reach a server
-- nobody is playing on, which is exactly when a paused or idle server
-- would otherwise stop listening.
Events.OnTickEvenPaused.Add(onTick)
Events.OnServerStarted.Add(onServerStarted)

if reloaded then
    -- OnServerStarted will not fire again: the server is already up, and
    -- without this the cursor is never read and every command the panel
    -- ever sent would replay from sequence 1.
    onServerStarted()
end

print("[ZomboidControl] Bridge " .. BRIDGE_VERSION
    .. (reloaded and " reloaded." or " loaded."))
