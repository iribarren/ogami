import type { StepError } from '../narrative/steps/stepError'
import type { CheckErrors } from './checkDraft'

/** Where a refused save of a check belongs in its editor. */
export interface CheckAnswer {
  /** The message of an input, keyed by the input the publisher's path names. */
  fields: CheckErrors
  /** What belongs to no input: shown with the form, path and all. */
  form: string | null
}

/** The paths below a check that the editor has an input for. */
const inputs: Record<string, keyof CheckErrors> = {
  key: 'key',
  label: 'label',
  hint: 'hint',
  dice: 'dice',
  modifier: 'modifier',
  'pool.count': 'poolCount',
  'pool.count.formula': 'poolCount',
  'pool.sides': 'poolSides',
  'pool.successAt': 'poolSuccessAt',
  'push.label': 'pushLabel',
  difficulty: 'difficulty',
  'difficulty.label': 'difficultyLabel',
  'difficulty.default': 'difficultyDefault',
  'difficulty.min': 'difficultyMin',
  'difficulty.max': 'difficultyMax',
}

/** The band input a path below `bands` names: `bands[1].key`, `bands[1].upTo.formula`… */
function bandAnswer(path: string, message: string): CheckAnswer | null {
  if (path === 'bands') {
    return { fields: { bands: message }, form: null }
  }
  const match = /^bands\[(\d+)\]\.(key|label|upTo)(?:\.(?:tracker|formula))?$/.exec(path)
  const [, position, input] = match ?? []
  if (position === undefined || input === undefined) {
    return null
  }
  return { fields: { band: { [Number(position)]: { [input]: message } } }, form: null }
}

/**
 * The push effects a path below `push.effects` names: the list (`push.effects`), an effect
 * (`push.effects[1]`) or one of its fields (`push.effects[1].value.formula`).
 */
function pushAnswer(path: string, message: string): CheckAnswer | null {
  const match = /^push\.effects(?:\[(\d+)\]((?:\.\w+)*))?$/.exec(path)
  if (match === null) {
    return null
  }
  const [, position, rest = ''] = match
  const error: StepError = {
    index: null,
    band: null,
    effect: position === undefined ? null : Number(position),
    field: position === undefined ? 'effects' : (rest.split('.')[1] ?? null),
    message,
  }
  return { fields: { pushEffects: [error] }, form: null }
}

/**
 * Reads the publisher's `path: message` for the check at `index` of the draft's checks
 * (`checks[2].dice: …`). A path of another check or of a property the form has no
 * input for comes back whole as a form message.
 */
export function checkAnswer(text: string, index: number): CheckAnswer {
  const prefix = `checks[${String(index)}].`
  if (text.startsWith(prefix)) {
    const match = /^([\w.[\]]+): (.*)$/s.exec(text.slice(prefix.length))
    const [, path, message] = match ?? []
    if (path !== undefined && message !== undefined) {
      const input = inputs[path]
      if (input !== undefined) {
        return { fields: { [input]: message }, form: null }
      }
      const band = bandAnswer(path, message)
      if (band !== null) {
        return band
      }
      const push = pushAnswer(path, message)
      if (push !== null) {
        return push
      }
    }
  }
  return { fields: {}, form: text }
}
