import { HOOK_LABELS } from '@/shared/flowDiagram'

import { MAX_EFFECTS, newEffect, type EditedEffect, type EffectDraft } from '../steps/effectDraft'
import type { StepDraft } from '../steps/stepDraft'
import { setHook, type PhaseDraft, type PhaseHook } from './flowDraft'
import { phaseLabel, type MapResult, type SceneNames } from './flowMapEdits'

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

/** The parts of a step that carry effects. A step kept as stored has none: it cannot be edited here. */
function partsOf(step: StepDraft): Part[] {
  if (step.kind === 'stored') {
    return []
  }
  return [
    {
      id: 'step',
      label: 'The step itself',
      effects: step.effects,
      write: (effects) => ({ ...step, effects }),
    },
  ]
}

/** A `nextScene` or `switchSceneType` effect to `sceneType`, as a new one from the step panel starts (`newEffect`) and is then filled. */
export function sceneEffect(
  kind: 'nextScene' | 'switchSceneType',
  sceneType: string,
): EditedEffect {
  return { ...newEffect(kind), kind, sceneType }
}

/** What the Map calls a step in its messages: its title, else its key, else its place. */
export function stepLabel(step: StepDraft, index: number): string {
  const title = step.kind === 'stored' ? '' : step.title.trim()
  const key = step.kind === 'stored' ? '' : step.key.trim()
  return `"${title === '' ? (key === '' ? `Step ${String(index + 1)}` : key) : title}"`
}

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
 * The effect appended to the `carrier` part of the step at `at`, as the step panel's Add effect does.
 * All or nothing: a hook with no step, a step kept as stored, a part the step does not have, an
 * effect the part carries already and a full list each refuse with the reason, and leave the phases alone.
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
  const part = partsOf(step).find(({ id }) => id === carrier)
  const says = describeEffect(effect, names)
  if (part === undefined) {
    return {
      refused:
        step.kind === 'stored'
          ? `Step ${stepLabel(step, at.step)} of the ${hook} cannot be edited here: open its panel.`
          : `Step ${stepLabel(step, at.step)} has no such part.`,
    }
  }
  if (part.effects.some((stored) => sameEffect(stored, effect))) {
    return { refused: `Step ${stepLabel(step, at.step)} already has this effect: ${says}.` }
  }
  if (part.effects.length >= MAX_EFFECTS) {
    return {
      refused: `${part.label} of step ${stepLabel(step, at.step)} has ${String(MAX_EFFECTS)} effects, the most it may.`,
    }
  }
  const written = part.write([...part.effects, effect])
  return {
    phases: phases.map((candidate, index) =>
      index === at.phase
        ? setHook(phase, at.hook, steps.toSpliced(at.step, 1, written))
        : candidate,
    ),
    say: `Added ${says} to step ${stepLabel(step, at.step)} of the ${hook}.`,
  }
}
