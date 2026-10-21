import { Link } from '@tanstack/react-router'
import { Library } from 'lucide-react'

import { Badge } from '@/shared/ui/badge'
import { IconTile } from '@/shared/ui/icon-tile'

import { SectionCounts } from '../SectionCounts'
import { NewLibraryPackDialog } from './NewLibraryPackDialog'
import { useLibraryPacks, type LibraryPackSummary } from './useLibraryPacks'

/**
 * The Library section of the Studio home: every pack, mine first. My packs open on their
 * editable page, the others on the read-only catalog.
 */
export function LibraryPacks() {
  const packs = useLibraryPacks()

  return (
    <section aria-labelledby="library-packs" className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <h2 id="library-packs" className="section-title">
            Library
          </h2>
          {packs.data && <span className="count-pill">{packs.data.length}</span>}
        </div>
        <NewLibraryPackDialog />
      </div>
      <Packs />
    </section>
  )
}

function Packs() {
  const packs = useLibraryPacks()
  if (packs.isPending) {
    return <p className="text-muted-foreground">Loading the library…</p>
  }
  if (packs.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {packs.error.message}
      </p>
    )
  }
  if (packs.data.length === 0) {
    return (
      <p className="text-muted-foreground">
        The library has no packs yet. Create one to share items between GameSystems.
      </p>
    )
  }
  return (
    <ul className="grid grid-cols-[repeat(auto-fill,minmax(min(320px,100%),1fr))] gap-4">
      {packs.data.map((pack) => (
        <li key={pack.key}>
          <PackCard pack={pack} />
        </li>
      ))}
    </ul>
  )
}

function PackCard({ pack }: { pack: LibraryPackSummary }) {
  const linkClass = 'underline-offset-4 hover:underline'
  return (
    <div className="flex gap-3 panel p-4">
      <IconTile size="lg">
        <Library />
      </IconTile>
      <div className="min-w-0 flex-1 space-y-1.5">
        <h3 className="flex flex-wrap items-center gap-2 font-heading text-panel-title break-words">
          {pack.mine ? (
            <Link to="/studio/game-systems/$key" params={{ key: pack.key }} className={linkClass}>
              {pack.name}
            </Link>
          ) : (
            <Link to="/studio/library/$pack" params={{ pack: pack.key }} className={linkClass}>
              {pack.name}
            </Link>
          )}
          {pack.mine && <Badge variant="secondary">Yours</Badge>}
        </h3>
        <p className="list-row-key break-all">{pack.key}</p>
        {pack.description !== null && <p className="text-sm break-words">{pack.description}</p>}
        <div className="text-xs text-muted-foreground">
          <SectionCounts counts={pack.itemCounts} compact />
        </div>
      </div>
    </div>
  )
}
