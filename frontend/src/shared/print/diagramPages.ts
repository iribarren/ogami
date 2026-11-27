import { layoutFlowDiagram, phaseDiagram, type FlowDiagram } from '@/shared/flowDiagram'

import { printableArea, type Paper } from './layout'

/** The drawing's units are CSS pixels: 96 to the inch. */
const MM_PER_UNIT = 25.4 / 96

/** One diagram on one page, and the size it is drawn at, in millimetres. */
export interface DiagramPage {
  diagram: FlowDiagram
  /** The size of the drawing on paper. */
  width: number
  height: number
  /** The phase this page cuts out; null for the whole flow. */
  phase: string | null
}

/**
 * The size of a drawing on paper: the printable width, or smaller when `fit` and it would be
 * taller than the page. A `maxScale` caps the scale, so a drawing narrower than the one it is cut
 * from is not blown up to the page width.
 */
function sizeOn(
  diagram: FlowDiagram,
  paper: Paper,
  fit: boolean,
  maxScale = Infinity,
): { width: number; height: number } {
  const layout = layoutFlowDiagram(diagram)
  const area = printableArea(paper)
  const natural = { width: layout.width * MM_PER_UNIT, height: layout.height * MM_PER_UNIT }
  const scale = Math.min(
    area.width / natural.width,
    fit ? area.height / natural.height : Infinity,
    maxScale,
  )
  return { width: natural.width * scale, height: natural.height * scale }
}

/**
 * The pages a flow diagram prints on. The whole diagram goes on one page, scaled to the printable
 * width of the paper, when it is no taller than the printable height then; otherwise each phase
 * has a page of its own (`phaseDiagram`), at the scale the whole diagram has at the printable
 * width at most, so its text is as large as on the one page, and smaller when it must fit the page
 * height. A flow without phases has no diagram to print.
 */
export function diagramPages(diagram: FlowDiagram, paper: Paper): DiagramPage[] {
  if (diagram.phases.length === 0) {
    return []
  }
  const whole = sizeOn(diagram, paper, false)
  const wholeScale = whole.width / (layoutFlowDiagram(diagram).width * MM_PER_UNIT)
  if (whole.height <= printableArea(paper).height) {
    return [{ diagram, ...whole, phase: null }]
  }
  return diagram.phases.map((phase) => {
    const cut = phaseDiagram(diagram, phase.key)
    return { diagram: cut, ...sizeOn(cut, paper, true, wholeScale), phase: phase.key }
  })
}
