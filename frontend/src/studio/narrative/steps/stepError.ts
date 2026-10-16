/** Where a server 422 about a step list belongs in the editor. */
export interface StepError {
  /** The card; null when the message is not about one step of this list. */
  index: number | null
  /** The band of the step, when the path names one. */
  band: number | null
  /** The effect (of the step, or of the band when there is one), when the path names one. */
  effect: number | null
  /** The field the path ends in (`title`, `upTo`, an effect's `value`, …); null when it is about the step, band or effect itself. */
  field: string | null
  message: string
}

const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

type Part = { name: string } | { at: number }

/** `.bands[0].upTo` as its parts: names and positions. */
const partsOf = (path: string): Part[] =>
  [...path.matchAll(/\.(\w+)|\[(\d+)\]/g)].map(([, name, at]) =>
    name === undefined ? { at: Number(at) } : { name },
  )

/**
 * Reads a `path: message` the publisher answered with (e.g. `sceneTypes[2].play[1].bands[0].upTo:
 * must be an integer`) for the step list found at `base` (e.g. `sceneTypes[2].play`). A path
 * outside the list or of a shape this does not know comes back whole as a list-level error.
 */
export function mapStepError(base: string, text: string): StepError {
  const matched = new RegExp(
    `^${escape(base)}\\[(\\d+)\\]((?:\\.\\w+|\\[\\d+\\])*): (.*)$`,
    's',
  ).exec(text)
  if (matched === null) {
    return { index: null, band: null, effect: null, field: null, message: text }
  }
  const [, index = '', path = '', message = ''] = matched
  const error: StepError = { index: Number(index), band: null, effect: null, field: null, message }
  const parts = partsOf(path)
  const [first, second] = parts
  if (first && 'name' in first && first.name === 'bands' && second && 'at' in second) {
    error.band = second.at
    parts.splice(0, 2)
  }
  const [head, indexed, field] = parts
  if (head && 'name' in head && head.name === 'effects' && indexed && 'at' in indexed) {
    error.effect = indexed.at
    error.field = field && 'name' in field ? field.name : null
  } else {
    error.field = head && 'name' in head ? head.name : null
  }
  return error
}
