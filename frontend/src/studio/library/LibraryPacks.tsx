import { Link } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card'

import { SectionCounts } from '../SectionCounts'
import { NewLibraryPackDialog } from './NewLibraryPackDialog'
import { useLibraryPacks, type LibraryPackSummary } from './useLibraryPacks'

/**
 * The Library section of the Studio home: every pack, mine first. My packs open on their
 * editable page, the others on the read-only catalog.
 */
export function LibraryPacks() {
  return (
    <section aria-labelledby="library-packs" className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="library-packs" className="font-heading text-xl font-semibold">
          Library
        </h2>
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
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2 break-words">
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
        </CardTitle>
        <p className="font-mono text-sm break-all text-muted-foreground">{pack.key}</p>
      </CardHeader>
      <CardContent className="space-y-2 text-sm">
        {pack.description !== null && <p className="break-words">{pack.description}</p>}
        <SectionCounts counts={pack.itemCounts} compact />
      </CardContent>
    </Card>
  )
}
