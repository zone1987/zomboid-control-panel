-- How the bridge picks the list of loaded vehicles.
--
-- Kept as Lua rather than folded into PHPUnit because that is what runs
-- on the game server: the fault this covers was a Java collection that
-- looks iterable in Lua and is not.
-- Run with: ddev exec "lua /var/www/html/backend/tests/Bridge/vehicles-test.lua"
--
-- The two functions below are a copy of the ones in
-- resources/bridge/ZomboidControlBridge.lua. PHPUnit checks they have
-- not drifted apart -- see BridgeVehicleListTest.

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

--- Stand-ins for the game's Java objects.

--- An ArrayList: answers size() and get(index), zero-based.
local function arrayList(items)
    return {
        size = function(self) return #items end,
        get = function(self, index) return items[index + 1] end,
    }
end

--- Something that answers nothing at all, as an unexposed class does.
local function opaque()
    return setmetatable({}, {
        __index = function()
            error("attempt to call a nil value")
        end,
    })
end

local failures = 0
local checks = 0

local function check(name, condition)
    checks = checks + 1

    if condition then
        print("  ok   " .. name)
    else
        failures = failures + 1
        print("  FAIL " .. name)
    end
end

local function withWorld(manager, cell, body)
    VehicleManager = manager
    getCell = function() return cell end
    -- The third source the chooser tries; absent unless a test sets it.
    getWorld = function() return nil end

    local list, from, tried = body()

    VehicleManager = nil
    getCell = nil
    getWorld = nil

    return list, from, tried
end

-- --------------------------------------------------------------------
-- The manager is the source that works
-- --------------------------------------------------------------------

local list, from = withWorld(
    nil,
    { getVehicles = function() return arrayList({ "a", "b", "c" }) end },
    vehicleList
)

check("reads the vehicles from the cell", list ~= nil and list:size() == 3)
check("indexes the cell's list from zero", list ~= nil and list:get(0) == "a")
check("names the cell as the source", from == "cell")

-- --------------------------------------------------------------------
-- The fault this test exists for
-- --------------------------------------------------------------------

local refused, refusedFrom, refusedTried = withWorld(
    nil,
    { getVehicles = function() return opaque() end },
    vehicleList
)

check("refuses a list that cannot be indexed", refused == nil)
check("names no source when nothing answered", refusedFrom == "none")
check("records why the cell was rejected",
    refusedTried ~= nil and refusedTried[1] == "cell:not-indexable")

check("refuses a list that answers nothing", not indexable(opaque()))
check("accepts an empty list rather than probing element zero", indexable(arrayList({})))

-- --------------------------------------------------------------------
-- Falling back, and giving up
-- --------------------------------------------------------------------

local fallback, fallbackFrom = withWorld(
    { instance = { getVehicles = function() return arrayList({ "only one" }) end } },
    { getVehicles = function() error("no cell on a dedicated server") end },
    vehicleList
)

check("falls back to the manager when the cell throws",
    fallback ~= nil and fallback:size() == 1)
check("names the manager as the source", fallbackFrom == "manager")

local missing = withWorld(nil, nil, vehicleList)

check("returns nothing when neither source answers", missing == nil)

local absent = withWorld(
    { instance = { getVehicles = function() return nil end } },
    { getVehicles = function() return nil end },
    vehicleList
)

check("returns nothing when both sources answer nil", absent == nil)

-- An empty world is not a broken one: it has to reach the panel as an
-- empty list, or the map cannot tell "no vehicles" from "cannot read".
local empty, emptyFrom = withWorld(
    nil,
    { getVehicles = function() return arrayList({}) end },
    vehicleList
)

check("tells an empty world apart from an unreadable one", empty ~= nil and empty:size() == 0)
check("still names the source for an empty world", emptyFrom == "cell")

print("")

if failures > 0 then
    print(string.format("%d of %d checks failed.", failures, checks))
    os.exit(1)
end

print(string.format("All %d checks passed.", checks))
