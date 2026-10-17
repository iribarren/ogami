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
    }
  }
  return { fields: {}, form: text }
}
