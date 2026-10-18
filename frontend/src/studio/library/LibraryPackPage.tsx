import { Link } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'

import { StudioError } from '../useGameSystems'
import {
  useLibraryPack,
  type LibraryPackCatalog,
  type LibraryPackCatalogSection,
} from './useLibraryPacks'

/** How the catalog's section names read. */
const sectionTitles: Record<LibraryPackCatalogSection['section'], string> = {
  oracles: 'Oracles',
  trackers: 'Trackers',
  tags: 'Tags',
  'fact-slots': 'Fact slots',
  'scene-types': 'Scene Types',
}

const kindLabels = { table: 'Table', likelihood: 'Likelihood' } as const

/**
 * `/studio/library/$pack`: the items of a library pack by section, read only. My own pack links
 * to its editable page. An unknown pack is not found.
 */
export function LibraryPackPage({ pack }: { pack: string }) {
  const catalog = useLibraryPack(pack)

  return (
    <div className="space-y-6">
      <Link
        to="/studio"
        className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
      >
        <span aria-hidden="true">← </span>Back to Studio
      </Link>
      <CatalogState catalog={catalog} />
    </div>
  )
}

function CatalogState({ catalog }: { catalog: ReturnType<typeof useLibraryPack> }) {
  if (catalog.isPending) {
    return <p className="text-muted-foreground">Loading the pack…</p>
  }
  if (catalog.isError) {
    return catalog.error instanceof StudioError && catalog.error.status === 404 ? (
      <section className="space-y-3">
        <h1 className="font-heading text-page-title">No such pack</h1>
        <p className="text-muted-foreground">This library pack does not exist.</p>
      </section>
    ) : (
      <p role="alert" className="text-sm text-destructive">
        {catalog.error.message}
      </p>
    )
  }
  return <Catalog catalog={catalog.data} />
}

function Catalog({ catalog }: { catalog: LibraryPackCatalog }) {
  return (
    <>
      <header className="space-y-2">
        <h1 className="font-heading text-page-title break-words">{catalog.name}</h1>
        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted-foreground">
          <span className="font-mono break-all">{catalog.key}</span>
          <Badge variant="secondary">Library pack</Badge>
          {catalog.mine && <Badge variant="secondary">Yours</Badge>}
        </p>
        {catalog.description !== null && <p className="break-words">{catalog.description}</p>}
        {catalog.mine && (
          <p>
            <Link
              to="/studio/game-systems/$key"
              params={{ key: catalog.key }}
              className="underline underline-offset-4"
            >
              Edit this pack
            </Link>
          </p>
        )}
      </header>
      {catalog.sections.map(({ section, items }) => (
        <section key={section} aria-label={sectionTitles[section]} className="space-y-2">
          <h2 className="font-heading text-xl font-semibold">{sectionTitles[section]}</h2>
          {items.length === 0 ? (
            <p className="text-muted-foreground">No items.</p>
          ) : (
            <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
              {items.map((item) => (
                <li key={item.key} className="space-y-1 px-4 py-3">
                  <p className="flex flex-wrap items-baseline gap-x-3">
                    <span className="font-medium break-words">{item.label}</span>
                    <span className="font-mono text-sm break-all text-muted-foreground">
                      {item.key}
                    </span>
                    {item.kind !== null && <Badge variant="outline">{kindLabels[item.kind]}</Badge>}
                  </p>
                  {item.dependsOn.length > 0 && (
                    <p className="text-sm break-words text-muted-foreground">
                      Uses: {item.dependsOn.map((ref) => ref.key).join(', ')}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      ))}
    </>
  )
}
