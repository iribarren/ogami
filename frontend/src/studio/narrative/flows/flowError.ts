import { clientStepErrors, mapStepError, type StepError } from '../steps/stepError'
import {
  MAX_SELECTION_SCENE_TYPES,
  phaseHooks,
  type FlowDraft,
  type HookErrors,
  type PhaseHook,
} from './flowDraft'

/** What a refused save says about one phase card. */
export interface PhaseAnswer {
  /** The message of a field: `key`, `name`, `act`, `description` or `mode`. */
  fields: Record<string, string>
  /** Anything else under the phase, path included. */
  messages: string[]
  /** What is said about its selection (the rule, the table or the Scene Types), message only. */
  selection: string[]
  /** Per hook, as `mapStepError` read them; a message on the list itself is list-level. */
  hooks: Partial<Record<PhaseHook, StepError[]>>
}

const said = (answer: Partial<PhaseAnswer>): PhaseAnswer => ({
  fields: {},
  messages: [],
  selection: [],
  hooks: {},
  ...answer,
})

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

/** What is said about a phase beyond its own fields: its selection, a hook or something else. */
function otherAnswer(text: string, rest: string, base: string, position: number): PhaseAnswer {
  const selection = /^\.selection(?:\.\w+|\[\d+\])*: (.*)$/s.exec(rest)
  if (selection?.[1] !== undefined) {
    return said({ selection: [selection[1]] })
  }
  // A hook is its path exactly, or a position in it: `sceneOpening` is not `sceneOpeningX`.
  const hook = phaseHooks.find(
    (name) => rest.startsWith(`.${name}[`) || rest.startsWith(`.${name}: `),
  )
  if (hook !== undefined) {
    // A message on the list itself has no step to sit on: it heads the list, path and all.
    const error: StepError = rest.startsWith(`.${hook}: `)
      ? { index: null, band: null, effect: null, field: null, message: text }
      : mapStepError(`${base}.phases[${String(position)}].${hook}`, text)
    // A path the step mapping does not read stays on the card, whole.
    return error.index === null && !rest.startsWith(`.${hook}: `)
      ? said({ messages: [text] })
      : said({ hooks: { [hook]: [error] } })
  }
  return said({ messages: [rest.replace(/^(\.|: )/, '')] })
}

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
    const phases: FlowAnswer['phases'] = []
    phases[position] =
      field?.[1] === undefined
        ? otherAnswer(text, rest, base, position)
        : said({ fields: { [field[1]]: field[2] ?? text } })
    return { ...noAnswer, phases }
  }
  const field = text.startsWith(`${base}.`) ? flowField.exec(text.slice(base.length + 1)) : null
  return field?.[1] === undefined
    ? { ...noAnswer, page: text }
    : { ...noAnswer, fields: { [field[1]]: field[2] ?? text } }
}

/**
 * What is wrong with the draft before anything is sent: an empty key, a phase key used twice, a
 * selection the publisher would refuse (no Scene Type, a player list naming one twice, no table)
 * and the whole numbers a hook's steps refuse (`hookErrors`, from `flowToItem`).
 */
export function flowChecks(draft: FlowDraft, hookErrors: HookErrors = {}): FlowAnswer | null {
  const phases: FlowAnswer['phases'] = []
  const seen = new Set<string>()
  draft.phases.forEach((phase, position) => {
    const key = phase.key.trim()
    const message =
      key === '' ? 'Enter a key.' : seen.has(key) ? 'Another phase has this key.' : null
    seen.add(key)
    const { selection } = phase
    const refused =
      selection.rule === 'oracle'
        ? selection.table === ''
          ? 'Pick an oracle table.'
          : null
        : selection.sceneTypes.length === 0
          ? 'Pick at least one Scene Type.'
          : selection.sceneTypes.length > MAX_SELECTION_SCENE_TYPES
            ? `Pick at most ${String(MAX_SELECTION_SCENE_TYPES)} Scene Types.`
            : selection.rule === 'player' &&
                new Set(selection.sceneTypes).size < selection.sceneTypes.length
              ? 'A player selection lists each Scene Type once.'
              : null
    const hooks: PhaseAnswer['hooks'] = {}
    for (const [hook, errors] of Object.entries(hookErrors[position] ?? {})) {
      hooks[hook as PhaseHook] = clientStepErrors(errors)
    }
    if (message !== null || refused !== null || Object.keys(hooks).length > 0) {
      phases[position] = said({
        fields: message === null ? {} : { key: message },
        selection: refused === null ? [] : [refused],
        hooks,
      })
    }
  })
  const empty = draft.key.trim() === ''
  return empty || phases.length > 0
    ? { ...noAnswer, fields: empty ? { key: 'Enter a key.' } : {}, phases }
    : null
}
