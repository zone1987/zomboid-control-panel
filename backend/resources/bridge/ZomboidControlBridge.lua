--[[
    ZomboidControl bridge — server side.

    Writes a JSON snapshot of connected players to a file the control panel
    reads over SFTP. Lua on the server has no HTTP client and no sockets, so
    files are the only way out.

    Output lands in the Zomboid data folder: ~/Zomboid/Lua/ZomboidControl/status.json

    Install: upload to media/lua/server on the dedicated server, then restart
    it. The panel uploads this file for you.
]]

local BRIDGE_VERSION = "0.3.0"
-- getFileWriter writes into ~/Zomboid/Lua, which is documented.
-- getModFileWriter targets the mod's own common/ directory instead,
-- and its behaviour for a mod without one is not established.
local STATUS_FILE = "ZomboidControl/status.json"

-- Build 42.20.2 has no EveryTenMinutes event, so OnTick is throttled by hand.
-- Roughly five seconds at 60 fps: the panel polls at the same rate, so a
-- player joining shows up within about ten seconds either way.
local TICKS_BETWEEN_WRITES = 300
local ticksSinceWrite = 0

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

--- Skills the character has actually trained; level 0 entries are skipped
--- so the payload stays small.
local function describeSkills(player)
    local perks = player:getPerkList()

    if perks == nil then
        return ""
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

local function writeStatus()
    local writer = getFileWriter(STATUS_FILE, true, false)

    if writer == nil then
        print("[ZomboidControl] Could not open " .. STATUS_FILE .. " for writing.")
        return
    end

    local players = getOnlinePlayers()
    local entries = {}

    if players ~= nil then
        for i = 0, players:size() - 1 do
            local player = players:get(i)

            if player ~= nil then
                table.insert(entries, describePlayer(player))
            end
        end
    end

    writer:write(string.format(
        "{\"bridgeVersion\":\"%s\",\"generatedAt\":%d,\"playerCount\":%d,\"players\":[%s]}",
        BRIDGE_VERSION,
        getTimestamp(),
        #entries,
        table.concat(entries, ",")
    ))

    writer:close()
end

local function onTick()
    ticksSinceWrite = ticksSinceWrite + 1

    if ticksSinceWrite < TICKS_BETWEEN_WRITES then
        return
    end

    ticksSinceWrite = 0

    local ok, err = pcall(writeStatus)

    if not ok then
        print("[ZomboidControl] Failed to write status: " .. tostring(err))
    end
end

Events.OnTick.Add(onTick)

print("[ZomboidControl] Bridge " .. BRIDGE_VERSION .. " loaded.")
