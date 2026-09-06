-- How the bridge describes a spawnable vehicle.
--
-- Kept as Lua rather than folded into PHPUnit because that is what runs
-- on the game server, and because the fault this covers is a Java field
-- read that looks like a Lua field and is not.
-- Run with: ddev exec "lua5.1 /var/www/html/backend/tests/Bridge/vehicle-catalogue-test.lua"
--
-- The function below is a copy of the one in
-- resources/bridge/ZomboidControlBridge.lua. PHPUnit checks they have
-- not drifted apart -- see BridgeVehicleCatalogueTest.

local function escape(value)
    return (tostring(value):gsub('[%c"\\]', function(c)
        if c == '"' then return '\\"' end
        if c == '\\' then return '\\\\' end
        return string.format('\\u%04x', string.byte(c))
    end))
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

--- Stand-ins for the game's Java objects.

local function script(fields)
    return {
        getModel = function()
            if fields.model == nil then return nil end

            return {
                getFile = function() return fields.model end,
                getScale = function() return fields.scale end,
            }
        end,
        getFullName = function() return fields.fullName end,
    }
end

--- A script whose every accessor throws, as an unexposed class does.
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

-- --------------------------------------------------------------------
-- A vehicle with its script name, model and scale
-- --------------------------------------------------------------------

local van = describeVehicleScript("Base.VanMail", script({
    model = "Vehicles_Van_NoRandom",
    fullName = "Base.VanMail",
    scale = 1.82,
}))

check("names the script", van:find('"script":"Base.VanMail"', 1, true) ~= nil)
-- getModel() returns an object; the file name is on it, and printing the
-- object itself gave "VehicleScript$Model@1a35f99a" on a live server.
check("takes the model's file name, not the object",
    van:find('"model":"Vehicles_Van_NoRandom"', 1, true) ~= nil)
check("carries the scale", van:find('"scale":1.8200', 1, true) ~= nil)
-- Deliberately absent: the panel already holds every vehicle's textures,
-- keyed by script name, and the artwork has to be uploaded either way.
check("does not claim to carry textures", van:find('"texture":', 1, true) == nil)

-- A modded vehicle is reported like any other: only the server knows
-- it exists, which is the whole reason for asking.
local modded = describeVehicleScript("MyMod.Truck", script({ model = "Vehicles_MyTruck" }))

check("reports a modded vehicle too", modded:find('"model":"Vehicles_MyTruck"', 1, true) ~= nil)

-- --------------------------------------------------------------------
-- The fault this test exists for
-- --------------------------------------------------------------------

local broken = describeVehicleScript("Base.Mystery", opaque())

check("still names a script whose accessors all throw",
    broken:find('"script":"Base.Mystery"', 1, true) ~= nil)
check("omits what it could not read rather than failing",
    broken:find('"model":', 1, true) == nil)

local bare = describeVehicleScript("Base.VanBurnt", script({ model = "Vehicles_VanBurnt" }))

check("still carries its model", bare:find('"model":"Vehicles_VanBurnt"', 1, true) ~= nil)

-- --------------------------------------------------------------------
-- Names that would break the JSON
-- --------------------------------------------------------------------

local quoted = describeVehicleScript('Base.Van"; drop', script({}))

check("escapes a quote in the script name", quoted:find('\\"', 1, true) ~= nil)
check("produces no bare quote inside the value",
    quoted:find('"script":"Base.Van\\"; drop"', 1, true) ~= nil)

print("")

if failures > 0 then
    print(string.format("%d of %d checks failed.", failures, checks))
    os.exit(1)
end

print(string.format("All %d checks passed.", checks))
