import { mapStepError, type StepError } from '../steps/stepError'
import { scenePartNames, type ScenePart } from './sceneTypeDraft'

/** Where a refused save of a Scene Type belongs on its editor page. */
export interface SceneTypeAnswer {
  /** Per step list, as `mapStepError` read them. */
  lists: Record<ScenePart, StepError[]>
  /** The message of a field: `key`, `name`, `purpose`, `tips` or `oracles`. */
  fields: Record<string, string>
  /** What belongs nowhere in particular: shown above the form. */
  page: string | null
  /** The draft changed since the form was filled: only a reload helps. */
  conflict: boolean
}

export const noAnswer: SceneTypeAnswer = {
  lists: { setup: [], play: [], closing: [] },
  fields: {},
  page: null,
  conflict: false,
}

/**
 * Reads the publisher's `path: message` for the Scene Type at `index` of the draft
 * (`sceneTypes[2].play[1].title: …`). A path of another Scene Type, or of a part this page does not
 * know, comes back whole as a page message.
 */
export function sceneTypeAnswer(text: string, index: number): SceneTypeAnswer {
  const base = `sceneTypes[${String(index)}]`
  // A list is its path exactly, or a position in it: `play` is not `playX`.
  const part = scenePartNames.find(
    (name) => text.startsWith(`${base}.${name}[`) || text.startsWith(`${base}.${name}: `),
  )
  if (part !== undefined) {
    // A message on the list itself has no step to sit on: it heads the list, path and all.
    const error: StepError = text.startsWith(`${base}.${part}: `)
      ? { index: null, band: null, effect: null, field: null, message: text }
      : mapStepError(`${base}.${part}`, text)
    return { ...noAnswer, lists: { ...noAnswer.lists, [part]: [error] } }
  }
  const field = text.startsWith(`${base}.`)
    ? /^(key|name|purpose|tips|oracles)(?:\[\d+\])?: (.*)$/s.exec(text.slice(base.length + 1))
    : null
  return field?.[1] === undefined
    ? { ...noAnswer, page: text }
    : { ...noAnswer, fields: { [field[1]]: field[2] ?? text } }
}
