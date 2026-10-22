import { Library, Plus, RefreshCw, Unlink } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { IconButton } from '@/shared/ui/icon-button'
import { IconTile } from '@/shared/ui/icon-tile'

import { gameSystemKey as draftKey, isStaleWrite, StudioError } from '../useGameSystems'
import { ImportDialog } from './ImportDialog'
import { sectionOrder, sectionTitles } from './sections'
import {
  useDetachLibraryImport,
  useLibraryImports,
  useUpdateLibraryImport,
  type LibraryImport,
  type LibraryImports,
} from './useLibraryImports'

const statusBadges = {
  current: { label: 'Current', variant: 'secondary' },
  changed: { label: 'Changed', variant: 'warning' },
  removed: { label: 'Removed', variant: 'destructive' },
} as const

/**
 * The Library tab of a draft: what it imported from library packs and where each import stands
 * against its pack, to update or detach, and the button to import more. A schema version 1 draft
 * cannot import, so it only gets a notice.
 */
export function LibraryTab({
  gameSystemKey,
  schemaVersion,
}: {
  gameSystemKey: string
  schemaVersion: number
}) {
  if (schemaVersion < 2) {
    return (
      <p className="text-muted-foreground">
        Schema version 1 drafts import nothing from the library: library imports exist only in
        schema version 2 drafts.
      </p>
    )
  }
  return <LibraryImportsPanel gameSystemKey={gameSystemKey} />
}

function LibraryImportsPanel({ gameSystemKey }: { gameSystemKey: string }) {
  const imports = useLibraryImports(gameSystemKey, true)
  const queryClient = useQueryClient()
  const update = useUpdateLibraryImport(gameSystemKey)
  const detach = useDetachLibraryImport(gameSystemKey)
  const [importing, setImporting] = useState(false)

  if (imports.data === undefined) {
    if (imports.isPending) {
      return <p className="text-muted-foreground">Loading the library imports…</p>
    }
    return (
      <p role="alert" className="text-sm text-destructive">
        {imports.error instanceof StudioError && imports.error.status === 404
          ? 'This GameSystem does not exist, or it is not one of yours.'
          : imports.error.message}
      </p>
    )
  }

  const { version } = imports.data
  const busy = update.isPending || detach.isPending
  const failure = update.error ?? detach.error

  /** What a stale write was refused over: the imports with their version, and the draft. */
  async function reload() {
    const [result] = await Promise.all([
      imports.refetch(),
      queryClient.invalidateQueries({ queryKey: draftKey(gameSystemKey), exact: true }),
    ])
    // A failed reload leaves the refusal in view: nothing was reloaded.
    if (!result.isError) {
      update.reset()
      detach.reset()
    }
    return !result.isError
  }

  function run(action: 'update' | 'detach', item: LibraryImport) {
    update.reset()
    detach.reset()
    const variables = { expectedVersion: version, section: item.section, item: item.key }
    if (action === 'update') {
      update.mutate(variables)
    } else {
      detach.mutate(variables)
    }
  }

  return (
    <section aria-label="Library" className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <Library />
        </IconTile>
        <h2 className="font-heading text-panel-title">Library imports</h2>
        <Button
          className="ml-auto"
          variant="outline"
          size="sm"
          onClick={() => {
            setImporting(true)
          }}
        >
          <Plus aria-hidden="true" />
          Import from library
        </Button>
      </div>
      <div className="space-y-6 panel-body">
        {imports.isRefetchError && (
          <p role="alert" className="text-sm text-destructive">
            Couldn&apos;t refresh the library imports. {imports.error.message}
          </p>
        )}
        {failure && (
          <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-destructive">
            <p>{failure.message}</p>
            {isStaleWrite(failure) && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  void reload()
                }}
              >
                Reload the latest version
              </Button>
            )}
          </div>
        )}
        <ImportList
          imports={imports.data}
          busy={busy}
          onUpdate={(item) => {
            run('update', item)
          }}
          onDetach={(item) => {
            run('detach', item)
          }}
          onImport={() => {
            setImporting(true)
          }}
        />
        <ImportDialog
          gameSystemKey={gameSystemKey}
          version={version}
          open={importing}
          onOpenChange={setImporting}
          onReload={reload}
        />
      </div>
    </section>
  )
}

function ImportList({
  imports,
  busy,
  onUpdate,
  onDetach,
  onImport,
}: {
  imports: LibraryImports
  busy: boolean
  onUpdate: (item: LibraryImport) => void
  onDetach: (item: LibraryImport) => void
  onImport: () => void
}) {
  if (imports.imports.length === 0) {
    return (
      <div className="empty-state">
        <IconTile size="sm">
          <Library />
        </IconTile>
        <p>
          This draft has imported nothing from the library yet. Import oracles, trackers, tags, fact
          slots or Scene Types from a library pack.
        </p>
        <Button onClick={onImport}>Import from library</Button>
      </div>
    )
  }
  return (
    <>
      {sectionOrder.map((section) => {
        const items = imports.imports.filter((item) => item.section === section)
        return items.length === 0 ? null : (
          <section key={section} aria-label={sectionTitles[section]} className="space-y-2">
            <h3 className="group-label">{sectionTitles[section]}</h3>
            <ul className="overflow-hidden rounded-lg border">
              {items.map((item) => (
                <ImportRow
                  key={item.key}
                  item={item}
                  busy={busy}
                  onUpdate={() => {
                    onUpdate(item)
                  }}
                  onDetach={() => {
                    onDetach(item)
                  }}
                />
              ))}
            </ul>
          </section>
        )
      })}
    </>
  )
}

function ImportRow({
  item,
  busy,
  onUpdate,
  onDetach,
}: {
  item: LibraryImport
  busy: boolean
  onUpdate: () => void
  onDetach: () => void
}) {
  const status = statusBadges[item.status]
  // A removed item has nothing to update from: it can only be detached.
  const updatable = item.status === 'changed' && !busy

  return (
    <li className="list-row">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <span className="list-row-title">{item.label}</span>
          <span className="list-row-key break-all">{item.key}</span>
          <Badge variant={status.variant}>{status.label}</Badge>
          {item.editedLocally && <Badge variant="outline">Edited locally</Badge>}
        </p>
        <p className="text-sm text-muted-foreground">
          {item.pack === null ? (
            <Badge variant="outline">Removed pack</Badge>
          ) : (
            <>
              From {item.pack.name} <span className="font-mono">{item.pack.key}</span>
            </>
          )}
        </p>
        {item.status === 'removed' && (
          <p className="text-sm text-muted-foreground">
            The pack or its item is gone: the item stays in the draft, and you can detach it.
          </p>
        )}
      </div>
      {item.editedLocally ? (
        <ConfirmDialog
          trigger={`Update ${item.key}`}
          icon={<RefreshCw />}
          disabled={!updatable}
          title={`Update ${item.key}?`}
          description="Update replaces your local edits with the pack's current item."
          confirm="Replace my edits"
          onConfirm={onUpdate}
        />
      ) : (
        <IconButton label={`Update ${item.key}`} dense disabled={!updatable} onClick={onUpdate}>
          <RefreshCw />
        </IconButton>
      )}
      <ConfirmDialog
        trigger={`Detach ${item.key}`}
        icon={<Unlink />}
        disabled={busy}
        title={`Detach ${item.key}?`}
        description="The item stays in the draft, but it no longer follows the library pack."
        confirm="Detach the item"
        onConfirm={onDetach}
      />
    </li>
  )
}

/** A button that asks before it acts; the action's result shows in the tab, not here. */
function ConfirmDialog({
  trigger,
  icon,
  disabled,
  title,
  description,
  confirm,
  onConfirm,
}: {
  trigger: string
  icon: ReactNode
  disabled: boolean
  title: string
  description: string
  confirm: string
  onConfirm: () => void
}) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <IconButton label={trigger} dense disabled={disabled}>
          {icon}
        </IconButton>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <Button
          variant="destructive"
          onClick={() => {
            setOpen(false)
            onConfirm()
          }}
        >
          {confirm}
        </Button>
      </DialogContent>
    </Dialog>
  )
}
