import { HOOK_LABELS } from '@/shared/flowDiagram'

import { MAX_EFFECTS, newEffect, type EditedEffect, type EffectDraft } from '../steps/effectDraft'
import {
  isSpecialOutcome,
  oracleAnswers,
  pickOutcomes,
  type CheckSpecial,
  type OracleAnswer,
  type PickOutcome,
  type StepDraft,
} from '../steps/stepDraft'
import { setHook, type PhaseDraft, type PhaseHook } from './flowDraft'
import { scenePartNames, type SceneTypeDraft, type ScenePart } from '../sceneTypes/sceneTypeDraft'
import {
  isRefusal,
  phaseLabel,
  type MapRefusal,
  type MapResult,
  type SceneNames,
} from './flowMapEdits'

/**
 * Where in a hook's steps an effect goes: the step and the part of it that carries the effect.
 * A part is the step itself (carrier id `step`); the arrows of the Map add transitions to a part.
 */
export interface EffectAt {
  phase: number
  hook: PhaseHook
  step: number
}

/** One part of a step that carries `effects`, and how to write a new list back into the step. */
interface Part {
  id: string
  label: string
  effects: EffectDraft[]
  write: (effects: EffectDraft[]) => StepDraft
}

const ANSWERS: Record<OracleAnswer, string> = {
  yes: 'Yes',
  no: 'No',
  exceptionalYes: 'Exceptional yes',
  exceptionalNo: 'Exceptional no',
}
const FOUND: Record<PickOutcome, string> = { found: 'Found', none: 'None' }
const SPECIALS: Record<CheckSpecial, string> = {
  critical: 'Critical',
  messyCritical: 'Messy critical',
  bestialFailure: 'Bestial failure',
}

/** A part named by what the author wrote in it, else by its place. */
const named = (what: string, written: string, index: number) =>
  `${what} ${written.trim() === '' ? String(index + 1) : `"${written.trim()}"`}`

/**
 * The parts of a step that carry effects: the step itself, and its bands, options and outcomes. A
 * step kept as stored has none: it cannot be edited here. A step is read as its draft says, so a
 * legacy one with bands or options missing simply has fewer parts.
 */
function partsOf(step: StepDraft): Part[] {
  if (step.kind === 'stored') {
    return []
  }
  /** A part of the step that is an outcome: a band, an option, a branch. */
  const outcome = <T extends { effects: EffectDraft[] }>(
    id: string,
    label: string,
    list: T[],
    index: number,
    write: (list: T[]) => StepDraft,
  ): Part => {
    const item = list[index]
    return {
      id,
      label,
      effects: item?.effects ?? [],
      write: (effects) => write(list.toSpliced(index, 1, { ...item, effects } as T)),
    }
  }
  const parts: Part[] = [
    {
      id: 'step',
      label: 'The step itself',
      effects: step.effects,
      write: (effects) => ({ ...step, effects }),
    },
  ]
  switch (step.kind) {
    case 'roll':
    case 'condition':
      step.bands.forEach((band, at) => {
        const upTo =
          band.upTo === null ? 'Any other result' : `Up to ${band.upTo.text.trim() || '?'}`
        const label = band.label.trim() === '' ? upTo : `${upTo}: ${band.label.trim()}`
        parts.push(
          outcome(`band:${String(at)}`, label, step.bands, at, (bands) => ({ ...step, bands })),
        )
      })
      break
    case 'choice':
      step.options.forEach((option, at) => {
        const label = named('Option', option.label.trim() === '' ? option.key : option.label, at)
        parts.push(
          outcome(`option:${String(at)}`, label, step.options, at, (options) => ({
            ...step,
            options,
          })),
        )
      })
      break
    case 'oracle':
      for (const answer of oracleAnswers) {
        parts.push({
          id: `oracle:${answer}`,
          label: `Answer: ${ANSWERS[answer]}`,
          effects: step.branches[answer].effects,
          write: (effects) => ({
            ...step,
            branches: { ...step.branches, [answer]: { ...step.branches[answer], effects } },
          }),
        })
      }
      break
    case 'pick':
      for (const found of pickOutcomes) {
        parts.push({
          id: `pick:${found}`,
          label: `Outcome: ${FOUND[found]}`,
          effects: step.branches[found].effects,
          write: (effects) => ({
            ...step,
            branches: { ...step.branches, [found]: { ...step.branches[found], effects } },
          }),
        })
      }
      break
    case 'table':
      step.branches.forEach((branch, at) => {
        parts.push(
          outcome(
            `branch:${String(at)}`,
            named('Entry', branch.entry, at),
            step.branches,
            at,
            (branches) => ({
              ...step,
              branches,
            }),
          ),
        )
      })
      parts.push({
        id: 'otherwise',
        label: 'Otherwise',
        effects: step.otherwise.effects,
        write: (effects) => ({ ...step, otherwise: { ...step.otherwise, effects } }),
      })
      break
    case 'check':
      step.outcomes.forEach((result, at) => {
        const label = isSpecialOutcome(result)
          ? `Special result: ${SPECIALS[result.special]}`
          : named('Band', result.band, at)
        parts.push(
          outcome(`check:${String(at)}`, label, step.outcomes, at, (outcomes) => ({
            ...step,
            outcomes,
          })),
        )
      })
      break
    default:
      break
  }
  return parts
}

/** The parts of `step` an effect can be added to, as `{ id, label }`; none for a step kept as stored. */
export function carriersOf(step: StepDraft): { id: string; label: string }[] {
  return partsOf(step).map(({ id, label }) => ({ id, label }))
}

/** A `nextScene` or `switchSceneType` effect to `sceneType`, as a new one from the step panel starts (`newEffect`) and is then filled. */
export function sceneEffect(
  kind: 'nextScene' | 'switchSceneType',
  sceneType: string,
): EditedEffect {
  return { ...newEffect(kind), kind, sceneType }
}

/** What the Map calls a step in its messages: its title, else its key, else its place. */
export function stepName(step: StepDraft, index: number): string {
  const title = step.kind === 'stored' ? '' : step.title.trim()
  const key = step.kind === 'stored' ? '' : step.key.trim()
  return title === '' ? (key === '' ? `Step ${String(index + 1)}` : key) : title
}

/** `stepName` in quotes, as the messages quote a name. */
export const stepLabel = (step: StepDraft, index: number): string => `"${stepName(step, index)}"`

/** What an effect says it does, for the Map's messages. */
export function describeEffect(effect: EditedEffect, names: SceneNames): string {
  switch (effect.kind) {
    case 'nextScene':
      return `next scene "${names(effect.sceneType)}"`
    case 'switchSceneType':
      return `switch to Scene Type "${names(effect.sceneType)}"`
    case 'endPhase':
      return 'end the phase'
    default:
      return `a ${effect.kind} effect`
  }
}

/** True when both effects do the same: the same kind, and the same Scene Type for the two that name one. */
const sameEffect = (stored: EffectDraft, added: EditedEffect): boolean =>
  stored.kind === added.kind &&
  (added.kind === 'endPhase' ||
    ('sceneType' in stored && 'sceneType' in added && stored.sceneType === added.sceneType))

/**
 * The step with `effect` appended to its `carrier` part, as the step panel's Add effect does; `at`
 * is the step's place in its list, for the messages, and `within` says where the list is (` of the
 * hook …`). All or nothing: a step kept as stored, a part the step does not have, an effect the part
 * carries already and a full list each refuse with the reason.
 */
export function addEffectToStep(
  step: StepDraft,
  at: number,
  carrier: string,
  effect: EditedEffect,
  names: SceneNames,
  within = '',
): { step: StepDraft } | MapRefusal {
  const part = partsOf(step).find(({ id }) => id === carrier)
  if (part === undefined) {
    return {
      refused:
        step.kind === 'stored'
          ? `Step ${stepLabel(step, at)}${within} cannot be edited here: open its panel.`
          : `Step ${stepLabel(step, at)} has no such part.`,
    }
  }
  if (part.effects.some((stored) => sameEffect(stored, effect))) {
    return {
      refused: `Step ${stepLabel(step, at)} already has this effect: ${describeEffect(effect, names)}.`,
    }
  }
  if (part.effects.length >= MAX_EFFECTS) {
    return {
      refused: `${part.label} of step ${stepLabel(step, at)} has ${String(MAX_EFFECTS)} effects, the most it may.`,
    }
  }
  return { step: part.write([...part.effects, effect]) }
}

/**
 * The effect appended to the `carrier` part of the step at `at` (`addEffectToStep`) in the phases.
 * All or nothing: a hook with no step and a step that is not there refuse too, and leave the phases alone.
 */
export function addEffect(
  phases: PhaseDraft[],
  at: EffectAt,
  carrier: string,
  effect: EditedEffect,
  names: SceneNames,
): MapResult {
  const phase = phases[at.phase]
  if (phase === undefined) {
    return { refused: 'That phase is not in the flow.' }
  }
  const hook = `hook "${HOOK_LABELS[at.hook]}" of phase ${phaseLabel(phase)}`
  const steps = phase.hooks[at.hook] ?? []
  const step = steps[at.step]
  if (step === undefined) {
    return {
      refused:
        steps.length === 0
          ? `The ${hook} has no step yet: add a step in its panel first.`
          : `The ${hook} has no such step.`,
    }
  }
  const added = addEffectToStep(step, at.step, carrier, effect, names, ` of the ${hook}`)
  if (isRefusal(added)) {
    return added
  }
  return {
    phases: phases.map((candidate, index) =>
      index === at.phase
        ? setHook(phase, at.hook, steps.toSpliced(at.step, 1, added.step))
        : candidate,
    ),
    say: `Added ${describeEffect(effect, names)} to step ${stepLabel(step, at.step)} of the ${hook}.`,
  }
}

/** A step of a Scene Type, as the transition dialog lists it: where it is, and what it is called there. */
export interface SceneStep {
  part: ScenePart
  index: number
  step: StepDraft
  /** `Setup · 1 "Arrive"`. */
  label: string
}

const PART_TITLES: Record<ScenePart, string> = { setup: 'Setup', play: 'Play', closing: 'Closing' }

/** The title of a Scene Type's step list, as its page shows it. */
export const partTitle = (part: ScenePart): string => PART_TITLES[part]

/**
 * Every step of a Scene Type in page order (setup, play, closing), each with its list and place.
 * The Scene Type page offers transitions in every list: only sheet effects are held back in setup.
 */
export function sceneTypeSteps(draft: SceneTypeDraft): SceneStep[] {
  return scenePartNames.flatMap((part) =>
    draft.steps[part].map((step, index) => ({
      part,
      index,
      step,
      label: `${PART_TITLES[part]} · ${String(index + 1)} ${stepLabel(step, index)}`,
    })),
  )
}

/**
 * The steps of a hook that an arrow can add an effect to, or the reason it cannot: the hook has no
 * step yet, or every step is one kept as stored.
 */
export function hookSteps(
  phases: PhaseDraft[],
  at: Omit<EffectAt, 'step'>,
): StepDraft[] | MapRefusal {
  const phase = phases[at.phase]
  if (phase === undefined) {
    return { refused: 'That phase is not in the flow.' }
  }
  const hook = `hook "${HOOK_LABELS[at.hook]}" of phase ${phaseLabel(phase)}`
  const steps = phase.hooks[at.hook] ?? []
  if (steps.length === 0) {
    return { refused: `The ${hook} has no step yet: add a step in its panel first.` }
  }
  return steps.some((step) => partsOf(step).length > 0)
    ? steps
    : { refused: `No step of the ${hook} can be edited here: open its panel.` }
}
