import type { FlowDraft } from './flowDraft'

/** What a refused save says about one phase card. */
export interface PhaseAnswer {
  /** The message of a field: `key`, `name`, `act`, `description` or `mode`. */
  fields: Record<string, string>
  /** Anything else under the phase (its selection, a hook), path included. */
  messages: string[]
}

/** Where a refused save of a flow belongs on its editor page. */
export interface FlowAnswer {
  /** The message of a flow field: `key`, `name`, `description`, `introduction`, `default`, `defaultView`, `oracles` or `trackers`. */
  fields: Record<string, string>
  /** By phase position; a hole is a phase with nothing said about it. */
  phases: (PhaseAnswer | undefined)[]
  /** What belongs nowhere in particular: shown above the form. */
  page: string | null
  /** The draft changed since the form was filled: only a reload helps. */
  conflict: boolean
}

export const noAnswer: FlowAnswer = { fields: {}, phases: [], page: null, conflict: false }

const flowField =
  /^(key|name|description|introduction|default|defaultView|oracles|trackers)(?:\[\d+\])?: (.*)$/s
const phaseField = /^\.(key|name|act|description|mode): (.*)$/s

/**
 * Reads the publisher's `path: message` for the flow at `index` of the draft
 * (`flows[0].phases[1].key: …`) when it has `phaseCount` phases. A path of another flow, of a phase
 * the page does not have, or of the phase list itself comes back whole as a page message.
 */
export function flowAnswer(text: string, index: number, phaseCount: number): FlowAnswer {
  const base = `flows[${String(index)}]`
  const inPhase = new RegExp(
    `^${base.replace(/[[\]]/g, '\\$&')}\\.phases\\[(\\d+)\\](.*)$`,
    's',
  ).exec(text)
  if (inPhase?.[1] !== undefined) {
    const position = Number(inPhase[1])
    const rest = inPhase[2] ?? ''
    if (position >= phaseCount) {
      return { ...noAnswer, page: text }
    }
    const field = phaseField.exec(rest)
    const answer: PhaseAnswer =
      field?.[1] === undefined
        ? { fields: {}, messages: [rest.replace(/^(\.|: )/, '')] }
        : { fields: { [field[1]]: field[2] ?? text }, messages: [] }
    const phases: FlowAnswer['phases'] = []
    phases[position] = answer
    return { ...noAnswer, phases }
  }
  const field = text.startsWith(`${base}.`) ? flowField.exec(text.slice(base.length + 1)) : null
  return field?.[1] === undefined
    ? { ...noAnswer, page: text }
    : { ...noAnswer, fields: { [field[1]]: field[2] ?? text } }
}

/** What is wrong with the draft before anything is sent: an empty key, or a phase key used twice. */
export function flowChecks(draft: FlowDraft): FlowAnswer | null {
  const phases: FlowAnswer['phases'] = []
  const seen = new Set<string>()
  draft.phases.forEach((phase, position) => {
    const key = phase.key.trim()
    const message =
      key === '' ? 'Enter a key.' : seen.has(key) ? 'Another phase has this key.' : null
    seen.add(key)
    if (message !== null) {
      phases[position] = { fields: { key: message }, messages: [] }
    }
  })
  const empty = draft.key.trim() === ''
  return empty || phases.length > 0
    ? { ...noAnswer, fields: empty ? { key: 'Enter a key.' } : {}, phases }
    : null
}
