--[[
    ZomboidControl bridge — server side.

    Writes a JSON snapshot of connected players to a file the control panel
    reads over SFTP. Lua on the server has no HTTP client and no sockets, so
    files are the only way out.

    Install: upload to media/lua/server on the dedicated server, then restart
    it. The panel uploads this file for you.
]]

local BRIDGE_VERSION = "0.1.0"
local MOD_ID = "ZomboidControlBridge"
local STATUS_FILE = "status.json"

-- Build 42.20.2 has no EveryTenMinutes event, so OnTick is throttled by hand.
local TICKS_BETWEEN_WRITES = 600
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
        -- Verified against build 42: IsInfected is capitalised, and the
        -- level getter is getApparentInfectionLevel.
        table.insert(parts, string.format("\"infected\":%s", tostring(body:IsInfected())))
        table.insert(parts, string.format("\"infectionLevel\":%.3f", body:getApparentInfectionLevel()))
    end

    return "{" .. table.concat(parts, ",") .. "}"
end

local function writeStatus()
    local writer = getModFileWriter(MOD_ID, STATUS_FILE, true, false)

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
