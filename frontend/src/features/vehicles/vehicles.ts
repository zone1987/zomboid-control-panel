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
