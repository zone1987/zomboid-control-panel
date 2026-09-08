import { apiFetch } from '@/lib/api'

/** Bodies whose members are wrecks; the backend leaves their name empty. */
export const WRECKS = 'wrecks'

export type VehicleBody = {
  id: string
  /** Empty when the frontend names it, as for the wrecks group. */
  name: string
  count: number
  /** A member with artwork, to picture the body. Null when none has any. */
  preview: string | null
}

/**
 * The kinds the backend sorts vehicles into, in the order the filter
 * shows them. Mirrored from `VehicleTypes::ORDER`, and asserted against
 * it by a test rather than trusted.
 */
export const VEHICLE_TYPES = [
  'small',
  'car',
  'sports',
  'suv',
  'pickup',
  'van',
  'service',
  'trailer',
  'wreck',
] as const

export type VehicleType = (typeof VEHICLE_TYPES)[number]

/** What a vehicle's own script says about it. Null for a modded one. */
export type VehicleSpecs = {
  seats: number | null
  trunk: number | null
  gloveBox: number | null
  mass: number | null
  maxSpeed: number | null
  engineForce: number | null
  brakingForce: number | null
  mechanicType: number | null
  engineLoudness: number | null
  engineQuality: number | null
}

export type SpawnableVehicle = {
  script: string
  name: string
  body: string
  type: string
  model: string | null
  texture: string | null
  /** Whether the artwork is both named and uploaded. */
  drawable: boolean
  specs: VehicleSpecs | null
}

export type VehicleCatalogue = {
  bodies: VehicleBody[]
  items: SpawnableVehicle[]
  generatedAt: number | null
  bridgeVersion: string | null
  available: boolean
}

export function listVehicles(
  serverId: string,
  language: string,
  refresh = false,
): Promise<VehicleCatalogue> {
  const query = new URLSearchParams({ language })

  if (refresh) {
    query.set('refresh', '1')
  }

  return apiFetch<VehicleCatalogue>(`/servers/${serverId}/vehicles?${query}`)
}

export function spawnVehicle(
  serverId: string,
  script: string,
  player: string,
): Promise<{ status: string; command: string; reply: string; failed: boolean }> {
  return apiFetch(`/servers/${serverId}/vehicles/spawn`, {
    method: 'POST',
    body: { script, player },
  })
}

/**
 * Whether a vehicle matches what was typed.
 *
 * Both the display name and the script are searched: an operator may
 * know "Chevalier Nyala" or "CarLights", and a modded vehicle often has
 * no display name at all.
 */
export function matches(vehicle: SpawnableVehicle, needle: string): boolean {
  const term = needle.trim().toLowerCase()

  if (term === '') {
    return true
  }

  return (
    vehicle.name.toLowerCase().includes(term) ||
    vehicle.script.toLowerCase().includes(term)
  )
}

export type Selection = {
  needle: string
  body: string | null
  onlyFavourites: boolean
  /** Empty means every type; otherwise only these. */
  types?: string[]
}

/** Whether a type filter lets this vehicle through. */
function inTypes(vehicle: SpawnableVehicle, types: string[] | undefined): boolean {
  return types === undefined || types.length === 0 || types.includes(vehicle.type)
}

/**
 * Which types the catalogue actually holds, in the offered order.
 *
 * Derived from the vehicles rather than listed, so a filter never offers
 * a type this server has none of -- a modded server may have no
 * trailers, and a dead button is worse than a missing one.
 */
export function typesPresent(items: SpawnableVehicle[]): VehicleType[] {
  const seen = new Set(items.map((item) => item.type))

  return VEHICLE_TYPES.filter((type) => seen.has(type))
}

/**
 * The bodies the top grid shows.
 *
 * Filtering by favourite narrows the bodies to the marked ones, and to
 * those holding a marked livery -- a favourite livery whose body was
 * never marked must still be reachable. A type filter narrows them to
 * the bodies still holding something.
 */
export function selectBodies(
  catalogue: VehicleCatalogue,
  onlyFavourites: boolean,
  isFavouriteBody: (id: string) => boolean,
  isFavouriteVehicle: (script: string) => boolean,
  types: string[] = [],
): VehicleBody[] {
  const withinTypes =
    types.length === 0
      ? catalogue.bodies
      : catalogue.bodies.filter((body) =>
          catalogue.items.some(
            (item) => item.body === body.id && types.includes(item.type),
          ),
        )

  if (!onlyFavourites) {
    return withinTypes
  }

  const bodiesHoldingOne = new Set(
    catalogue.items
      .filter((item) => isFavouriteVehicle(item.script) && inTypes(item, types))
      .map((item) => item.body),
  )

  return withinTypes.filter(
    (body) => isFavouriteBody(body.id) || bodiesHoldingOne.has(body.id),
  )
}

/**
 * Which vehicles the lower grid shows.
 *
 * A chosen body always wins: its liveries are all shown, favourite or
 * not, because choosing a shell is the request to see what it comes in.
 * With no body chosen, the favourites stand in for it -- the marked
 * liveries, plus every livery of a marked body.
 */
export function select(
  items: SpawnableVehicle[],
  selection: Selection,
  isFavouriteVehicle: (script: string) => boolean,
  isFavouriteBody: (id: string) => boolean = () => false,
): SpawnableVehicle[] {
  // The type filter narrows the ground the other steps work on rather
  // than being a mode of its own, so it combines with all of them.
  const within = items.filter((vehicle) => inTypes(vehicle, selection.types))

  if (selection.needle.trim() !== '') {
    return within.filter((vehicle) => matches(vehicle, selection.needle))
  }

  if (selection.body !== null) {
    return within.filter((vehicle) => vehicle.body === selection.body)
  }

  if (selection.onlyFavourites) {
    return within.filter(
      (vehicle) => isFavouriteVehicle(vehicle.script) || isFavouriteBody(vehicle.body),
    )
  }

  return []
}
