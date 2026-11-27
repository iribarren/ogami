import { useMemo } from 'react'

import { DeckBack, DeckFront, SceneBack, SceneFront } from '@/shared/print/CardFaces'
import { flowDiagramFromReleaseContent } from '@/shared/flowDiagram'
import { buildCards, CARD_CAPACITY, type Cards } from '@/shared/print/cards'
import { diagramPages, type DiagramPage } from '@/shared/print/diagramPages'
import { FlowDiagramPages, FlowSheetText, TablesSheet } from '@/shared/print/FlowSheet'
import { buildCampaignLog, type CampaignLog } from '@/shared/print/campaignLog'
import { buildChecksReference, type ChecksReference } from '@/shared/print/checksReference'
import { buildFlowSheet, type FlowSheet } from '@/shared/print/flowSheet'
import { pageLayout } from '@/shared/print/layout'
import { DuplexSheets, PrintCards } from '@/shared/print/PokerSheets'
import {
  CampaignLogSheet,
  ChecksReferenceSheet,
  TrackersSheet,
} from '@/shared/print/ReferenceSheets'
import { buildTablesSheet, type OracleTableSheet } from '@/shared/print/tablesSheet'
import { buildTrackerSheet, type TrackerSheetEntry } from '@/shared/print/trackerSheet'

import { resolvePrintOptions, type PrintOptions, type PrintSearch } from './printOptions'
import { PrintOptionsForm } from './PrintOptionsForm'
import { useReleaseContent, type PinnedImages } from './useReleaseContent'

/** A release version in the address: a positive whole number. */
const VERSION = /^[1-9]\d{0,8}$/

function Problems({
  problems,
  flows = [],
}: {
  problems: string[]
  flows?: { key: string; name: string }[]
}) {
  return (
    <div role="alert" className="space-y-2 rounded-md border border-destructive p-4 text-sm">
      {problems.map((problem) => (
        <p key={problem}>{problem}</p>
      ))}
      {flows.length > 0 && (
        <>
          <p>The flows of this release:</p>
          <ul className="list-disc pl-5">
            {flows.map((flow) => (
              <li key={flow.key}>
                {flow.name} (<code>{flow.key}</code>)
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  )
}

/** Everything one flow prints, in page order: the cards, the flow sheet and its diagram, the tables, the references and the log. */
interface Built {
  cards: Cards
  flowSheet: FlowSheet
  diagram: DiagramPage[]
  tables: OracleTableSheet[]
  checks: ChecksReference
  trackers: TrackerSheetEntry[]
  log: CampaignLog
}

function Sheets({
  built,
  options,
  images,
}: {
  built: Built
  options: PrintOptions
  images: PinnedImages
}) {
  const { cards, flowSheet, diagram, tables, checks, trackers, log } = built
  const layout = pageLayout(options.paper, options.bleed)
  const text = { bleed: options.bleed, image: images.cardFront }
  const back = { bleed: options.bleed, image: images.cardBack }
  return (
    <>
      <DuplexSheets
        layout={layout}
        items={cards.sceneCards}
        front={(card) => <SceneFront card={card} face={text} />}
        back={(card) => <SceneBack card={card} face={text} />}
      />
      {cards.decks.map((deck) => (
        <DuplexSheets
          key={deck.key}
          layout={layout}
          items={deck.cards.map((card, at) => ({ card, index: at + 1 }))}
          front={({ card, index }) => (
            <DeckFront deck={deck} card={card} index={index} face={text} />
          )}
          back={({ index }) => <DeckBack deck={deck} index={index} face={back} />}
        />
      ))}
      <FlowSheetText sheet={flowSheet} paper={options.paper} />
      <FlowDiagramPages pages={diagram} paper={options.paper} />
      <TablesSheet tables={tables} paper={options.paper} />
      <ChecksReferenceSheet reference={checks} paper={options.paper} />
      <TrackersSheet trackers={trackers} paper={options.paper} />
      <CampaignLogSheet log={log} paper={options.paper} />
    </>
  )
}

/** The Scene Types that continue on "(cont.)" cards, for the screen only. */
function OverflowNote({ continued }: { continued: Cards['continued'] }) {
  if (continued.length === 0) {
    return null
  }
  return (
    <p className="print-note mb-4 text-sm text-muted-foreground">
      These Scene Types continue on &quot;(cont.)&quot; cards:{' '}
      {continued.map((card) => `${card.name} (${String(card.cards)} cards)`).join(', ')}.
    </p>
  )
}

/**
 * The cards, flow sheet, diagram, oracle tables, checks reference, trackers and campaign log of one flow of a published release, ready for the browser's print dialog. Everything
 * that can go wrong (an unknown release, content the reader refuses, a bad option, a flow a
 * builder cannot build) is a message on the page, never a blank sheet or a crash.
 */
export function ReleasePrintPage({
  gameSystemKey,
  version,
  search,
  onSearchChange,
}: {
  gameSystemKey: string
  version: string
  search: PrintSearch
  /** Replaces the options of the address: the form's way of choosing. */
  onSearchChange: (search: PrintSearch) => void
}) {
  const validVersion = VERSION.test(version)
  const content = useReleaseContent(gameSystemKey, validVersion ? Number(version) : 0, validVersion)
  const outcome = useMemo(() => {
    if (content.data === undefined) {
      return undefined
    }
    const resolved = resolvePrintOptions(search, content.data.release)
    if (!resolved.ok) {
      return resolved
    }
    const { options } = resolved
    try {
      const cards = buildCards(content.data.release, {
        flow: options.flow,
        deckTables: options.decks,
        capacity: options.bleed ? CARD_CAPACITY.bleed : CARD_CAPACITY.poker,
      })
      const flowSheet = buildFlowSheet(content.data.release, options.flow)
      const diagram = diagramPages(
        flowDiagramFromReleaseContent(content.data.content, options.flow),
        options.paper,
      )
      const tables = buildTablesSheet(content.data.release, options.flow)
      const checks = buildChecksReference(content.data.release, options.flow)
      const trackers = buildTrackerSheet(content.data.release, options.flow)
      const log = buildCampaignLog(content.data.release)
      return {
        ok: true as const,
        options,
        built: { cards, flowSheet, diagram, tables, checks, trackers, log },
      }
    } catch (error) {
      return {
        ok: false as const,
        problems: [error instanceof Error ? error.message : 'The flow could not be built.'],
        flows: [],
      }
    }
  }, [content.data, search])

  if (!validVersion) {
    return <Problems problems={[`"${version}" is not a release version: use a whole number.`]} />
  }
  if (content.isPending) {
    return <p role="status">Loading the release…</p>
  }
  if (content.isError) {
    return <Problems problems={[content.error.message]} />
  }
  if (outcome === undefined) {
    return null
  }
  const { release } = content.data
  const form = (
    <PrintOptionsForm
      release={release}
      search={search}
      canPrint={outcome.ok}
      onChange={onSearchChange}
      onPrint={() => {
        window.print()
      }}
    />
  )
  if (!outcome.ok) {
    return (
      <PrintCards>
        <Problems problems={outcome.problems} flows={outcome.flows} />
        {form}
      </PrintCards>
    )
  }
  const { options, built } = outcome
  const note = `${release.systemName}, release ${version}: print on ${options.paper.toUpperCase()}${options.bleed ? ' with bleed' : ''} from the browser, with margins set to none and background graphics on.`
  return (
    <PrintCards>
      {form}
      <p className="print-note mb-4 text-sm text-muted-foreground">{note}</p>
      <OverflowNote continued={built.cards.continued} />
      <Sheets built={built} options={options} images={content.data.images} />
    </PrintCards>
  )
}
