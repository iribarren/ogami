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
 * The items each section holds; the draft's list also says its editor is still to come. The
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
          {editorComing && (
            <span className="ml-auto text-sm text-muted-foreground">Editor coming</span>
          )}
        </li>
      ))}
    </ul>
  )
}
