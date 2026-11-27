import { MAX_SELECTION_SCENE_TYPES, type PhaseDraft, type SelectionDraft } from './flowDraft'
import type { FlowNode } from './flowNode'
import { moveItem } from '../steps/draftParts'

/** A change the Map makes to the draft's phases, and what it announces. */
export interface MapEdit {
  phases: PhaseDraft[]
  /** Set when a phase changed place: the save answers and the selection follow it. */
  move?: { from: number; to: number }
  /** Set when the edit leaves the Map: the node whose panel the editor then shows. */
  select?: FlowNode
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

/** The selection of a phase that lists Scene Types: `sequence` and `player`. An `oracle` draws them from a table. */
type ListedSelection = Extract<SelectionDraft, { rule: 'sequence' | 'player' }>

/** How the Map names a Scene Type in its messages: the name the narrative gives it, else its key. */
export type SceneNames = (key: string) => string

const sceneLabel = (names: SceneNames, key: string) => `"${names(key)}"`

/** The phases with the selection of the one at `index` replaced. */
const withSceneTypes = (phases: PhaseDraft[], index: number, sceneTypes: string[]): PhaseDraft[] =>
  phases.map((phase, at) =>
    at === index && phase.selection.rule !== 'oracle'
      ? { ...phase, selection: { ...phase.selection, sceneTypes } }
      : phase,
  )

/** The phase at `index` and its listed selection, or the refusal that says why it has none. */
function listedPhase(
  phases: PhaseDraft[],
  index: number,
): { phase: PhaseDraft; selection: ListedSelection } | MapRefusal {
  const phase = phases[index]
  if (phase === undefined) {
    return { refused: 'That phase is not in the flow.' }
  }
  if (phase.selection.rule === 'oracle') {
    return {
      refused: `Phase ${phaseLabel(phase)} draws its Scene Types from table "${phase.selection.table}": edit the table instead.`,
    }
  }
  return { phase, selection: phase.selection }
}

/**
 * The Scene Type added to the selection of the phase at `to`, as the outline's Add a Scene Type does.
 * `before` is the place in a `sequence` it goes in front of (null: at the end); a `player` list
 * appends. Refused for an `oracle` phase, a Scene Type the phase lists already (one node stands for
 * it on the Map) and a full list.
 */
export function addSceneType(
  phases: PhaseDraft[],
  to: number,
  sceneType: string,
  before: number | null,
  names: SceneNames,
): MapResult {
  const listed = listedPhase(phases, to)
  if (isRefusal(listed)) {
    return listed
  }
  const { phase, selection } = listed
  if (sceneType === '') {
    return { refused: 'Pick a Scene Type to add.' }
  }
  if (selection.sceneTypes.includes(sceneType)) {
    return { refused: `${sceneLabel(names, sceneType)} is already in phase ${phaseLabel(phase)}.` }
  }
  if (selection.sceneTypes.length >= MAX_SELECTION_SCENE_TYPES) {
    return {
      refused: `Phase ${phaseLabel(phase)} lists ${String(MAX_SELECTION_SCENE_TYPES)} Scene Types, the most it may.`,
    }
  }
  const at =
    selection.rule === 'sequence' && before !== null
      ? Math.min(Math.max(before, 0), selection.sceneTypes.length)
      : selection.sceneTypes.length
  return {
    phases: withSceneTypes(phases, to, selection.sceneTypes.toSpliced(at, 0, sceneType)),
    say: `Added ${sceneLabel(names, sceneType)} to phase ${phaseLabel(phase)}.`,
  }
}

/** The Scene Type taken out of the selection of the phase at `from`; the node stays drawn when a transition still reaches it. */
export function removeSceneType(
  phases: PhaseDraft[],
  from: number,
  sceneType: string,
  names: SceneNames,
): MapResult {
  const listed = listedPhase(phases, from)
  if (isRefusal(listed)) {
    return listed
  }
  const { phase, selection } = listed
  if (!selection.sceneTypes.includes(sceneType)) {
    return {
      refused: `${sceneLabel(names, sceneType)} is not in the selection of phase ${phaseLabel(phase)}.`,
    }
  }
  return {
    // One node stands for every copy of a Scene Type a legacy list repeats, so all of them go.
    phases: withSceneTypes(
      phases,
      from,
      selection.sceneTypes.filter((key) => key !== sceneType),
    ),
    say: `Removed ${sceneLabel(names, sceneType)} from phase ${phaseLabel(phase)}.`,
  }
}

/**
 * The Scene Type moved inside the `sequence` of the phase at `at`, in front of the place `before`
 * (an index of the list as it is now; null: to the end), as the outline's Up and Down do. A `player`
 * list has no order to change, and a move that leaves the place as it is says so.
 */
export function placeSceneType(
  phases: PhaseDraft[],
  at: number,
  sceneType: string,
  before: number | null,
  names: SceneNames,
): MapResult {
  const listed = listedPhase(phases, at)
  if (isRefusal(listed)) {
    return listed
  }
  const { phase, selection } = listed
  const list = selection.sceneTypes
  const from = list.indexOf(sceneType)
  if (from < 0) {
    return {
      refused: `${sceneLabel(names, sceneType)} is not in the selection of phase ${phaseLabel(phase)}.`,
    }
  }
  const rest = list.toSpliced(from, 1)
  const to = before === null ? rest.length : before > from ? before - 1 : before
  if (selection.rule === 'player' || to === from) {
    return {
      refused: `${sceneLabel(names, sceneType)} stays where it is in phase ${phaseLabel(phase)}.`,
    }
  }
  return {
    phases: withSceneTypes(phases, at, rest.toSpliced(to, 0, sceneType)),
    say: `Moved ${sceneLabel(names, sceneType)} to place ${String(to + 1)} of phase ${phaseLabel(phase)}.`,
  }
}

/** Where a Scene Type dragged on the Map comes from: a selection it is in, or the palette (`from` null). */
export interface SceneSource {
  sceneType: string
  from: number | null
}

/**
 * What letting go of a dragged Scene Type does on the phase at `to` (-1: on no phase). From the
 * palette it adds; from a selection it removes (dropped on nothing), repositions (the same phase) or
 * moves to another phase, which is all or nothing: a refused add leaves the first selection alone.
 */
export function sceneDrop(
  phases: PhaseDraft[],
  source: SceneSource,
  to: number,
  before: number | null,
  names: SceneNames,
): MapResult {
  const { sceneType, from } = source
  if (to < 0) {
    return from === null
      ? { refused: 'Dropped outside every phase: nothing changed.' }
      : removeSceneType(phases, from, sceneType, names)
  }
  if (from === null) {
    return addSceneType(phases, to, sceneType, before, names)
  }
  if (from === to) {
    return placeSceneType(phases, to, sceneType, before, names)
  }
  const added = addSceneType(phases, to, sceneType, before, names)
  if (isRefusal(added)) {
    return added
  }
  const removed = removeSceneType(added.phases, from, sceneType, names)
  if (isRefusal(removed)) {
    return removed
  }
  return {
    phases: removed.phases,
    say: `Moved ${sceneLabel(names, sceneType)} from phase ${phaseLabel(phases[from])} to phase ${phaseLabel(phases[to])}.`,
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
export const isRefusal = (result: object): result is MapRefusal => 'refused' in result
