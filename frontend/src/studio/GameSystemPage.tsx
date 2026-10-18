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

import { LibraryChangesBadge } from './LibraryChangesBadge'
import { MetadataForm } from './MetadataForm'
import { NarrativeTab } from './narrative/NarrativeTab'
import { FlowsTab } from './narrative/flows/FlowsTab'
import { SceneTypesTab } from './narrative/sceneTypes/SceneTypesTab'
import { ChecksTab } from './checks/ChecksTab'
import { OraclesTab } from './oracles/OraclesTab'
import { PublishDialog } from './PublishDialog'
import { ReleasesTab } from './ReleasesTab'
import { SectionCounts } from './SectionCounts'
import { SheetTab } from './sheet/SheetTab'
import { useSampleValues } from './sheet/useSheetSamples'
import {
  StudioError,
  useDiscardGameSystem,
  useGameSystemValidation,
  useStudioGameSystem,
  type GameSystem,
} from './useGameSystems'

type Tab =
  | 'overview'
  | 'oracles'
  | 'narrative'
  | 'sceneTypes'
  | 'flows'
  | 'sheet'
  | 'checks'
  | 'validation'
  | 'releases'
  | 'sections'

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

/**
 * Without data yet, one state at a time. Once there is data, a failed refetch keeps the page
 * (and what was typed in the form) and only adds a non-blocking alert with a retry.
 */
function GameSystemState({ gameSystem }: { gameSystem: ReturnType<typeof useStudioGameSystem> }) {
  if (gameSystem.data === undefined) {
    if (gameSystem.isPending) {
      return <p className="text-muted-foreground">Loading the GameSystem…</p>
    }
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
  }

  return (
    <>
      {gameSystem.isRefetchError && (
        <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-destructive">
          <p>Couldn&apos;t refresh this GameSystem. {gameSystem.error.message}</p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              void gameSystem.refetch()
            }}
          >
            Try again
          </Button>
        </div>
      )}
      <GameSystemView
        key={gameSystem.data.key}
        gameSystem={gameSystem.data}
        onReload={async () => {
          const result = await gameSystem.refetch()
          // A failed refetch keeps the stale data: do not remount the form on it.
          return result.isError ? undefined : result.data
        }}
      />
    </>
  )
}

function GameSystemView({
  gameSystem,
  onReload,
}: {
  gameSystem: GameSystem
  onReload: () => Promise<GameSystem | undefined>
}) {
  const [tab, setTab] = useState<Tab>('overview')
  // A library pack is edited here like a draft, without anything about releases or publishing.
  const pack = gameSystem.kind === 'library-pack'
  const [notice, setNotice] = useState<string | null>(null)
  // The sample values of the Sheet tab's preview and the Checks tab's sandbox: one set for both.
  const sampleValues = useSampleValues()
  // The form owns what is typed and only starts from this GameSystem: a save does not reset it,
  // while a reload the person asked for remounts it on the latest version.
  const [formStart, setFormStart] = useState({ gameSystem, reloads: 0 })

  function reload() {
    void onReload().then((latest) => {
      if (latest) {
        setFormStart((start) => ({ gameSystem: latest, reloads: start.reloads + 1 }))
      }
    })
  }

  return (
    <>
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-2">
          <h1 className="font-heading text-page-title break-words">{gameSystem.name}</h1>
          <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted-foreground">
            <span className="font-mono break-all">{gameSystem.key}</span>
            <span>Schema version {gameSystem.schemaVersion}</span>
            {pack ? (
              <Badge variant="secondary">Library pack</Badge>
            ) : (
              <>
                <span>
                  {gameSystem.latestReleaseVersion === null
                    ? 'Never published'
                    : `Latest release v${String(gameSystem.latestReleaseVersion)}`}
                </span>
                {gameSystem.hasUnpublishedChanges && (
                  <Badge variant="warning">Unpublished changes</Badge>
                )}
                <LibraryChangesBadge count={gameSystem.libraryChanges} />
              </>
            )}
          </p>
        </div>
        {!pack && (
          <PublishDialog
            gameSystem={gameSystem}
            onPublished={(version) => {
              setNotice(`Published version ${String(version)}.`)
            }}
          />
        )}
      </header>
      {notice && (
        <p role="status" className="text-sm">
          {notice}
        </p>
      )}
      <SegmentedControl
        aria-label="GameSystem page"
        value={tab}
        onValueChange={(value) => {
          setTab(value as Tab)
          setNotice(null)
        }}
      >
        <SegmentedControlItem value="overview">Overview</SegmentedControlItem>
        <SegmentedControlItem value="oracles">Oracles</SegmentedControlItem>
        <SegmentedControlItem value="narrative">Trackers &amp; tags</SegmentedControlItem>
        <SegmentedControlItem value="sceneTypes">Scene Types</SegmentedControlItem>
        {!pack && <SegmentedControlItem value="flows">Flows</SegmentedControlItem>}
        {!pack && <SegmentedControlItem value="sheet">Sheet</SegmentedControlItem>}
        {!pack && <SegmentedControlItem value="checks">Checks</SegmentedControlItem>}
        <SegmentedControlItem value="validation">Validation</SegmentedControlItem>
        {!pack && <SegmentedControlItem value="releases">Releases</SegmentedControlItem>}
        {!pack && <SegmentedControlItem value="sections">Sections</SegmentedControlItem>}
      </SegmentedControl>
      {/* Stays mounted while another tab shows, so typed and saved values survive tab switches. */}
      <div hidden={tab !== 'overview'} className="space-y-8">
        <MetadataForm key={formStart.reloads} gameSystem={formStart.gameSystem} onReload={reload} />
        {(pack || gameSystem.latestReleaseVersion === null) && (
          <Discard gameSystemKey={gameSystem.key} pack={pack} />
        )}
      </div>
      {tab === 'oracles' && <OraclesTab gameSystemKey={gameSystem.key} />}
      {tab === 'narrative' && <NarrativeTab gameSystemKey={gameSystem.key} />}
      {tab === 'sceneTypes' && <SceneTypesTab gameSystemKey={gameSystem.key} />}
      {tab === 'flows' && <FlowsTab gameSystemKey={gameSystem.key} />}
      {tab === 'sheet' && <SheetTab gameSystemKey={gameSystem.key} sampleValues={sampleValues} />}
      {tab === 'checks' && <ChecksTab gameSystemKey={gameSystem.key} sampleValues={sampleValues} />}
      {tab === 'validation' && <Validation gameSystemKey={gameSystem.key} pack={pack} />}
      {tab === 'releases' && (
        <ReleasesTab
          gameSystemKey={gameSystem.key}
          onReload={reload}
          onRestored={(restored, version) => {
            // The restored draft replaces what the form started from, and the form remounts on it.
            setFormStart((start) => ({ gameSystem: restored, reloads: start.reloads + 1 }))
            setNotice(`Restored version ${String(version)} into the draft.`)
            setTab('overview')
          }}
        />
      )}
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

function Validation({ gameSystemKey, pack }: { gameSystemKey: string; pack: boolean }) {
  const validation = useGameSystemValidation(gameSystemKey)
  if (validation.isPending) {
    return <p className="text-muted-foreground">Validating the {pack ? 'pack' : 'draft'}…</p>
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
        <p>
          {pack
            ? "The pack's items are valid."
            : 'The draft is valid: it could be published as a release.'}
        </p>
      ) : (
        <p role="alert" className="text-destructive">
          The {pack ? 'pack' : 'draft'} is not valid yet. The first error:{' '}
          {error?.message ?? 'unknown'}
        </p>
      )}
      {warnings.length > 0 && (
        <>
          <h2 className="font-heading font-semibold">Warnings</h2>
          <ul className="list-disc space-y-1 pl-5 text-sm">
            {warnings.map((warning, index) => (
              // Warnings can repeat, so the position is part of the key.
              <li key={`${String(index)}:${warning}`}>{warning}</li>
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
      <SectionCounts counts={counts} editorComing />
    </Panel>
  )
}

/**
 * Only a never-published draft can be discarded; the server refuses one with releases (409). A
 * pack is never published, so it can always be discarded: the drafts that imported from it keep
 * their copies.
 */
function Discard({ gameSystemKey, pack }: { gameSystemKey: string; pack: boolean }) {
  const noun = pack ? 'pack' : 'draft'
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
        <Button variant="destructive">Discard {noun}</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Discard this {noun}?</DialogTitle>
          <DialogDescription>
            {pack
              ? 'It is deleted for good, with its key. GameSystems that imported from it keep their copies.'
              : 'It was never published, so it is deleted for good, with its key.'}
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
          Discard the {noun}
        </Button>
      </DialogContent>
    </Dialog>
  )
}
