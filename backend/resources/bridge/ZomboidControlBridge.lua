--[[
    ZomboidControl bridge — server side.

    Writes JSON snapshots of server state into files the control panel
    reads over FTP or SFTP. Lua on the server has no HTTP client and no
    sockets, so files are the only way out.

    Output lands in the Zomboid data folder, under Lua/ZomboidControl:

        players.json    who is connected, with position and condition
        server.json     time, weather and how long the server has been up
        safehouses.json claimed safehouses and their members

    Install: upload to media/lua/server on the dedicated server, then
    restart it. The panel uploads this file for you.
]]

local BRIDGE_VERSION = "0.5.0"

-- getFileWriter writes into ~/Zomboid/Lua, which is documented.
-- getModFileWriter targets the mod's own common/ directory instead, and
-- its behaviour for a mod without one is not established.
local PLAYERS_FILE = "ZomboidControl/players.json"
local SERVER_FILE = "ZomboidControl/server.json"
local SAFEHOUSES_FILE = "ZomboidControl/safehouses.json"

-- Build 42 has no server-side event for a player joining or leaving:
-- OnPlayerConnect and OnPlayerDisconnect do not exist, and OnConnected
-- and OnDisconnect fire in the client only. The roster is therefore
-- checked on every tick -- reading a size and a name per player is
-- cheap -- and the file is written the moment it differs.
--
-- Intervals are counted in real seconds rather than ticks. A dedicated
-- server's tick rate follows its own frame rate, so it varies with load
-- and hardware: the same counter measured 10 ticks per second on an
-- empty server and 5 with a player on it. Seconds do not drift.
local SECONDS_BETWEEN_FULL_WRITES = 3
local SECONDS_BETWEEN_SLOW_WRITES = 60

local lastPlayerWrite = 0
local lastSlowWrite = 0
local lastRoster = ""

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
        "{\"bridgeVersion\":\"%s\",\"generatedAt\":%d,\"playerCount\":%d,\"players\":[%s]}",
        BRIDGE_VERSION,
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
        "{\"bridgeVersion\":\"%s\",\"generatedAt\":%d,\"safehouses\":[%s]}",
        BRIDGE_VERSION,
        getTimestamp(),
        table.concat(entries, ",")
    ))
end

--- Wraps a write so a fault in one file cannot stop the others.
local function attempt(what, write, ...)
    local ok, err = pcall(write, ...)

    if not ok then
        print("[ZomboidControl] Failed to write " .. what .. ": " .. tostring(err))
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

    -- Time, weather and safehouses move slowly, and reading them is more
    -- expensive than reading the roster.
    if now - lastSlowWrite >= SECONDS_BETWEEN_SLOW_WRITES then
        lastSlowWrite = now
        attempt("server info", writeServerInfo)
        attempt("safehouses", writeSafehouses)
    end
end

Events.OnTick.Add(onTick)

-- The first write happens as soon as the server is up rather than after
-- the first interval, so the panel has something to read immediately.
Events.OnServerStarted.Add(function()
    attempt("players", writePlayers, getOnlinePlayers())
    attempt("server info", writeServerInfo)
    attempt("safehouses", writeSafehouses)
end)

print("[ZomboidControl] Bridge " .. BRIDGE_VERSION .. " loaded.")
