import { useState, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'

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
import { SegmentedControl, SegmentedControlItem } from '@/shared/ui/segmented-control'

import { MetadataForm } from './MetadataForm'
import {
  StudioError,
  useDiscardGameSystem,
  useGameSystemValidation,
  useStudioGameSystem,
  type GameSystem,
} from './useGameSystems'

type Tab = 'overview' | 'validation' | 'sections'

/** How the release contract's section names read; an unknown name shows as it is. */
const sectionLabels: Record<string, string> = {
  oracleTables: 'Oracle tables',
  likelihoodOracles: 'Likelihood oracles',
  trackers: 'Trackers',
  factSlots: 'Fact slots',
  tags: 'Tags',
  sceneTypes: 'Scene Types',
  flows: 'Flows',
  sheetFields: 'Sheet fields',
  checks: 'Checks',
  // Schema version 1.
  oracles: 'Oracles',
  flowSteps: 'Flow steps',
}

/**
 * `/studio/game-systems/$key`: one of my GameSystem drafts. An unknown key, or someone else's,
 * is not found.
 */
export function GameSystemPage({ gameSystemKey }: { gameSystemKey: string }) {
  const gameSystem = useStudioGameSystem(gameSystemKey)

  return (
    <div className="space-y-6">
      <Link
        to="/studio"
        className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
      >
        <span aria-hidden="true">← </span>Back to Studio
      </Link>
      <GameSystemState gameSystem={gameSystem} />
    </div>
  )
}

/** One state at a time: a failed refetch shows the failure instead of data it may not match. */
function GameSystemState({ gameSystem }: { gameSystem: ReturnType<typeof useStudioGameSystem> }) {
  switch (gameSystem.status) {
    case 'pending':
      return <p className="text-muted-foreground">Loading the GameSystem…</p>
    case 'error':
      return gameSystem.error instanceof StudioError && gameSystem.error.status === 404 ? (
        <section className="space-y-3">
          <h1 className="font-heading text-page-title">GameSystem not found</h1>
          <p className="text-muted-foreground">
            This GameSystem does not exist, or it is not one of yours.
          </p>
        </section>
      ) : (
        <p role="alert" className="text-sm text-destructive">
          {gameSystem.error.message}
        </p>
      )
    case 'success':
      return (
        <GameSystemView
          key={gameSystem.data.key}
          gameSystem={gameSystem.data}
          onReload={async () => (await gameSystem.refetch()).data}
        />
      )
  }
}

function GameSystemView({
  gameSystem,
  onReload,
}: {
  gameSystem: GameSystem
  onReload: () => Promise<GameSystem | undefined>
}) {
  const [tab, setTab] = useState<Tab>('overview')
  // The form owns what is typed and only starts from this GameSystem: a save does not reset it,
  // while a reload the person asked for remounts it on the latest version.
  const [formStart, setFormStart] = useState({ gameSystem, reloads: 0 })

  return (
    <>
      <header className="space-y-2">
        <h1 className="font-heading text-page-title break-words">{gameSystem.name}</h1>
        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted-foreground">
          <span className="font-mono break-all">{gameSystem.key}</span>
          <span>Schema version {gameSystem.schemaVersion}</span>
          <span>
            {gameSystem.latestReleaseVersion === null
              ? 'Never published'
              : `Latest release v${String(gameSystem.latestReleaseVersion)}`}
          </span>
          {gameSystem.hasUnpublishedChanges && <Badge variant="warning">Unpublished changes</Badge>}
        </p>
      </header>
      <SegmentedControl
        aria-label="GameSystem page"
        value={tab}
        onValueChange={(value) => {
          setTab(value as Tab)
        }}
      >
        <SegmentedControlItem value="overview">Overview</SegmentedControlItem>
        <SegmentedControlItem value="validation">Validation</SegmentedControlItem>
        <SegmentedControlItem value="sections">Sections</SegmentedControlItem>
      </SegmentedControl>
      {tab === 'overview' && (
        <div className="space-y-8">
          <MetadataForm
            key={formStart.reloads}
            gameSystem={formStart.gameSystem}
            onReload={() => {
              void onReload().then((latest) => {
                if (latest) {
                  setFormStart((start) => ({ gameSystem: latest, reloads: start.reloads + 1 }))
                }
              })
            }}
          />
          {gameSystem.latestReleaseVersion === null && <Discard gameSystemKey={gameSystem.key} />}
        </div>
      )}
      {tab === 'validation' && <Validation gameSystemKey={gameSystem.key} />}
      {tab === 'sections' && <Sections counts={gameSystem.sectionCounts} />}
    </>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section aria-label={title} className="space-y-3">
      {children}
    </section>
  )
}

function Validation({ gameSystemKey }: { gameSystemKey: string }) {
  const validation = useGameSystemValidation(gameSystemKey)
  if (validation.isPending) {
    return <p className="text-muted-foreground">Validating the draft…</p>
  }
  if (validation.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {validation.error.message}
      </p>
    )
  }
  const { valid, error, warnings } = validation.data
  return (
    <Panel title="Validation">
      {valid ? (
        <p>The draft is valid: it could be published as a release.</p>
      ) : (
        <p role="alert" className="text-destructive">
          The draft is not valid yet. The first error: {error?.message ?? 'unknown'}
        </p>
      )}
      {warnings.length > 0 && (
        <>
          <h2 className="font-heading font-semibold">Warnings</h2>
          <ul className="list-disc space-y-1 pl-5 text-sm">
            {warnings.map((warning) => (
              <li key={warning}>{warning}</li>
            ))}
          </ul>
        </>
      )}
    </Panel>
  )
}

function Sections({ counts }: { counts: Record<string, number> }) {
  return (
    <Panel title="Sections">
      <ul className="max-w-md divide-y rounded-xl ring-1 ring-foreground/10">
        {Object.entries(counts).map(([name, count]) => (
          <li key={name} className="flex items-baseline gap-3 px-4 py-3">
            <span className="font-medium">{sectionLabels[name] ?? name}</span>
            <span className="text-muted-foreground">{count}</span>
            <span className="ml-auto text-sm text-muted-foreground">Editor coming</span>
          </li>
        ))}
      </ul>
    </Panel>
  )
}

/** Only a never-published draft can be discarded; the server refuses one with releases (409). */
function Discard({ gameSystemKey }: { gameSystemKey: string }) {
  const [open, setOpen] = useState(false)
  const discard = useDiscardGameSystem(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        discard.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button variant="destructive">Discard draft</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Discard this draft?</DialogTitle>
          <DialogDescription>
            It was never published, so it is deleted for good, with its key.
          </DialogDescription>
        </DialogHeader>
        {discard.error && (
          <p role="alert" className="text-sm text-destructive">
            {discard.error.message}
          </p>
        )}
        <Button
          variant="destructive"
          disabled={discard.isPending}
          onClick={() => {
            discard.mutate()
          }}
        >
          Discard the draft
        </Button>
      </DialogContent>
    </Dialog>
  )
}
