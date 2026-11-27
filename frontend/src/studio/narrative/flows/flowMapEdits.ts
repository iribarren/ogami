import type { PhaseDraft } from './flowDraft'
import type { FlowNode } from './flowNode'
import { moveItem } from '../steps/draftParts'

/** A change the Map makes to the draft's phases, and what it announces. */
export interface MapEdit {
  phases: PhaseDraft[]
  /** Set when a phase changed place: the save answers and the selection follow it. */
  move?: { from: number; to: number }
  say: string
}

/** A gesture the draft cannot take, with the reason the person is told. The draft is untouched. */
export interface MapRefusal {
  refused: string
}

export type MapResult = MapEdit | MapRefusal

/** What the Map calls a phase in its messages. */
export function phaseLabel(phase: PhaseDraft | undefined): string {
  const name = phase?.name.trim() ?? ''
  const key = phase?.key.trim() ?? ''
  return `"${name === '' ? (key === '' ? 'Unnamed' : key) : name}"`
}

/** The phases with the one at `from` moved to the place `to`, the same move the outline's Move up and Move down make. */
export function movePhase(phases: PhaseDraft[], from: number, to: number): MapResult {
  const phase = phases[from]
  const over = phases[to]
  if (phase === undefined || over === undefined) {
    return { refused: 'That phase is not in the flow.' }
  }
  if (from === to) {
    return { refused: `Phase ${phaseLabel(phase)} stays where it is.` }
  }
  return {
    phases: moveItem(phases, from, to - from),
    move: { from, to },
    say: `Moved phase ${phaseLabel(phase)} ${to < from ? 'before' : 'after'} ${phaseLabel(over)}.`,
  }
}

/** The phase at `index` switched between running once and looping, as the panel's Mode select does. */
export function toggleMode(phases: PhaseDraft[], index: number): MapResult {
  const phase = phases[index]
  if (phase === undefined) {
    return { refused: 'That phase is not in the flow.' }
  }
  const mode = phase.mode === 'once' ? 'loop' : 'once'
  return {
    phases: phases.map((candidate, at) => (at === index ? { ...candidate, mode } : candidate)),
    say: `Phase ${phaseLabel(phase)} now ${mode === 'loop' ? 'loops' : 'runs once'}.`,
  }
}

/** Where the phase at `index` is once the phase at `move.from` has moved to `move.to`. */
export function followMove(index: number, move: { from: number; to: number }): number {
  if (index === move.from) {
    return move.to
  }
  if (move.from < move.to && index > move.from && index <= move.to) {
    return index - 1
  }
  if (move.to < move.from && index >= move.to && index < move.from) {
    return index + 1
  }
  return index
}

/** The node the editor has selected, once the phase at `move.from` moved to `move.to`. */
export function followNode(node: FlowNode, move: { from: number; to: number }): FlowNode {
  return node.kind === 'settings' ? node : { ...node, phase: followMove(node.phase, move) }
}

/** True for a refusal. */
export const isRefusal = (result: MapResult): result is MapRefusal => 'refused' in result
