import { createContext, useContext } from 'react'

import { Button } from '@/shared/ui/button'

import type { ListKind } from './useLists'

/**
 * How a list is opened, provided by the screen that shows the Threads and NPCs panels. Without
 * a provider there is no panel to open, so no links show.
 */
const OpenListContext = createContext<((kind: ListKind) => void) | null>(null)

export const OpenListProvider = OpenListContext.Provider

const links: { kind: ListKind; label: string }[] = [
  { kind: 'thread', label: 'Threads' },
  { kind: 'npc', label: 'NPCs' },
]

/** "Threads" and "NPCs" links that open those panels, for the guide and focus mode. */
export function ListLinks() {
  const open = useContext(OpenListContext)
  if (open === null) {
    return null
  }
  return (
    <nav aria-label="Lists" className="flex flex-wrap items-center gap-1 text-sm">
      <span className="text-muted-foreground">Open: </span>
      {links.map(({ kind, label }) => (
        <Button
          key={kind}
          type="button"
          variant="link"
          size="sm"
          onClick={() => {
            open(kind)
          }}
        >
          {label}
        </Button>
      ))}
    </nav>
  )
}
