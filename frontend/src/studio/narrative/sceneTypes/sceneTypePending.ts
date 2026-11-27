import { newEffect, type EditedEffect } from '../steps/effectDraft'
import { addEffectToStep, partTitle, sceneEffect } from '../flows/flowMapCarriers'
import { isRefusal } from '../flows/flowMapEdits'
import type { SceneTypeDraft } from './sceneTypeDraft'
import type { PendingTransition } from './sceneTypeParams'

/**
 * The Scene Type with the transition the flow Map drew added to its step, as the step panel's Add
 * effect would add it, or why it cannot be: the step or its part is not there, the part has the
 * effect already or is full, the target Scene Type is not in the draft. All or nothing: a refusal
 * leaves the draft as stored. `names` gives a Scene Type's name; `known` says which keys exist.
 */
export function addPendingTransition(
  draft: SceneTypeDraft,
  { step: place, carrier, effect: asked }: PendingTransition,
  names: (sceneType: string) => string,
  known: (sceneType: string) => boolean,
): { draft: SceneTypeDraft; say: string } | { refused: string } {
  const list = draft.steps[place.part]
  const title = partTitle(place.part)
  const step = list[place.index]
  if (step === undefined) {
    return { refused: `Step ${String(place.index + 1)} of ${title} is not in this Scene Type.` }
  }
  if (asked.kind !== 'endPhase' && !known(asked.sceneType)) {
    return { refused: `The Scene Type "${asked.sceneType}" is not in the draft.` }
  }
  const effect: EditedEffect =
    asked.kind === 'endPhase' ? newEffect('endPhase') : sceneEffect(asked.kind, asked.sceneType)
  const added = addEffectToStep(step, place.index, carrier, effect, names, ` of ${title}`)
  if (isRefusal(added)) {
    return added
  }
  const what =
    asked.kind === 'nextScene'
      ? `a transition to "${names(asked.sceneType)}"`
      : asked.kind === 'switchSceneType'
        ? `a switch to Scene Type "${names(asked.sceneType)}"`
        : 'a transition that ends the phase'
  return {
    draft: {
      ...draft,
      steps: { ...draft.steps, [place.part]: list.toSpliced(place.index, 1, added.step) },
    },
    say: `Added ${what} on step ${String(place.index + 1)} of ${title}. Save to keep it.`,
  }
}
