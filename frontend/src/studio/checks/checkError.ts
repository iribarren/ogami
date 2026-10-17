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

/**
 * Reads the publisher's `path: message` for the check at `index` of the draft's checks
 * (`checks[2].dice: …`). A path of another check, of the bands or of a property the form has no
 * input for comes back whole as a form message.
 */
export function checkAnswer(text: string, index: number): CheckAnswer {
  const prefix = `checks[${String(index)}].`
  if (text.startsWith(prefix)) {
    const match = /^([\w.]+): (.*)$/s.exec(text.slice(prefix.length))
    const [, path, message] = match ?? []
    const input = path === undefined ? undefined : inputs[path]
    if (input !== undefined && message !== undefined) {
      return { fields: { [input]: message }, form: null }
    }
  }
  return { fields: {}, form: text }
}
