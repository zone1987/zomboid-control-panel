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

export type SpawnableVehicle = {
  script: string
  name: string
  body: string
  model: string | null
  texture: string | null
  /** Whether the artwork is both named and uploaded. */
  drawable: boolean
}

export type VehicleCatalogue = {
  bodies: VehicleBody[]
  items: SpawnableVehicle[]
  generatedAt: number | null
  bridgeVersion: string | null
  available: boolean
}

export function listVehicles(serverId: string, refresh = false): Promise<VehicleCatalogue> {
  const query = refresh ? '?refresh=1' : ''

  return apiFetch<VehicleCatalogue>(`/servers/${serverId}/vehicles${query}`)
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

/**
 * One representative per body, for the state before a body is chosen.
 *
 * Prefers a drawable member so the grid is not a wall of fallback icons
 * on a server whose artwork is only partly uploaded.
 */
export function representatives(catalogue: VehicleCatalogue): SpawnableVehicle[] {
  return catalogue.bodies
    .map((body) => {
      const members = catalogue.items.filter((item) => item.body === body.id)

      return members.find((member) => member.drawable) ?? members[0]
    })
    .filter((member): member is SpawnableVehicle => member !== undefined)
}

export type Selection = {
  needle: string
  body: string | null
  onlyFavourites: boolean
}

/**
 * The bodies the top grid shows.
 *
 * Filtering by favourite narrows the bodies to the marked ones, and to
 * those holding a marked livery -- a favourite livery whose body was
 * never marked must still be reachable.
 */
export function selectBodies(
  catalogue: VehicleCatalogue,
  onlyFavourites: boolean,
  isFavouriteBody: (id: string) => boolean,
  isFavouriteVehicle: (script: string) => boolean,
): VehicleBody[] {
  if (!onlyFavourites) {
    return catalogue.bodies
  }

  const bodiesHoldingOne = new Set(
    catalogue.items.filter((item) => isFavouriteVehicle(item.script)).map((item) => item.body),
  )

  return catalogue.bodies.filter(
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
  if (selection.needle.trim() !== '') {
    return items.filter((vehicle) => matches(vehicle, selection.needle))
  }

  if (selection.body !== null) {
    return items.filter((vehicle) => vehicle.body === selection.body)
  }

  if (selection.onlyFavourites) {
    return items.filter(
      (vehicle) => isFavouriteVehicle(vehicle.script) || isFavouriteBody(vehicle.body),
    )
  }

  return []
}
