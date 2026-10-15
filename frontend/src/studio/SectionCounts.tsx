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

/** The items each section holds; the draft's list also says its editor is still to come. */
export function SectionCounts({
  counts,
  editorComing = false,
}: {
  counts: Record<string, number>
  editorComing?: boolean
}) {
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
