--[[
    ZomboidControl bridge — server side.

    Writes JSON snapshots of server state into files the control panel
    reads over FTP or SFTP. Lua on the server has no HTTP client and no
    sockets, so files are the only way out.

    Output lands in the Zomboid data folder, under Lua/ZomboidControl:

        players.json    who is connected, with position and condition
        server.json     time, weather and how long the server has been up
        safehouses.json claimed safehouses and their members

    Since 0.8.0 it also reads. The panel writes numbered command files
    into the same directory and the bridge answers each with a result
    file, so the panel can ask the server to do something rather than
    only watch what it wrote.

    Install: upload to media/lua/server on the dedicated server, then
    restart it. The panel uploads this file for you.
]]

local BRIDGE_VERSION = "0.8.0"

-- getFileWriter writes into ~/Zomboid/Lua, which is documented.
-- getModFileWriter targets the mod's own common/ directory instead, and
-- its behaviour for a mod without one is not established.
local PLAYERS_FILE = "ZomboidControl/players.json"
local SERVER_FILE = "ZomboidControl/server.json"
local SAFEHOUSES_FILE = "ZomboidControl/safehouses.json"
-- Written once per server start: the catalogue only changes when mods do,
-- and it is several thousand entries.
local ITEMS_FILE = "ZomboidControl/items.json"
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
local SECONDS_BETWEEN_SLOW_WRITES = 60

local lastPlayerWrite = 0
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
local function describeSkills(player)
    local perks = player:getPerkList()

    if perks == nil then
        return "{}"
    end

    local entries = {}

    for i = 0, perks:size() - 1 do
        local info = perks:get(i)

        if info ~= nil and info.perk ~= nil then
            local level = info:getLevel()

            if level > 0 then
                table.insert(entries, string.format("\"%s\":%d", escape(info.perk:getId()), level))
            end
        end
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
                "\"weather\":{\"temperature\":%.1f,\"raining\":%s,\"snowing\":%s,\"windSpeed\":%.1f,\"season\":\"%s\"}",
                climate:getTemperature(),
                tostring(climate:isRaining()),
                tostring(climate:isSnowing()),
                climate:getWindspeedKph(),
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

--- Wraps a write so a fault in one file cannot stop the others.
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

    -- Time, weather and safehouses move slowly, and reading them is more
    -- expensive than reading the roster.
    if now - lastSlowWrite >= SECONDS_BETWEEN_SLOW_WRITES then
        lastSlowWrite = now
        attempt("server info", writeServerInfo)
        attempt("safehouses", writeSafehouses)
    end
end

-- OnTickEvenPaused rather than OnTick: the panel has to reach a server
-- nobody is playing on, which is exactly when a paused or idle server
-- would otherwise stop listening.
Events.OnTickEvenPaused.Add(onTick)

-- The first write happens as soon as the server is up rather than after
-- the first interval, so the panel has something to read immediately.
Events.OnServerStarted.Add(function()
    attempt("players", writePlayers, getOnlinePlayers())
    attempt("server info", writeServerInfo)
    attempt("safehouses", writeSafehouses)
    -- Once per start: mods are loaded by now, so the catalogue includes
    -- whatever they added.
    attempt("items", writeItems)
    attempt("probe", writeProbe)

    -- Picks up where the last run left off, so a restart does not replay
    -- every command the panel ever sent.
    readCursor()
    writeCursor()
end)

print("[ZomboidControl] Bridge " .. BRIDGE_VERSION .. " loaded.")
