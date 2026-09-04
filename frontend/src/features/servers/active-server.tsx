import { createContext, use, useCallback, useMemo, useState } from 'react'

type ActiveServerContextValue = {
  activeServerId: string | null
  setActiveServerId: (id: string | null) => void
}

const STORAGE_KEY = 'zomboidcontrol.activeServer'

const ActiveServerContext = createContext<ActiveServerContextValue | null>(null)

function readStored(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

export function ActiveServerProvider({ children }: { children: React.ReactNode }) {
  const [activeServerId, setState] = useState<string | null>(readStored)

  const setActiveServerId = useCallback((id: string | null) => {
    try {
      if (id === null) {
        localStorage.removeItem(STORAGE_KEY)
      } else {
        localStorage.setItem(STORAGE_KEY, id)
      }
    } catch {
      // A forgotten selection is a small annoyance; failing to switch is not.
    }

    setState(id)
  }, [])

  const value = useMemo(
    () => ({ activeServerId, setActiveServerId }),
    [activeServerId, setActiveServerId],
  )

  return <ActiveServerContext value={value}>{children}</ActiveServerContext>
}

export function useActiveServer(): ActiveServerContextValue {
  const context = use(ActiveServerContext)

  if (!context) {
    throw new Error('useActiveServer must be used inside an ActiveServerProvider.')
  }

  return context
}
