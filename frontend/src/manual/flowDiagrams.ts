import { type FlowDiagram, flowDiagramFromReleaseContent, phaseDiagram } from '@/shared/flowDiagram'

// The same preset files PHP publishes (backend/presets/), so a diagram cannot drift from its flow.
// The node container mounts them, see compose.yaml. The card sheets of the print entry read them too.
const presets = import.meta.glob<unknown>('../../../backend/presets/{hard-country,dogwatch}.json', {
  eager: true,
  import: 'default',
})

/** The manual presets by file name (`hard-country`, `dogwatch`), as PHP publishes them. */
export const manualPresets: Record<string, unknown> = Object.fromEntries(
  Object.entries(presets).map(([path, content]) => [
    path.replace(/^.*\/(.+)\.json$/, '$1'),
    content,
  ]),
)

/** The flow keys a preset file declares. A file that is not release content has none. */
function flowKeys(content: unknown): string[] {
  const flows =
    typeof content === 'object' && content !== null && 'flows' in content ? content.flows : null
  if (!Array.isArray(flows)) {
    return []
  }
  return flows.flatMap((flow: unknown) =>
    typeof flow === 'object' && flow !== null && 'key' in flow && typeof flow.key === 'string'
      ? [flow.key]
      : [],
  )
}

/**
 * The diagram a `::flow-diagram` directive names: the flow `flow` of the manual preset that
 * declares it, or one phase of it. An unknown flow, a flow key both presets declare or an unknown
 * phase throws, naming the key: a chapter can never print a diagram of nothing.
 */
export function manualFlowDiagram(flow: string, phase?: string): FlowDiagram {
  const owners = Object.entries(manualPresets).filter(([, content]) =>
    flowKeys(content).includes(flow),
  )
  const owner = owners[0]
  if (owner === undefined) {
    throw new Error(`The manual has no flow "${flow}" in its presets.`)
  }
  if (owners.length > 1) {
    throw new Error(`The flow "${flow}" is in more than one manual preset.`)
  }
  const diagram = flowDiagramFromReleaseContent(owner[1], flow)
  return phase === undefined ? diagram : phaseDiagram(diagram, phase)
}
