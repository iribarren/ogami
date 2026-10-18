import { useState } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/dialog'

import { StudioError } from '../useGameSystems'
import { sectionTitles } from './sections'
import {
  useImportLibraryItems,
  usePreviewLibraryImport,
  type LibraryImportPreview,
  type LibraryItemRef,
} from './useLibraryImports'
import {
  useLibraryPack,
  useLibraryPacks,
  type LibraryPackCatalog,
  type LibraryPackSummary,
} from './useLibraryPacks'

const kindLabels = { table: 'Table', likelihood: 'Likelihood' } as const

const idOf = ({ section, key }: LibraryItemRef) => `${section}/${key}`

/**
 * Imports items of a library pack into the draft: pick a pack, tick the items of its catalog,
 * preview what the import would do, then import. The dialog closes on success and the Library tab
 * shows the new list.
 */
export function ImportDialog({
  gameSystemKey,
  version,
  open,
  onOpenChange,
  onReload,
}: {
  gameSystemKey: string
  /** The draft version the imports were read at: sent as `expectedVersion`. */
  version: number
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Reloads the imports and the draft after a stale write; false when that failed. */
  onReload: () => Promise<boolean>
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import from library</DialogTitle>
          <DialogDescription>
            Copy items of a library pack into this draft. What they need comes along, and a key the
            draft already has is kept.
          </DialogDescription>
        </DialogHeader>
        <ImportSteps
          gameSystemKey={gameSystemKey}
          version={version}
          onImported={() => {
            onOpenChange(false)
          }}
          onReload={onReload}
        />
      </DialogContent>
    </Dialog>
  )
}

function Alert({ children }: { children: string }) {
  return (
    <p role="alert" className="text-sm text-destructive">
      {children}
    </p>
  )
}

function ImportSteps({
  gameSystemKey,
  version,
  onImported,
  onReload,
}: {
  gameSystemKey: string
  version: number
  onImported: () => void
  onReload: () => Promise<boolean>
}) {
  const [pack, setPack] = useState<string | null>(null)

  return pack === null ? (
    <PackPicker onPick={setPack} />
  ) : (
    <ItemPicker
      key={pack}
      gameSystemKey={gameSystemKey}
      version={version}
      pack={pack}
      onBack={() => {
        setPack(null)
      }}
      onImported={onImported}
      onReload={onReload}
    />
  )
}

function PackPicker({ onPick }: { onPick: (pack: string) => void }) {
  const packs = useLibraryPacks()

  if (packs.isPending) {
    return <p className="text-muted-foreground">Loading the library packs…</p>
  }
  if (packs.isError) {
    return <Alert>{packs.error.message}</Alert>
  }
  if (packs.data.length === 0) {
    return <p className="text-muted-foreground">The library has no packs yet.</p>
  }
  return (
    <section aria-label="Library packs" className="space-y-2">
      <h3 className="font-heading font-semibold">Choose a pack</h3>
      <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
        {packs.data.map((pack) => (
          <PackChoice key={pack.key} pack={pack} onPick={onPick} />
        ))}
      </ul>
    </section>
  )
}

function PackChoice({
  pack,
  onPick,
}: {
  pack: LibraryPackSummary
  onPick: (pack: string) => void
}) {
  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3">
          <span className="font-medium break-words">{pack.name}</span>
          <span className="font-mono text-sm break-all text-muted-foreground">{pack.key}</span>
          {pack.mine && <Badge variant="secondary">Yours</Badge>}
        </p>
        {pack.description !== null && (
          <p className="text-sm break-words text-muted-foreground">{pack.description}</p>
        )}
      </div>
      <Button
        variant="outline"
        size="sm"
        aria-label={`Choose ${pack.name}`}
        onClick={() => {
          onPick(pack.key)
        }}
      >
        Choose
      </Button>
    </li>
  )
}

function ItemPicker({
  gameSystemKey,
  version,
  pack,
  onBack,
  onImported,
  onReload,
}: {
  gameSystemKey: string
  version: number
  pack: string
  onBack: () => void
  onImported: () => void
  onReload: () => Promise<boolean>
}) {
  const catalog = useLibraryPack(pack)

  return (
    <div className="space-y-4">
      <Button variant="outline" size="sm" onClick={onBack}>
        Choose another pack
      </Button>
      {catalog.isPending && <p className="text-muted-foreground">Loading the pack…</p>}
      {catalog.isError && <Alert>{catalog.error.message}</Alert>}
      {catalog.data && (
        <Selection
          gameSystemKey={gameSystemKey}
          version={version}
          catalog={catalog.data}
          onImported={onImported}
          onReload={onReload}
        />
      )}
    </div>
  )
}

function Selection({
  gameSystemKey,
  version,
  catalog,
  onImported,
  onReload,
}: {
  gameSystemKey: string
  version: number
  catalog: LibraryPackCatalog
  onImported: () => void
  onReload: () => Promise<boolean>
}) {
  const [selected, setSelected] = useState<LibraryItemRef[]>([])
  const [reloadFailed, setReloadFailed] = useState(false)
  const preview = usePreviewLibraryImport(gameSystemKey)
  const importItems = useImportLibraryItems(gameSystemKey)
  const pending = preview.isPending || importItems.isPending
  const result = preview.data
  const blocked = result !== undefined && (result.conflicts.length > 0 || result.error !== null)
  const failure = preview.error ?? importItems.error

  function toggle(ref: LibraryItemRef, checked: boolean) {
    // What was previewed or refused described the old selection.
    preview.reset()
    importItems.reset()
    setSelected((now) =>
      checked ? [...now, ref] : now.filter((other) => idOf(other) !== idOf(ref)),
    )
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        <span className="font-medium text-foreground">{catalog.name}</span>{' '}
        <span className="font-mono">{catalog.key}</span>
      </p>
      {catalog.sections.map(({ section, items }) =>
        items.length === 0 ? null : (
          <fieldset key={section} className="space-y-1">
            <legend className="font-heading font-semibold">{sectionTitles[section]}</legend>
            {items.map((item) => {
              const ref = { section, key: item.key }
              return (
                <label key={item.key} className="flex items-start gap-2 py-1">
                  <input
                    type="checkbox"
                    className="mt-1"
                    checked={selected.some((other) => idOf(other) === idOf(ref))}
                    onChange={(event) => {
                      toggle(ref, event.target.checked)
                    }}
                  />
                  <span className="min-w-0 space-y-0.5">
                    <span className="flex flex-wrap items-baseline gap-x-3">
                      <span className="font-medium break-words">{item.label}</span>
                      <span className="font-mono text-sm break-all text-muted-foreground">
                        {item.key}
                      </span>
                      {item.kind !== null && (
                        <Badge variant="outline">{kindLabels[item.kind]}</Badge>
                      )}
                    </span>
                    {item.dependsOn.length > 0 && (
                      <span className="block text-sm break-words text-muted-foreground">
                        Also brings {item.dependsOn.map((need) => need.key).join(', ')}
                      </span>
                    )}
                  </span>
                </label>
              )
            })}
          </fieldset>
        ),
      )}
      {catalog.sections.every(({ items }) => items.length === 0) && (
        <p className="text-muted-foreground">This pack has no items yet.</p>
      )}
      {result && <PreviewResult preview={result} />}
      {failure && <Alert>{failure.message}</Alert>}
      {reloadFailed && <Alert>Couldn&apos;t reload the latest version. Try again.</Alert>}
      <div className="flex flex-wrap gap-2">
        {failure instanceof StudioError && failure.status === 409 && (
          <Button
            variant="outline"
            disabled={pending}
            onClick={() => {
              void onReload().then((ok) => {
                setReloadFailed(!ok)
                if (ok) {
                  preview.reset()
                  importItems.reset()
                }
              })
            }}
          >
            Reload the latest version
          </Button>
        )}
        <Button
          variant="outline"
          disabled={selected.length === 0 || pending}
          onClick={() => {
            importItems.reset()
            preview.mutate({ pack: catalog.key, items: selected })
          }}
        >
          Preview
        </Button>
        <Button
          disabled={selected.length === 0 || result === undefined || blocked || pending}
          onClick={() => {
            importItems.mutate(
              { expectedVersion: version, pack: catalog.key, items: selected },
              { onSuccess: onImported },
            )
          }}
        >
          Import
        </Button>
      </div>
    </div>
  )
}

function RefList({ title, refs }: { title: string; refs: LibraryItemRef[] }) {
  return refs.length === 0 ? null : (
    <div className="space-y-1">
      <h4 className="font-medium">{title}</h4>
      <ul className="list-disc pl-5">
        {refs.map((ref) => (
          <li key={idOf(ref)}>
            {sectionTitles[ref.section]}: <span className="font-mono">{ref.key}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

function PreviewResult({ preview }: { preview: LibraryImportPreview }) {
  return (
    <section
      aria-label="Preview"
      className="space-y-3 rounded-xl p-3 text-sm ring-1 ring-foreground/10"
    >
      <RefList title="Will be imported" refs={preview.imports} />
      <RefList title="Kept from your draft" refs={preview.overrides} />
      {preview.conflicts.length > 0 && (
        <div className="space-y-1 text-destructive">
          <RefList title="Already in your draft" refs={preview.conflicts} />
          <p>The draft already has these items. Choose others, or detach or rename them first.</p>
        </div>
      )}
      {preview.error !== null && (
        <p role="alert" className="text-destructive">
          The import would break a rule at {preview.error.path}: {preview.error.message}
        </p>
      )}
    </section>
  )
}
