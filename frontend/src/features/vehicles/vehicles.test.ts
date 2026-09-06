import { describe, expect, it } from 'vitest'

import { readFileSync } from 'node:fs'

import {
  matches,
  representatives,
  select,
  selectBodies,
  typesPresent,
  VEHICLE_TYPES,
  WRECKS,
  type SpawnableVehicle,
  type VehicleCatalogue,
} from './vehicles'

const vehicle = (overrides: Partial<SpawnableVehicle> & { script: string }): SpawnableVehicle => ({
  name: overrides.script,
  body: 'van',
  type: 'van',
  model: null,
  texture: null,
  drawable: false,
  specs: null,
  ...overrides,
})

const nyala = vehicle({
  script: 'Base.CarNormal',
  name: 'Chevalier Nyala',
  body: 'nyala',
  type: 'car',
})
const van = vehicle({ script: 'Base.VanSeats', name: 'Franklin Valuline', body: 'van' })
const wreck = vehicle({
  script: 'Base.CarBurnt',
  name: 'Burnt Car',
  body: WRECKS,
  type: 'wreck',
})

const items = [nyala, van, wreck]

describe('matches', () => {
  it('matches on the display name', () => {
    expect(matches(nyala, 'nyala')).toBe(true)
  })

  /** A modded vehicle often has no display name, only a script. */
  it('matches on the script', () => {
    expect(matches(nyala, 'carnormal')).toBe(true)
  })

  it('keeps everything while nothing is typed', () => {
    expect(matches(nyala, '   ')).toBe(true)
  })
})

describe('select', () => {
  const none = () => false

  it('shows nothing until a body is chosen', () => {
    expect(select(items, { needle: '', body: null, onlyFavourites: false }, none, none)).toEqual([])
  })

  it('shows one body once it is chosen', () => {
    expect(select(items, { needle: '', body: 'van', onlyFavourites: false }, none, none)).toEqual([
      van,
    ])
  })

  /** Somebody who types a name does not know which shell it belongs to. */
  it('searches across every body', () => {
    expect(select(items, { needle: 'nyala', body: null, onlyFavourites: false }, none, none)).toEqual(
      [nyala],
    )
  })

  it('lets a search leave the chosen body', () => {
    expect(
      select(items, { needle: 'nyala', body: 'van', onlyFavourites: false }, none, none),
    ).toEqual([nyala])
  })

  it('shows the marked liveries when no body is chosen', () => {
    expect(
      select(
        items,
        { needle: '', body: null, onlyFavourites: true },
        (script) => script === 'Base.CarNormal',
        none,
      ),
    ).toEqual([nyala])
  })

  /** Marking a shell is a request to see everything it comes in. */
  it('shows every livery of a marked body', () => {
    expect(
      select(items, { needle: '', body: null, onlyFavourites: true }, none, (id) => id === 'van'),
    ).toEqual([van])
  })

  it('combines marked bodies and marked liveries', () => {
    expect(
      select(
        items,
        { needle: '', body: null, onlyFavourites: true },
        (script) => script === 'Base.CarBurnt',
        (id) => id === 'van',
      ),
    ).toEqual([van, wreck])
  })

  /**
   * Choosing a shell overrides the filter, so its plain liveries stay
   * visible rather than the grid emptying under the cursor.
   */
  it('shows all of a chosen body even while filtering by favourite', () => {
    expect(select(items, { needle: '', body: 'van', onlyFavourites: true }, none, none)).toEqual([
      van,
    ])
  })

  it('shows nothing when nothing is marked and no body is chosen', () => {
    expect(select(items, { needle: '', body: null, onlyFavourites: true }, none, none)).toEqual([])
  })
})

describe('representatives', () => {
  it('prefers a member with artwork, so the grid is not all fallbacks', () => {
    const plain = vehicle({ script: 'Base.VanA', body: 'van' })
    const drawn = vehicle({ script: 'Base.VanB', body: 'van', drawable: true })

    expect(
      representatives({
        bodies: [{ id: 'van', name: 'Van', count: 2, preview: 'Base.VanB' }],
        items: [plain, drawn],
        generatedAt: null,
        bridgeVersion: null,
        available: true,
      }),
    ).toEqual([drawn])
  })
})

describe('selectBodies', () => {
  const catalogue: VehicleCatalogue = {
    bodies: [
      { id: 'nyala', name: 'Chevalier Nyala', count: 1, preview: null },
      { id: 'van', name: 'Franklin Valuline', count: 1, preview: null },
      { id: WRECKS, name: '', count: 1, preview: null },
    ],
    items,
    generatedAt: null,
    bridgeVersion: null,
    available: true,
  }

  const none = () => false

  it('shows every body while the filter is off', () => {
    expect(selectBodies(catalogue, false, none, none).map((body) => body.id)).toEqual([
      'nyala',
      'van',
      WRECKS,
    ])
  })

  it('narrows to the marked bodies', () => {
    expect(
      selectBodies(catalogue, true, (id) => id === 'van', none).map((body) => body.id),
    ).toEqual(['van'])
  })

  /** A favourite livery must stay reachable even with its body unmarked. */
  it('keeps a body holding a marked livery', () => {
    expect(
      selectBodies(catalogue, true, none, (script) => script === 'Base.CarNormal').map(
        (body) => body.id,
      ),
    ).toEqual(['nyala'])
  })

  it('does not list a body twice when both it and its livery are marked', () => {
    expect(
      selectBodies(
        catalogue,
        true,
        (id) => id === 'nyala',
        (script) => script === 'Base.CarNormal',
      ).map((body) => body.id),
    ).toEqual(['nyala'])
  })

  it('shows nothing when nothing is marked', () => {
    expect(selectBodies(catalogue, true, none, none)).toEqual([])
  })
})

describe('the type filter', () => {
  const none = () => false

  const small = vehicle({ script: 'Base.SmallCar', body: 'small', type: 'small' })
  const cruiser = vehicle({ script: 'Base.CarLightsPolice', body: 'car', type: 'service' })
  const fleet = [nyala, van, wreck, small, cruiser]

  it('offers only the types the catalogue holds, in the offered order', () => {
    expect(typesPresent(fleet)).toEqual(['small', 'car', 'van', 'service', 'wreck'])
  })

  it('offers nothing for an empty catalogue', () => {
    expect(typesPresent([])).toEqual([])
  })

  it('narrows a chosen body to the types asked for', () => {
    expect(
      select(fleet, { needle: '', body: 'van', onlyFavourites: false, types: ['service'] }, none),
    ).toEqual([])
  })

  it('keeps a chosen body when its type is asked for', () => {
    expect(
      select(fleet, { needle: '', body: 'van', onlyFavourites: false, types: ['van'] }, none),
    ).toEqual([van])
  })

  /** Several types at once, because they are not mutually exclusive. */
  it('accepts more than one type', () => {
    expect(
      select(fleet, { needle: 'a', body: null, onlyFavourites: false, types: ['small', 'service'] }, none)
        .map((entry) => entry.script),
    ).toEqual(['Base.SmallCar', 'Base.CarLightsPolice'])
  })

  it('narrows a search to the types asked for', () => {
    expect(
      select(fleet, { needle: 'nyala', body: null, onlyFavourites: false, types: ['van'] }, none),
    ).toEqual([])
  })

  it('narrows the favourites to the types asked for', () => {
    expect(
      select(
        fleet,
        { needle: '', body: null, onlyFavourites: true, types: ['van'] },
        (script) => script === 'Base.CarNormal',
      ),
    ).toEqual([])
  })

  it('shows every type when none is asked for', () => {
    expect(
      select(fleet, { needle: '', body: 'van', onlyFavourites: false, types: [] }, none),
    ).toEqual([van])
  })

  it('hides a body holding nothing of the asked-for type', () => {
    const catalogue: VehicleCatalogue = {
      bodies: [
        { id: 'van', name: 'Van', count: 1, preview: null },
        { id: 'small', name: 'Small', count: 1, preview: null },
      ],
      items: fleet,
      generatedAt: null,
      bridgeVersion: null,
      available: true,
    }

    expect(selectBodies(catalogue, false, none, none, ['small']).map((body) => body.id)).toEqual([
      'small',
    ])
  })
})

/**
 * The frontend lists the types to keep the filter's order and its typing
 * local, so it can drift from the backend that assigns them. Asserted
 * against the source rather than trusted.
 */
describe('the type list against the backend', () => {
  it('matches VehicleTypes::ORDER', () => {
    const source = readFileSync('../backend/src/Server/Vehicles/VehicleTypes.php', 'utf8')
    const start = source.indexOf('public const ORDER')
    const order = source.slice(start, source.indexOf('];', start))
    const constants = [...order.matchAll(/self::([A-Z]+),/g)].map((match) => match[1])

    const values = constants.map((name) => {
      const found = source.match(new RegExp(`public const ${name} = '([a-z]+)'`))

      expect(found).not.toBeNull()

      return (found as RegExpMatchArray)[1]
    })

    expect(values).toEqual([...VEHICLE_TYPES])
  })
})
