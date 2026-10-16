import type { FieldErrors } from '../narrativeDraft'
import { listOf, stringsOf, textOf, unknownFields } from '../steps/draftParts'
import { stepsToDrafts, stepsToItems, type StepDraft, type StepsResult } from '../steps/stepDraft'
import type { StoredItem } from '../useGameSystemNarrative'

// Typed form state of a Scene Type: its own fields and its three step lists. Like the other
// narrative drafts, what the editor does not know stays in `extra` and is sent back untouched,
// and a stored item it cannot read at all is `null`: the tab lists it read-only. The publisher
// owns every rule, so nothing is checked here beyond what the step lists refuse themselves.

/** The three step lists of a Scene Type, in schema order. */
export const scenePartNames = ['setup', 'play', 'closing'] as const
export type ScenePart = (typeof scenePartNames)[number]

export interface SceneTypeDraft {
  key: string
  name: string
  purpose: string
  tips: string
  /** The oracle shortcuts, as stored: a key the draft no longer has stays until it is unticked. */
  oracles: string[]
  steps: Record<ScenePart, StepDraft[]>
  /** Fields this editor does not know. */
  extra: StoredItem
}

export type SceneTypeResult = { item: StoredItem } | { errors: Record<ScenePart, FieldErrors> }

const known = ['key', 'name', 'purpose', 'tips', 'oracles', ...scenePartNames]

/** What the New Scene Type dialog asks for; the editor page takes it from there. */
export interface NewSceneTypeDraft {
  key: string
  name: string
  purpose: string
}

/**
 * The smallest Scene Type the publisher accepts: its oracle shortcuts and its three step lists
 * may be empty.
 */
export function newSceneTypeToItem({ key, name, purpose }: NewSceneTypeDraft): {
  item: StoredItem
} {
  return {
    item: {
      key: key.trim(),
      name: name.trim(),
      purpose: purpose.trim(),
      oracles: [],
      setup: [],
      play: [],
      closing: [],
    },
  }
}

/** The Scene Type as form state; null when a field has a type this editor does not read. */
export function sceneTypeToDraft(definition: StoredItem): SceneTypeDraft | null {
  const key = textOf(definition, 'key')
  const name = textOf(definition, 'name')
  const purpose = textOf(definition, 'purpose')
  const tips = textOf(definition, 'tips')
  const oracles = stringsOf(definition, 'oracles')
  const setup = listOf(definition, 'setup')
  const play = listOf(definition, 'play')
  const closing = listOf(definition, 'closing')
  if (
    key === null ||
    name === null ||
    purpose === null ||
    tips === null ||
    oracles === null ||
    setup === null ||
    play === null ||
    closing === null
  ) {
    return null
  }
  return {
    key,
    name,
    purpose,
    tips,
    oracles,
    steps: {
      setup: stepsToDrafts(setup),
      play: stepsToDrafts(play),
      closing: stepsToDrafts(closing),
    },
    extra: unknownFields(definition, known),
  }
}

const errorsOf = (result: StepsResult): FieldErrors => ('errors' in result ? result.errors : {})
const stepsOf = (result: StepsResult): StoredItem[] => ('steps' in result ? result.steps : [])

/** The item to send, or the whole numbers each step list refuses; nothing is sent with any of them. */
export function sceneTypeToItem(draft: SceneTypeDraft): SceneTypeResult {
  const { setup, play, closing } = {
    setup: stepsToItems(draft.steps.setup),
    play: stepsToItems(draft.steps.play),
    closing: stepsToItems(draft.steps.closing),
  }
  if ([setup, play, closing].some((result) => 'errors' in result)) {
    return { errors: { setup: errorsOf(setup), play: errorsOf(play), closing: errorsOf(closing) } }
  }
  return {
    item: {
      ...draft.extra,
      key: draft.key.trim(),
      name: draft.name.trim(),
      purpose: draft.purpose.trim(),
      ...(draft.tips.trim() === '' ? {} : { tips: draft.tips.trim() }),
      oracles: draft.oracles,
      setup: stepsOf(setup),
      play: stepsOf(play),
      closing: stepsOf(closing),
    },
  }
}
