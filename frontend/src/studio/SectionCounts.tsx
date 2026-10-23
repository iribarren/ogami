import { Link, useParams } from '@tanstack/react-router'
import { ArrowRight } from 'lucide-react'

import type { GameSystemTab } from './gameSystemTabs'

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

/** The tab of the GameSystem page that edits each section; the schema version 1 names have none. */
const sectionTabs: Record<string, GameSystemTab> = {
  oracleTables: 'oracles',
  likelihoodOracles: 'oracles',
  trackers: 'narrative',
  factSlots: 'narrative',
  tags: 'narrative',
  sceneTypes: 'sceneTypes',
  flows: 'flows',
  sheetFields: 'sheet',
  checks: 'checks',
}

/**
 * The items each section holds; the draft's list also links each section to its editor tab. The
 * compact form is one line for a card, and leaves out the sections without items.
 */
export function SectionCounts({
  counts,
  editorComing = false,
  compact = false,
}: {
  counts: Record<string, number>
  editorComing?: boolean
  compact?: boolean
}) {
  const { key } = useParams({ strict: false })
  if (compact) {
    const filled = Object.entries(counts).filter(([, count]) => count > 0)
    return filled.length === 0 ? (
      <p className="text-muted-foreground">No items yet</p>
    ) : (
      <ul className="flex flex-wrap gap-x-4 gap-y-1">
        {filled.map(([name, count]) => (
          <li key={name}>
            <span className="text-muted-foreground">{sectionLabels[name] ?? name}</span>{' '}
            <span className="font-medium">{count}</span>
          </li>
        ))}
      </ul>
    )
  }
  return (
    <ul className="max-w-md divide-y rounded-xl ring-1 ring-foreground/10">
      {Object.entries(counts).map(([name, count]) => (
        <li key={name} className="flex items-baseline gap-3 px-4 py-3">
          <span className="font-medium">{sectionLabels[name] ?? name}</span>
          <span className="text-muted-foreground">{count}</span>
          {editorComing && key !== undefined && name in sectionTabs && (
            <Link
              to="/studio/game-systems/$key"
              params={{ key }}
              search={{ tab: sectionTabs[name] }}
              aria-label={`Open ${sectionLabels[name] ?? name}`}
              className="ml-auto inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
              Open
              <ArrowRight aria-hidden="true" className="size-4" />
            </Link>
          )}
        </li>
      ))}
    </ul>
  )
}
