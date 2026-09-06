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

--- One spawnable vehicle: its model, scale and the textures it wears.
---
--- A script carries exactly one skin -- the 51 van liveries are 51
--- separate scripts, not skins of one -- so the panel groups them by
--- shared model rather than reading a list from any single script.
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

    -- The textures decide what the renderer can draw and which body a
    -- vehicle belongs to: everything sharing a mask is one shell.
    local gotCount, count = pcall(function() return script:getSkinCount() end)

    if gotCount and type(count) == "number" and count > 0 then
        local gotSkin, skin = pcall(function() return script:getSkin(0) end)

        if gotSkin and skin ~= nil then
            for _, entry in ipairs({
                { "texture", "texture" },
                { "mask", "textureMask" },
                { "rust", "textureRust" },
            }) do
                local gotValue, value = pcall(function() return skin[entry[2]] end)

                if gotValue and value ~= nil and tostring(value) ~= "" then
                    table.insert(
                        parts,
                        string.format("\"%s\":\"%s\"", entry[1], escape(tostring(value)))
                    )
                end
            end
        end
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
        getSkinCount = function() return fields.skin and 1 or 0 end,
        getSkin = function() return fields.skin end,
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
-- A vehicle with its model, scale and textures
-- --------------------------------------------------------------------

local van = describeVehicleScript("Base.VanMail", script({
    model = "Vehicles_Van_NoRandom",
    fullName = "Base.VanMail",
    scale = 1.82,
    skin = {
        texture = "Vehicles/vehicle_vanmail",
        textureMask = "Vehicles/vehicle_van_mask",
        textureRust = "Vehicles/Veh_Rust",
    },
}))

check("names the script", van:find('"script":"Base.VanMail"', 1, true) ~= nil)
-- getModel() returns an object; the file name is on it, and printing the
-- object itself gave "VehicleScript$Model@1a35f99a" on a live server.
check("takes the model's file name, not the object",
    van:find('"model":"Vehicles_Van_NoRandom"', 1, true) ~= nil)
check("carries the scale", van:find('"scale":1.8200', 1, true) ~= nil)
check("carries the texture", van:find('"texture":"Vehicles/vehicle_vanmail"', 1, true) ~= nil)
-- Everything sharing a mask is one body shell, which is how the panel
-- groups 51 van liveries under one tile.
check("carries the mask", van:find('"mask":"Vehicles/vehicle_van_mask"', 1, true) ~= nil)
check("carries the rust overlay", van:find('"rust":"Vehicles/Veh_Rust"', 1, true) ~= nil)

-- --------------------------------------------------------------------
-- The fault this test exists for
-- --------------------------------------------------------------------

local broken = describeVehicleScript("Base.Mystery", opaque())

check("still names a script whose accessors all throw",
    broken:find('"script":"Base.Mystery"', 1, true) ~= nil)
check("omits what it could not read rather than failing",
    broken:find('"model":', 1, true) == nil)

local bare = describeVehicleScript("Base.VanBurnt", script({ model = "Vehicles_VanBurnt" }))

-- A burnt-out shell carries no paint, which is normal, not a fault.
check("accepts a vehicle with no mask", bare:find('"mask":', 1, true) == nil)
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
