import { isObject, listOf, stringsOf, textOf, unknownFields } from '../steps/draftParts'
import type { StoredItem } from '../useGameSystemNarrative'

// Typed form state of a flow: its own fields and its phases. Like the other narrative drafts, what
// the editor does not know stays in `extra` and is sent back untouched, a missing optional field
// stays missing, and a stored flow it cannot read at all is `null`: the tab lists it read-only.
// The publisher owns every rule (`ReleaseVersion2::flows`), so nothing is checked here beyond the
// shape the draft needs in order to hold the stored values without guessing.

export const flowViews = ['focus', 'journal'] as const
export type FlowView = (typeof flowViews)[number]

export const phaseModes = ['once', 'loop'] as const
export type PhaseMode = (typeof phaseModes)[number]

/** The hooks of a phase, in schema order (`ReleaseVersion2::PHASE_HOOKS`). */
export const phaseHooks = [
  'sessionOpening',
  'sessionClosing',
  'phaseOpening',
  'phaseClosing',
  'sceneOpening',
  'sceneClosing',
  'worldTurn',
] as const
export type PhaseHook = (typeof phaseHooks)[number]

/** How a phase picks its next scene: from a list of Scene Types, or from an oracle table. */
export type SelectionDraft =
  | { rule: 'sequence' | 'player'; sceneTypes: string[]; extra: StoredItem }
  | { rule: 'oracle'; table: string; extra: StoredItem }

export interface PhaseDraft {
  key: string
  name: string
  act: string
  description: string
  mode: PhaseMode
  selection: SelectionDraft
  /**
   * The step lists as stored, raw: this slice only lists flows. A hook the phase does not have
   * stays missing; the flow editor reads them with `stepDraft`.
   */
  hooks: Partial<Record<PhaseHook, unknown[]>>
  /** Fields this editor does not know. */
  extra: StoredItem
}

export interface FlowDraft {
  key: string
  name: string
  description: string
  introduction: string
  default: boolean
  defaultView: FlowView
  /** The oracle shortcuts and the trackers the flow shows, as stored. */
  oracles: string[]
  trackers: string[]
  phases: PhaseDraft[]
  /** Fields this editor does not know. */
  extra: StoredItem
}

export interface FlowResult {
  item: StoredItem
}

const flowKnown = [
  'key',
  'name',
  'description',
  'introduction',
  'default',
  'defaultView',
  'oracles',
  'trackers',
  'phases',
]
const phaseKnown = ['key', 'name', 'act', 'description', 'mode', 'selection', ...phaseHooks]

const oneOf = <T extends string>(options: readonly T[], value: unknown): T | null =>
  options.find((option) => option === value) ?? null

function selectionToDraft(value: unknown): SelectionDraft | null {
  if (!isObject(value)) {
    return null
  }
  if (value.rule === 'oracle') {
    const table = value.table
    return typeof table === 'string'
      ? { rule: 'oracle', table, extra: unknownFields(value, ['rule', 'table']) }
      : null
  }
  const sceneTypes = stringsOf(value, 'sceneTypes')
  return (value.rule === 'sequence' || value.rule === 'player') && sceneTypes !== null
    ? {
        rule: value.rule,
        sceneTypes,
        extra: unknownFields(value, ['rule', 'sceneTypes']),
      }
    : null
}

function phaseToDraft(value: unknown): PhaseDraft | null {
  if (!isObject(value)) {
    return null
  }
  const key = textOf(value, 'key')
  const name = textOf(value, 'name')
  const act = textOf(value, 'act')
  const description = textOf(value, 'description')
  const mode = oneOf(phaseModes, value.mode)
  const selection = selectionToDraft(value.selection)
  if (
    key === null ||
    name === null ||
    act === null ||
    description === null ||
    mode === null ||
    selection === null
  ) {
    return null
  }
  const hooks: PhaseDraft['hooks'] = {}
  for (const hook of phaseHooks) {
    const steps = listOf(value, hook)
    if (steps === null) {
      return null
    }
    if (value[hook] !== undefined) {
      hooks[hook] = steps
    }
  }
  return {
    key,
    name,
    act,
    description,
    mode,
    selection,
    hooks,
    extra: unknownFields(value, phaseKnown),
  }
}

/** The flow as form state; null when a field has a shape this editor does not read. */
export function flowToDraft(definition: StoredItem): FlowDraft | null {
  const key = textOf(definition, 'key')
  const name = textOf(definition, 'name')
  const description = textOf(definition, 'description')
  const introduction = textOf(definition, 'introduction')
  const defaultView = oneOf(flowViews, definition.defaultView)
  const oracles = stringsOf(definition, 'oracles')
  const trackers = stringsOf(definition, 'trackers')
  const phases = Array.isArray(definition.phases)
    ? (definition.phases as unknown[]).map(phaseToDraft)
    : null
  const isDefault = definition.default
  if (
    key === null ||
    name === null ||
    description === null ||
    introduction === null ||
    defaultView === null ||
    oracles === null ||
    trackers === null ||
    phases === null ||
    (isDefault !== undefined && typeof isDefault !== 'boolean')
  ) {
    return null
  }
  const read = phases.filter((phase) => phase !== null)
  return read.length === phases.length
    ? {
        key,
        name,
        description,
        introduction,
        default: isDefault ?? false,
        defaultView,
        oracles,
        trackers,
        phases: read,
        extra: unknownFields(definition, flowKnown),
      }
    : null
}

const optionalText = (field: string, text: string): StoredItem =>
  text.trim() === '' ? {} : { [field]: text }

function selectionToItem(selection: SelectionDraft): StoredItem {
  return selection.rule === 'oracle'
    ? { ...selection.extra, rule: selection.rule, table: selection.table }
    : { ...selection.extra, rule: selection.rule, sceneTypes: selection.sceneTypes }
}

function phaseToItem(phase: PhaseDraft): StoredItem {
  return {
    ...phase.extra,
    key: phase.key.trim(),
    name: phase.name.trim(),
    ...(phase.act.trim() === '' ? {} : { act: phase.act.trim() }),
    ...optionalText('description', phase.description),
    mode: phase.mode,
    selection: selectionToItem(phase.selection),
    ...phase.hooks,
  }
}

/** The item to send. `default` is sent only when true: the publisher drops a false one. */
export function flowToItem(draft: FlowDraft): FlowResult {
  return {
    item: {
      ...draft.extra,
      key: draft.key.trim(),
      name: draft.name.trim(),
      ...optionalText('description', draft.description),
      ...optionalText('introduction', draft.introduction),
      ...(draft.default ? { default: true } : {}),
      defaultView: draft.defaultView,
      oracles: draft.oracles,
      trackers: draft.trackers,
      phases: draft.phases.map(phaseToItem),
    },
  }
}

/**
 * A phase to add: the smallest one the publisher accepts, with a key no phase has and a selection
 * that starts at `sceneType`, like the first phase of `newFlowToItem`.
 */
export function newPhase(phases: PhaseDraft[], sceneType: string): PhaseDraft {
  let number = phases.length + 1
  while (phases.some(({ key }) => key === `phase-${String(number)}`)) {
    number += 1
  }
  return {
    key: `phase-${String(number)}`,
    name: `Phase ${String(phases.length + 1)}`,
    act: '',
    description: '',
    mode: 'once',
    selection: { rule: 'sequence', sceneTypes: [sceneType], extra: {} },
    hooks: {},
    extra: {},
  }
}

/** What the New flow dialog asks for: the first phase needs a Scene Type to pick from. */
export interface NewFlowDraft {
  key: string
  name: string
  /** The key of the Scene Type the first phase starts with. */
  sceneType: string
  /** What the Scene Type select offers; never sent. */
  sceneTypes: { key: string; name: string }[]
}

export const newFlow = (sceneTypes: NewFlowDraft['sceneTypes']): NewFlowDraft => ({
  key: '',
  name: '',
  sceneType: sceneTypes[0]?.key ?? '',
  sceneTypes,
})

/** The dialog after a reload: it offers the latest Scene Types and keeps the chosen one while it exists. */
export function refreshNewFlow(
  draft: NewFlowDraft,
  sceneTypes: NewFlowDraft['sceneTypes'],
): NewFlowDraft {
  const kept = sceneTypes.some(({ key }) => key === draft.sceneType)
  return { ...draft, sceneType: kept ? draft.sceneType : (sceneTypes[0]?.key ?? ''), sceneTypes }
}

/**
 * The smallest flow the publisher accepts: `oracles` and `trackers` may be empty, a flow needs one
 * phase, and a phase needs a selection of at least one Scene Type.
 */
export function newFlowToItem({ key, name, sceneType }: NewFlowDraft): FlowResult {
  return {
    item: {
      key: key.trim(),
      name: name.trim(),
      defaultView: 'focus',
      oracles: [],
      trackers: [],
      phases: [
        {
          key: 'main',
          name: 'Main',
          mode: 'once',
          selection: { rule: 'sequence', sceneTypes: [sceneType] },
        },
      ],
    },
  }
}
