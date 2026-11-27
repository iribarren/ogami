import { FlowDiagramView } from '@/shared/flowDiagram'

import { manualFlowDiagram } from './flowDiagrams'

/**
 * The diagram a `::flow-diagram` directive names, from the manual presets. An unknown flow or
 * phase throws, so a page with a broken directive fails loudly instead of showing a gap.
 */
export function ManualFlowDiagram({ flow, phase }: { flow: string; phase?: string }) {
  return <FlowDiagramView diagram={manualFlowDiagram(flow, phase)} className="my-6" />
}
