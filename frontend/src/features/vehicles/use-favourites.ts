import { useCallback, useState } from 'react'

const STORAGE_PREFIX = 'zomboidcontrol.vehicleFavourites'

/** Bodies and liveries are kept apart: a body id is not a script name. */
export type FavouriteKind = 'body' | 'vehicle'

const keyFor = (serverId: string, kind: FavouriteKind) =>
  `${STORAGE_PREFIX}.${kind}.${serverId}`

function readStored(serverId: string, kind: FavouriteKind): string[] {
  try {
    const raw = localStorage.getItem(keyFor(serverId, kind))

    if (raw === null) {
      return []
    }

    const parsed: unknown = JSON.parse(raw)

    return Array.isArray(parsed) ? parsed.filter((entry) => typeof entry === 'string') : []
  } catch {
    return []
  }
}

export type Favourites = {
  ids: string[]
  has: (id: string) => boolean
  toggle: (id: string) => void
}

/** Favourites of one kind, kept per server in the browser that marked them. */
export function useFavourites(serverId: string, kind: FavouriteKind): Favourites {
  const [ids, setIds] = useState<string[]>(() => readStored(serverId, kind))

  const toggle = useCallback(
    (id: string) => {
      setIds((previous) => {
        const next = previous.includes(id)
          ? previous.filter((entry) => entry !== id)
          : [...previous, id]

        try {
          localStorage.setItem(keyFor(serverId, kind), JSON.stringify(next))
        } catch {
          // A forgotten favourite is a small annoyance; refusing the click is not.
        }

        return next
      })
    },
    [serverId, kind],
  )

  const has = useCallback((id: string) => ids.includes(id), [ids])

  return { ids, has, toggle }
}
