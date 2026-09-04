# Brief 08 — Isometric map and live world state

**Status:** not started, requested 2026-09-05
**Depends on:** [07](07-event-console.md), and the map built there

## Why this exists

Two requests, made together, that sound like one thing and are not.

**The isometric view.** The map today is Project Zomboid's own in-game
map: orthogonal, drawn from above, shipped inside `pyramid.zip`. The
operator wants the angled 2.5D view of projectzomboidmap.com, where
buildings, walls, roofs and trees stand up out of the ground.

**What the player sees.** The same operator asked whether the map could
show the world as it is now -- the shelves a player built, the crates
they placed. It cannot today, and the reason is worth stating plainly:
the tiles are a picture of the world as it shipped, not of the world as
it is.

## The isometric view

### What is needed

Tiles rendered isometrically. Nobody distributes them and the game does
not contain them: they are generated with **pzmap2dzi** (MIT, Min
Xiang) from a game installation.

The panel side is already built -- see "What is already done" -- so
what is missing is the tiles and nothing else.

### What it costs, from the project's own measurements

Measured on a Ryzen 7 5700G, 16 threads:

| View | Format | Size | Time |
|---|---|---|---|
| isometric | webp | **404 GB** | 12:45 |
| isometric | webp, jpg for floor 0 | **347 GB** | **3:36** |
| top-down | webp | 67 MB | 0:52 |

The jpg trick for floor 0 saves a quarter of the time and is the
setting to use.

Four ways to make it smaller, in the order they are worth trying:

- **`omit_levels`** drops the deepest pyramid levels. Each one quarters
  the output: 1 gives a quarter, 2 a sixteenth, 3 a sixty-fourth. The
  viewer compensates through `scale`, so world coordinates stay right.
- **`render_cell_range`** renders only part of the world, without
  changing the geometry. Right when a server only plays one region.
- **`layer_range`** trims the floors from build 42's 64 to the handful
  a map uses.
- **`image_fmt_base_layer0: jpg`**, as above.

### The constraint that decides who can do this

**pzmap2dzi needs the full game installation, not a dedicated server.**
It reads `media/texturepacks/*.pack` -- the client textures -- and app
380870 does not ship them. Confirmed against this project's own server:
`media/maps` is there, `media/texturepacks/UI.pack` is not.

So the isometric view is for an operator who owns the game and has a
machine to render on. That is a real limit and the interface has to say
so rather than offering a button that cannot work.

### What is already done

The panel reads DZI and swaps floors under a running view; the tile
source is configurable; the coordinate transform handles both
projections and is tested against pzmap2dzi's own formula:

    px = (x0 + (sx - sy) * sqr / 2) / scale
    py = (y0 + (sx + sy) * sqr / 4 - 1.5 * layer * sqr) / scale

`IsometricTiles` (backend) reads a render's `map_info.json` for the
geometry rather than assuming it, lists the floors from the files it
finds, and serves tiles through the panel's own authentication.

### What is left

- A screen that takes a render: upload, or a path on the panel's host.
- Reading `map_info.json` into the frontend's map source, so the
  geometry comes from the render rather than from defaults.
- Per-floor tile extension: pzmap2dzi writes floor 0 as jpg and the
  rest as webp, and getting it wrong is a pyramid of 404s.
- Saying in the interface what a render needs, so nobody spends three
  hours discovering the texture packs are missing.

## What the player sees

### Why the map cannot show it

`pyramid.zip` is a picture, drawn once, shipped with the game. What
players change lives in the save: `Saves/Multiplayer/<world>/map_*.bin`,
one file per cell, holding what differs from the original.

Rendering that is pzmap2dzi's `save` mode -- and it needs the texture
packs like every other isometric render, plus a re-render whenever the
world changes. It would not be live; it would be a picture that is
occasionally less old.

### The direct way

**The bridge can read the world around a player.** It is already on the
server, already answers commands, and the game's own API exposes what
is on a square: `IsoGridSquare` has the objects, the items on the
floor, and whether they were built rather than generated.

That gives something the tiles never can:

- What a player is standing next to, right now
- Items on the floor in a radius, with their names
- What has been built, by whom, and when
- All of it live, at the three-second poll the panel already runs

Costs no gigabytes, needs no textures, and works on a rented server.

### What is left

- A bridge command that reads squares in a radius and answers with what
  is on them.
- A panel view for it -- probably beside the player rather than on the
  map, since it is a list rather than a picture.
- Deciding how large a radius is honest: reading a whole cell every
  three seconds would cost frames on the game server, and the operator
  should not have to guess.

## What "done" looks like

An operator with the game installed renders the isometric tiles once,
points the panel at them, and sees Muldraugh from the angle the game
draws it -- with the floors switchable and their players on top.

An operator without the game still sees the top-down map, and can ask
what a player has around them at this moment.
