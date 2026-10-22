import type { EffectDraft } from '../steps/effectDraft'
import type { StepContext } from '../steps/stepContext'
import type { StepDraft } from '../steps/stepDraft'
import type { GameSystemNarrative } from '../useGameSystemNarrative'
import type { FlowDraft, SelectionDraft } from './flowDraft'

/** What a flow needs enabled (its `trackers` and `oracles` lists) beyond what it has. */
export interface Missing {
  trackers: string[]
  oracles: string[]
}

export const nothingMissing: Missing = { trackers: [], oracles: [] }

export const hasMissing = (missing: Missing) =>
  missing.trackers.length > 0 || missing.oracles.length > 0

/**
 * The keys the draft can enable: the trackers and oracle shortcuts (tables and likelihood oracles)
 * that exist, and each Scene Type's own shortcuts. A key outside these is left to the publisher's
 * validation: enabling it would not make the flow valid.
 */
export interface FlowScope {
  trackers: string[]
  oracles: string[]
  sceneTypeOracles: Record<string, string[]>
}

export function flowScope(narrative: GameSystemNarrative, context: StepContext): FlowScope {
  const sceneTypeOracles: Record<string, string[]> = {}
  for (const { key, definition } of narrative.sceneTypes) {
    const shortcuts: unknown = definition.oracles
    sceneTypeOracles[key] = Array.isArray(shortcuts)
      ? shortcuts.filter((shortcut): shortcut is string => typeof shortcut === 'string')
      : []
  }
  return {
    trackers: context.trackers.map(({ key }) => key),
    oracles: [...context.tables, ...context.likelihood].map(({ key }) => key),
    sceneTypeOracles,
  }
}

/** Every effect list a step has: its own, its bands', its options', its branches' and outcomes'. */
function effectLists(step: Exclude<StepDraft, { kind: 'stored' }>): EffectDraft[][] {
  const lists = [step.effects]
  if ('bands' in step) {
    lists.push(...step.bands.map((band) => band.effects))
  }
  if ('options' in step) {
    lists.push(...step.options.map((option) => option.effects))
  }
  if ('outcomes' in step) {
    lists.push(...step.outcomes.map((outcome) => outcome.effects))
  }
  if ('otherwise' in step) {
    lists.push(step.otherwise.effects)
  }
  if ('branches' in step) {
    const outcomes = Array.isArray(step.branches) ? step.branches : Object.values(step.branches)
    lists.push(...outcomes.map((outcome) => outcome.effects))
  }
  return lists
}

/** The trackers a step changes with an effect or reads in a condition, once each, in order. */
export function stepTrackers(step: StepDraft): string[] {
  if (step.kind === 'stored') {
    return []
  }
  const keys = effectLists(step)
    .flat()
    .flatMap((effect) => (effect.kind === 'tracker' ? [effect.tracker] : []))
  if (step.kind === 'condition' && step.source === 'tracker') {
    keys.unshift(step.tracker)
  }
  return [...new Set(keys.filter((key) => key !== ''))]
}

const absent = (keys: string[], enabled: string[], exist: string[]) =>
  [...new Set(keys)].filter((key) => exist.includes(key) && !enabled.includes(key))

/** What a step uses that the flow does not enable. */
export function missingForStep(draft: FlowDraft, step: StepDraft, scope: FlowScope): Missing {
  return {
    trackers: absent(stepTrackers(step), draft.trackers, scope.trackers),
    oracles: [],
  }
}

/** The oracle shortcuts of the Scene Types a selection plays directly that the flow does not enable. */
export function missingForSelection(
  draft: FlowDraft,
  selection: SelectionDraft,
  scope: FlowScope,
): Missing {
  const keys =
    selection.rule === 'oracle'
      ? []
      : selection.sceneTypes.flatMap((sceneType) => scope.sceneTypeOracles[sceneType] ?? [])
  return { trackers: [], oracles: absent(keys, draft.oracles, scope.oracles) }
}

/** Everything the flow's steps and selections use that it does not enable. */
export function missingForFlow(draft: FlowDraft, scope: FlowScope): Missing {
  const parts = draft.phases.flatMap((phase) => [
    missingForSelection(draft, phase.selection, scope),
    ...Object.values(phase.hooks).flatMap((steps) =>
      steps.map((step) => missingForStep(draft, step, scope)),
    ),
  ])
  return {
    trackers: [...new Set(parts.flatMap((part) => part.trackers))],
    oracles: [...new Set(parts.flatMap((part) => part.oracles))],
  }
}

/** The draft with the missing keys added to the flow's lists: nothing is removed or reordered. */
export function enableOnFlow(draft: FlowDraft, missing: Missing): FlowDraft {
  const add = (keys: string[], more: string[]) => [
    ...keys,
    ...more.filter((key) => !keys.includes(key)),
  ]
  return {
    ...draft,
    trackers: add(draft.trackers, missing.trackers),
    oracles: add(draft.oracles, missing.oracles),
  }
}
