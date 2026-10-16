/** Where a server 422 about a step list belongs in the editor. */
export interface StepError {
  /** The card; null when the message is not about one step of this list. */
  index: number | null
  /** The band of the step, when the path names one. */
  band: number | null
  /** The field the path ends in (`title`, `bands`' `upTo`, …); null when it is about the step itself. */
  field: string | null
  message: string
}

const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

/**
 * Reads a `path: message` the publisher answered with (e.g. `sceneTypes[2].play[1].bands[0].upTo:
 * must be an integer`) for the step list found at `base` (e.g. `sceneTypes[2].play`). A path
 * outside the list or of a shape this does not know comes back whole as a list-level error.
 */
export function mapStepError(base: string, text: string): StepError {
  const matched = new RegExp(
    `^${escape(base)}\\[(\\d+)\\](?:\\.(\\w+)(?:\\[(\\d+)\\])?(?:\\.(\\w+))?)?: (.*)$`,
    's',
  ).exec(text)
  if (matched === null) {
    return { index: null, band: null, field: null, message: text }
  }
  const [, index = '', field, position, inner, message = ''] = matched
  if (field === 'bands' && position !== undefined) {
    return { index: Number(index), band: Number(position), field: inner ?? null, message }
  }
  return { index: Number(index), band: null, field: field ?? null, message }
}
