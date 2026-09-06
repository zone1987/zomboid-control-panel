ARG BASE_IMAGE
FROM $BASE_IMAGE

# Lua 5.1, because that is what Project Zomboid runs (Kahlua2): the
# bridge is syntax-checked with luac5.1 -p and exercised with lua5.1,
# and a newer Lua would accept what the game rejects.
#
# luacheck comes from apt rather than luarocks: the image has no gcc, so
# luarocks would have to compile luafilesystem itself.
RUN apt-get update && apt-get install -y --no-install-recommends \
        lua5.1 \
        lua-check \
        lua-filesystem \
        lua-argparse \
    && rm -rf /var/lib/apt/lists/*
