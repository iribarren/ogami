import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import { SectionCounts } from './SectionCounts'
import {
  useGameSystemRelease,
  useGameSystemReleases,
  useRestoreGameSystem,
  type ReleaseSummary,
} from './useGameSystemReleases'
import { StudioError, type GameSystem } from './useGameSystems'

const publishedAtFormat = new Intl.DateTimeFormat('en-GB', {
  dateStyle: 'medium',
  timeStyle: 'short',
})

/** The short form of a content hash a UI shows. */
const shortHash = (hash: string) => hash.slice(0, 8)

/**
 * The Releases tab: the history of a GameSystem, newest first, with a read-only view of one
 * release and a way to restore it into the draft.
 */
export function ReleasesTab({
  gameSystemKey,
  onReload,
  onRestored,
}: {
  gameSystemKey: string
  onReload: () => void
  onRestored: (draft: GameSystem, version: number) => void
}) {
  const releases = useGameSystemReleases(gameSystemKey)
  const [selected, setSelected] = useState<number | null>(null)

  if (releases.isPending) {
    return <p className="text-muted-foreground">Loading the releases…</p>
  }
  if (releases.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {releases.error.message}
      </p>
    )
  }
  if (releases.data.length === 0) {
    return (
      <section aria-label="Releases" className="space-y-2">
        <p className="font-medium">Never published</p>
        <p className="text-muted-foreground">
          Publish the draft to create version 1. Releases appear here, newest first.
        </p>
      </section>
    )
  }

  return (
    <div className="space-y-8">
      <section aria-label="Releases">
        <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
          {releases.data.map((release) => (
            <ReleaseRow
              key={release.version}
              release={release}
              selected={release.version === selected}
              onSelect={() => {
                setSelected(release.version)
              }}
            />
          ))}
        </ul>
      </section>
      {selected !== null && (
        <ReleaseView
          // A fresh view (and restore dialog) per release.
          key={selected}
          gameSystemKey={gameSystemKey}
          version={selected}
          onReload={onReload}
          onRestored={onRestored}
        />
      )}
    </div>
  )
}

function ReleaseRow({
  release,
  selected,
  onSelect,
}: {
  release: ReleaseSummary
  selected: boolean
  onSelect: () => void
}) {
  return (
    <li className="flex flex-wrap items-start gap-x-6 gap-y-2 px-4 py-3">
      <div className="min-w-0 flex-1 space-y-1">
        <p className="flex flex-wrap items-baseline gap-x-3">
          <span className="font-heading font-semibold">Version {release.version}</span>
          <span className="text-sm text-muted-foreground">
            Schema version {release.schemaVersion}
          </span>
          <span className="text-sm text-muted-foreground">
            {publishedAtFormat.format(new Date(release.publishedAt))}
          </span>
          <span className="font-mono text-sm text-muted-foreground">
            {shortHash(release.contentHash)}
          </span>
        </p>
        <p className={release.notes === null ? 'text-muted-foreground' : 'break-words'}>
          {release.notes ?? 'No notes'}
        </p>
      </div>
      <Button
        variant="outline"
        size="sm"
        aria-pressed={selected}
        aria-label={`View version ${String(release.version)}`}
        onClick={onSelect}
      >
        View
      </Button>
    </li>
  )
}

function ReleaseView({
  gameSystemKey,
  version,
  onReload,
  onRestored,
}: {
  gameSystemKey: string
  version: number
  onReload: () => void
  onRestored: (draft: GameSystem, version: number) => void
}) {
  const release = useGameSystemRelease(gameSystemKey, version)

  if (release.isPending) {
    return <p className="text-muted-foreground">Loading version {version}…</p>
  }
  if (release.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {release.error.message}
      </p>
    )
  }
  const { name, schemaVersion, sectionCounts } = release.data
  return (
    <section aria-label={`Version ${String(version)}`} className="space-y-4">
      <div className="space-y-1">
        <h2 className="font-heading text-lg font-semibold">
          Version {version}: {name}
        </h2>
        <p className="text-sm text-muted-foreground">Read-only. Schema version {schemaVersion}.</p>
      </div>
      <SectionCounts counts={sectionCounts} />
      <RestoreDialog
        gameSystemKey={gameSystemKey}
        version={version}
        onReload={onReload}
        onRestored={onRestored}
      />
    </section>
  )
}

/** Confirms before the draft's content is replaced by a release's. */
function RestoreDialog({
  gameSystemKey,
  version,
  onReload,
  onRestored,
}: {
  gameSystemKey: string
  version: number
  onReload: () => void
  onRestored: (draft: GameSystem, version: number) => void
}) {
  const [open, setOpen] = useState(false)
  const restore = useRestoreGameSystem(gameSystemKey)
  const concurrent = restore.error instanceof StudioError && restore.error.status === 409

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        restore.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline">Restore into the draft</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Restore version {version}?</DialogTitle>
          <DialogDescription>
            The content of the draft is replaced by version {version}. The key and owner stay. Edits
            to the metadata that you have not saved are lost, and so is any draft content not
            published yet.
          </DialogDescription>
        </DialogHeader>
        {restore.error && (
          <div role="alert" className="space-y-2 text-sm text-destructive">
            <p>{restore.error.message}</p>
            {concurrent && (
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setOpen(false)
                  onReload()
                }}
              >
                Reload the latest version
              </Button>
            )}
          </div>
        )}
        <Button
          disabled={restore.isPending}
          onClick={() => {
            restore.mutate(version, {
              onSuccess: (draft) => {
                setOpen(false)
                onRestored(draft, version)
              },
            })
          }}
        >
          Restore version {version}
        </Button>
      </DialogContent>
    </Dialog>
  )
}
