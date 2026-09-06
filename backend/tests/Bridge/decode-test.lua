-- The bridge's JSON reader, checked against the shapes the panel sends.
--
-- Kept as Lua rather than folded into PHPUnit because that is what runs
-- on the game server: a pattern that works in PHP proves nothing here.
-- Run with: ddev exec "lua /var/www/html/backend/tests/Bridge/decode-test.lua"
--
-- The parser below is a copy of the one in
-- resources/bridge/ZomboidControlBridge.lua. PHPUnit checks they have
-- not drifted apart -- see BridgeDecoderTest.

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

local function check(name, ok)
    print((ok and "  ok   " or "  FAIL ") .. name)
    return ok and 0 or 1
end

local failures = 0
local d = decodeFlatObject

failures = failures + check("action gelesen", d('{"action":"setTime","hour":14}').action == "setTime")
failures = failures + check("Zahl gelesen", d('{"action":"setTime","hour":14}').hour == 14)
failures = failures + check("negative Kommazahl", d('{"value":-1.5}').value == -1.5)
failures = failures + check("Boolean true", d('{"enabled":true}').enabled == true)
failures = failures + check("Boolean false", d('{"enabled":false}').enabled == false)
failures = failures + check("Text mit Leerzeichen", d('{"title":"Bobs Haus"}').title == "Bobs Haus")
failures = failures + check("maskiertes Anfuehrungszeichen",
    d('{"title":"Er sagte \\"hallo\\""}').title == 'Er sagte "hallo"')
failures = failures + check("Feld nach maskiertem Quote",
    d('{"title":"a\\"b","hour":7}').hour == 7)
failures = failures + check("maskierter Backslash",
    d('{"path":"C:\\\\Zomboid"}').path == "C:\\Zomboid")
failures = failures + check("Zeilenumbruch", d('{"text":"a\\nb"}').text == "a\nb")
failures = failures + check("Doppelpunkt im Wert", d('{"text":"10:30"}').text == "10:30")
failures = failures + check("geschweifte Klammer im Wert", d('{"text":"a}b"}').text == "a}b")
failures = failures + check("leerer Text", d('{"text":""}').text == "")
failures = failures + check("mehrere Zahlen",
    (function() local r = d('{"x":10778,"y":9770,"radius":150}')
     return r.x == 10778 and r.y == 9770 and r.radius == 150 end)())
failures = failures + check("nil bleibt nil", d(nil) == nil)
failures = failures + check("fehlendes Feld ist nil", d('{"action":"ping"}').hour == nil)
failures = failures + check("Leerzeichen um Doppelpunkt",
    d('{ "action" : "ping" , "hour" : 3 }').hour == 3)
failures = failures + check("Zahl als Text bleibt Text", d('{"hour":"14"}').hour == "14")

print(failures == 0 and "\nAll checks passed." or ("\n" .. failures .. " failed."))
os.exit(failures == 0 and 0 or 1)
