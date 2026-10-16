import { parseInteger } from '../../oracles/tableDraft'
import type { FieldErrors } from '../narrativeDraft'
import type { StoredItem } from '../useGameSystemNarrative'
import type { StepContext } from './stepContext'
import {
  isObject,
  listOf,
  readValue,
  stringsOf,
  textOf,
  unknownFields,
  valueToStored,
  WHOLE,
  type ValueDraft,
  type ValueForm,
} from './draftParts'
import { effectsToDrafts, effectsToStored, type EffectDraft } from './effectDraft'

// Typed form state for a step list (a Scene Type's `setup`, `play` and `closing`, a flow's hooks).
// A step is read into strings the inputs can hold and written back. What the editor does not know
// stays in `extra` and is sent back untouched; a step of a kind this editor does not edit, or of a
// shape it cannot read, is kept as stored (`kind: 'stored'`), shown read-only, never dropped or
// guessed. Effects are typed drafts of their own (`effectDraft`). The publisher owns every
// rule: the guides at the bottom only flag what the author is likely to want to fix first.

/** The reserved `next` that ends the list. */
export const END = 'end'

export const stepKinds = [
  'prompt',
  'character',
  'roll',
  'condition',
  'oracle',
  'table',
  'choice',
  'pick',
  'check',
] as const
export type StepKind = (typeof stepKinds)[number]

export const countTargets = ['npc', 'thread', 'character'] as const
export type CountTarget = (typeof countTargets)[number]

export interface BandDraft {
  /** Null for the last band, which catches the rest. */
  upTo: ValueDraft | null
  /** '' continues with the following step. */
  next: string
  effects: EffectDraft[]
  extra: StoredItem
}

/** What a branch, an option or `otherwise` does: continue elsewhere and apply effects. */
export interface OutcomeDraft {
  /** '' continues with the following step. */
  next: string
  effects: EffectDraft[]
  extra: StoredItem
}

/** The answers an oracle step may branch on. */
export const oracleAnswers = ['yes', 'no', 'exceptionalYes', 'exceptionalNo'] as const
export type OracleAnswer = (typeof oracleAnswers)[number]

/** The outcomes a pick step may branch on. */
export const pickOutcomes = ['found', 'none'] as const
export type PickOutcome = (typeof pickOutcomes)[number]

export const pickModes = ['random', 'choose'] as const
export type PickMode = (typeof pickModes)[number]

/** A check step outcome: the band of the check it is for and what follows it. */
export interface CheckOutcomeDraft extends OutcomeDraft {
  band: string
}

/** A table step branch: the entry it is for and what follows it. */
export interface BranchDraft extends OutcomeDraft {
  entry: string
}

/** A choice option (the publisher's own limits, echoed to disable the buttons). */
export interface OptionDraft extends OutcomeDraft {
  key: string
  label: string
}

export const MAX_BRANCHES = 1000
export const MAX_PICK_COUNT = 10
export const MIN_OPTIONS = 2
export const MAX_OPTIONS = 10

interface StepBase {
  key: string
  title: string
  prompt: string
  tip: string
  mandatory: boolean
  /** A later step key or `end`; '' continues with the following step. */
  next: string
  effects: EffectDraft[]
  extra: StoredItem
}

export type StepDraft =
  | (StepBase & { kind: 'prompt'; inspiration: string[] })
  | (StepBase & { kind: 'character' })
  | (StepBase & { kind: 'roll'; dice: string; bands: BandDraft[] })
  | (StepBase & {
      kind: 'condition'
      source: 'tracker' | 'count'
      tracker: string
      of: CountTarget
      tags: string[]
      /** What the stored `count` carries beyond `of` and `tags`. */
      countExtra: StoredItem
      bands: BandDraft[]
    })
  | (StepBase & {
      kind: 'oracle'
      oracle: string
      /** The likelihood level; '' asks at the oracle's default. */
      level: string
      branches: Record<OracleAnswer, OutcomeDraft>
      /** What the stored `branches` carries beyond the four answers. */
      branchesExtra: StoredItem
    })
  | (StepBase & { kind: 'table'; table: string; branches: BranchDraft[]; otherwise: OutcomeDraft })
  | (StepBase & { kind: 'choice'; options: OptionDraft[]; skip: string })
  | (StepBase & {
      kind: 'pick'
      of: CountTarget
      mode: PickMode
      tags: string[]
      /** The count as text; 1 and 1 is the default and is not saved. */
      min: string
      max: string
      /** What the stored `count` carries beyond `min` and `max`. */
      countExtra: StoredItem
      branches: Record<PickOutcome, OutcomeDraft>
      branchesExtra: StoredItem
    })
  | (StepBase & {
      kind: 'check'
      check: string
      /** Empty text is not saved. */
      difficulty: ValueDraft
      /** As stored: in the order of the check's bands once one is edited, strays (no such band, repeated) kept. */
      outcomes: CheckOutcomeDraft[]
    })
  | { kind: 'stored'; stored: unknown }

export type EditedStep = Exclude<StepDraft, { kind: 'stored' }>

export type StepsResult = { steps: StoredItem[] } | { errors: FieldErrors }

const commonFields = ['key', 'kind', 'title', 'prompt', 'tip', 'mandatory', 'next', 'effects']
const bandFields = ['upTo', 'next', 'effects']
const countFields = ['of', 'tags']
const pickCountFields = ['min', 'max']
const outcomeFields = ['next', 'effects']

/** The forms a band limit and a check difficulty take. */
export const bandForms = ['integer', 'tracker', 'formula'] as const satisfies readonly ValueForm[]

function readBand(stored: unknown): BandDraft | null {
  if (!isObject(stored)) {
    return null
  }
  const upTo = stored.upTo === undefined ? null : readValue(stored.upTo, bandForms)
  const next = textOf(stored, 'next')
  const effects = listOf(stored, 'effects')
  if ((stored.upTo !== undefined && upTo === null) || next === null || effects === null) {
    return null
  }
  return { upTo, next, effects: effectsToDrafts(effects), extra: unknownFields(stored, bandFields) }
}

/** Every band, or null when one of them cannot be read. */
function readBands(stored: StoredItem): BandDraft[] | null {
  const list = listOf(stored, 'bands')
  const bands = (list ?? []).map(readBand)
  return list === null || bands.includes(null) ? null : (bands as BandDraft[])
}

export const emptyOutcome = (): OutcomeDraft => ({ next: '', effects: [], extra: {} })

const isEmptyOutcome = (outcome: OutcomeDraft) =>
  outcome.next === '' && outcome.effects.length === 0 && Object.keys(outcome.extra).length === 0

/** An outcome as form state; `own` are the fields of a branch or option around it. */
function readOutcome(stored: unknown, own: readonly string[] = []): OutcomeDraft | null {
  if (!isObject(stored)) {
    return null
  }
  const next = textOf(stored, 'next')
  const effects = listOf(stored, 'effects')
  return next === null || effects === null
    ? null
    : {
        next,
        effects: effectsToDrafts(effects),
        extra: unknownFields(stored, [...outcomeFields, ...own]),
      }
}

/** The outcomes under a step's `branches` named `names` (an oracle's, a pick's), or null when one cannot be read. */
function readAnswers<Name extends string>(stored: StoredItem, names: readonly Name[]) {
  if (stored.branches !== undefined && !isObject(stored.branches)) {
    return null
  }
  const given = stored.branches ?? {}
  const outcomes = names.map(
    (name) =>
      [name, given[name] === undefined ? emptyOutcome() : readOutcome(given[name])] as const,
  )
  return outcomes.some(([, outcome]) => outcome === null)
    ? null
    : {
        branches: Object.fromEntries(outcomes) as Record<Name, OutcomeDraft>,
        branchesExtra: unknownFields(given, names),
      }
}

function readBranches(stored: StoredItem): BranchDraft[] | null {
  const list = listOf(stored, 'branches')
  if (list === null) {
    return null
  }
  const branches = list.map((branch) => {
    const outcome = readOutcome(branch, ['entry'])
    const entry = isObject(branch) ? branch.entry : undefined
    return outcome === null || typeof entry !== 'string' ? null : { ...outcome, entry }
  })
  return branches.includes(null) ? null : (branches as BranchDraft[])
}

function readOptions(stored: StoredItem): OptionDraft[] | null {
  if (!Array.isArray(stored.options)) {
    return null
  }
  const options = (stored.options as unknown[]).map((option) => {
    const outcome = readOutcome(option, ['key', 'label'])
    const { key, label } = isObject(option) ? option : { key: undefined, label: undefined }
    return outcome === null || typeof key !== 'string' || typeof label !== 'string'
      ? null
      : { ...outcome, key, label }
  })
  return options.includes(null) ? null : (options as OptionDraft[])
}

/** The count of a pick as text; absent or empty is the default 1 to 1. Null when it cannot be read. */
function readPickCount(stored: StoredItem) {
  const { count } = stored
  if (count === undefined) {
    return { min: '1', max: '1', countExtra: {} }
  }
  if (!isObject(count)) {
    return null
  }
  const { min, max } = count
  if (min === undefined && max === undefined) {
    return { min: '1', max: '1', countExtra: unknownFields(count, pickCountFields) }
  }
  return Number.isInteger(min) && Number.isInteger(max)
    ? {
        min: String(min),
        max: String(max),
        countExtra: unknownFields(count, pickCountFields),
      }
    : null
}

function readPick(stored: StoredItem, base: StepBase): StepDraft | null {
  const tags = stringsOf(stored, 'tags')
  const count = readPickCount(stored)
  const answers = readAnswers(stored, pickOutcomes)
  const { of, mode } = stored
  if (
    tags === null ||
    count === null ||
    answers === null ||
    !countTargets.some((target) => target === of) ||
    !pickModes.some((option) => option === mode)
  ) {
    return null
  }
  return {
    ...base,
    kind: 'pick',
    of: of as CountTarget,
    mode: mode as PickMode,
    tags,
    ...count,
    ...answers,
  }
}

function readCheck(stored: StoredItem, base: StepBase): StepDraft | null {
  const list = listOf(stored, 'outcomes')
  const difficulty =
    stored.difficulty === undefined
      ? { form: 'integer' as const, text: '' }
      : readValue(stored.difficulty, bandForms)
  const outcomes = (list ?? []).map((outcome) => {
    const read = readOutcome(outcome, ['band'])
    const band = isObject(outcome) ? outcome.band : undefined
    return read === null || typeof band !== 'string' ? null : { ...read, band }
  })
  return list === null ||
    difficulty === null ||
    outcomes.includes(null) ||
    typeof stored.check !== 'string'
    ? null
    : {
        ...base,
        kind: 'check',
        check: stored.check,
        difficulty,
        outcomes: outcomes as CheckOutcomeDraft[],
      }
}

function readCondition(stored: StoredItem, base: StepBase): StepDraft | null {
  const { tracker, count } = stored
  const bands = readBands(stored)
  if (bands === null || (tracker === undefined) === (count === undefined)) {
    return null
  }
  const blank = { of: 'npc' as const, tags: [], countExtra: {} }
  if (tracker !== undefined) {
    return typeof tracker === 'string'
      ? { ...base, kind: 'condition', source: 'tracker', tracker, ...blank, bands }
      : null
  }
  const tags = isObject(count) ? stringsOf(count, 'tags') : null
  if (!isObject(count) || tags === null || !countTargets.some((of) => of === count.of)) {
    return null
  }
  return {
    ...base,
    kind: 'condition',
    source: 'count',
    tracker: '',
    of: count.of as CountTarget,
    tags,
    countExtra: unknownFields(count, countFields),
    bands,
  }
}

/** The step as form state; a step this editor cannot edit is kept as stored. */
export function stepToDraft(stored: unknown): StepDraft {
  const unreadable: StepDraft = { kind: 'stored', stored }
  if (!isObject(stored) || !stepKinds.some((kind) => kind === stored.kind)) {
    return unreadable
  }
  const key = textOf(stored, 'key')
  const title = textOf(stored, 'title')
  const prompt = textOf(stored, 'prompt')
  const tip = textOf(stored, 'tip')
  const next = textOf(stored, 'next')
  const effects = listOf(stored, 'effects')
  const mandatory = stored.mandatory ?? false
  if ([key, title, prompt, tip, next, effects].includes(null) || typeof mandatory !== 'boolean') {
    return unreadable
  }
  const kind = stored.kind as StepKind
  const known = {
    prompt: [...commonFields, 'inspiration'],
    character: commonFields,
    roll: [...commonFields, 'dice', 'bands'],
    condition: [...commonFields, 'tracker', 'count', 'bands'],
    oracle: [...commonFields, 'oracle', 'likelihood', 'branches'],
    table: [...commonFields, 'table', 'branches', 'otherwise'],
    choice: [...commonFields, 'options', 'skip'],
    pick: [...commonFields, 'of', 'tags', 'mode', 'count', 'branches'],
    check: [...commonFields, 'check', 'difficulty', 'outcomes'],
  }[kind]
  const base: StepBase = {
    key: key ?? '',
    title: title ?? '',
    prompt: prompt ?? '',
    tip: tip ?? '',
    mandatory,
    next: next ?? '',
    effects: effectsToDrafts(effects ?? []),
    extra: unknownFields(stored, known),
  }
  switch (kind) {
    case 'prompt': {
      const inspiration = stringsOf(stored, 'inspiration')
      return inspiration === null ? unreadable : { ...base, kind, inspiration }
    }
    case 'character':
      return { ...base, kind }
    case 'roll': {
      const bands = readBands(stored)
      const dice = textOf(stored, 'dice')
      return bands === null || dice === null ? unreadable : { ...base, kind, dice, bands }
    }
    case 'condition':
      return readCondition(stored, base) ?? unreadable
    case 'oracle': {
      const answers = readAnswers(stored, oracleAnswers)
      const level = textOf(stored, 'likelihood')
      return answers === null || level === null || typeof stored.oracle !== 'string'
        ? unreadable
        : { ...base, kind, oracle: stored.oracle, level, ...answers }
    }
    case 'table': {
      const branches = readBranches(stored)
      const otherwise =
        stored.otherwise === undefined ? emptyOutcome() : readOutcome(stored.otherwise)
      return branches === null || otherwise === null || typeof stored.table !== 'string'
        ? unreadable
        : { ...base, kind, table: stored.table, branches, otherwise }
    }
    case 'choice': {
      const options = readOptions(stored)
      const skip = textOf(stored, 'skip')
      return options === null || skip === null ? unreadable : { ...base, kind, options, skip }
    }
    case 'pick':
      return readPick(stored, base) ?? unreadable
    case 'check':
      return readCheck(stored, base) ?? unreadable
  }
}

export const stepsToDrafts = (stored: unknown[]): StepDraft[] => stored.map(stepToDraft)

/** The smallest `kind-n` key no step of the list has. */
function freeKey(kind: StepKind, steps: StepDraft[]): string {
  const taken = steps.map(stepKey)
  let n = 1
  while (taken.includes(`${kind}-${String(n)}`)) {
    n += 1
  }
  return `${kind}-${String(n)}`
}

export function newBand(): BandDraft {
  return { upTo: { form: 'integer', text: '' }, next: '', effects: [], extra: {} }
}

export const newBranch = (): BranchDraft => ({ entry: '', ...emptyOutcome() })

/** An option with the smallest free `option-n` key and no label yet. */
export function newOption(options: OptionDraft[]): OptionDraft {
  let n = options.length + 1
  while (options.some((option) => option.key === `option-${String(n)}`)) {
    n += 1
  }
  return { key: `option-${String(n)}`, label: '', ...emptyOutcome() }
}

export function newStep(kind: StepKind, steps: StepDraft[]): EditedStep {
  const base: StepBase = {
    key: freeKey(kind, steps),
    title: '',
    prompt: '',
    tip: '',
    mandatory: false,
    next: '',
    effects: [],
    extra: {},
  }
  const lastBand: BandDraft = { ...newBand(), upTo: null }
  switch (kind) {
    case 'prompt':
      return { ...base, kind, inspiration: [] }
    case 'character':
      return { ...base, kind }
    case 'roll':
      return { ...base, kind, dice: '1d6', bands: [] }
    case 'condition':
      return {
        ...base,
        kind,
        source: 'tracker',
        tracker: '',
        of: 'npc',
        tags: [],
        countExtra: {},
        bands: [lastBand],
      }
    case 'oracle':
      return {
        ...base,
        kind,
        oracle: '',
        level: '',
        branches: {
          yes: emptyOutcome(),
          no: emptyOutcome(),
          exceptionalYes: emptyOutcome(),
          exceptionalNo: emptyOutcome(),
        },
        branchesExtra: {},
      }
    case 'table':
      return { ...base, kind, table: '', branches: [], otherwise: emptyOutcome() }
    case 'choice': {
      const first = newOption([])
      return { ...base, kind, options: [first, newOption([first])], skip: '' }
    }
    case 'pick':
      return {
        ...base,
        kind,
        of: 'npc',
        mode: 'random',
        tags: [],
        min: '1',
        max: '1',
        countExtra: {},
        branches: { found: emptyOutcome(), none: emptyOutcome() },
        branchesExtra: {},
      }
    case 'check':
      return { ...base, kind, check: '', difficulty: { form: 'integer', text: '' }, outcomes: [] }
  }
}

/** The key of a step, '' when a stored step has none. */
export function stepKey(step: StepDraft): string {
  const key = step.kind === 'stored' && isObject(step.stored) ? step.stored.key : undefined
  return step.kind === 'stored' ? (typeof key === 'string' ? key : '') : step.key
}

/**
 * The outcomes with the one of `band` set to `outcome` (added when the band had none), in the order
 * of the check's `bands`; outcomes for no band of it keep their order, last. `origin` says where each
 * had been (null: new), so messages about outcomes follow them.
 */
export function setBandOutcome(
  outcomes: CheckOutcomeDraft[],
  bands: string[],
  band: string,
  outcome: OutcomeDraft,
) {
  const entries: { outcome: CheckOutcomeDraft; from: number | null }[] = outcomes.map(
    (current, from) => ({ outcome: current, from }),
  )
  const at = outcomes.findIndex((current) => current.band === band)
  const edited = { ...outcome, band }
  if (at === -1) {
    entries.push({ outcome: edited, from: null })
  } else {
    entries[at] = { outcome: edited, from: at }
  }
  const rank = (name: string) => (bands.includes(name) ? bands.indexOf(name) : bands.length)
  entries.sort((a, b) => rank(a.outcome.band) - rank(b.outcome.band))
  return { outcomes: entries.map((entry) => entry.outcome), origin: entries.map((e) => e.from) }
}

/**
 * The limit stays with the band whatever its position: a band that became the last one keeps what
 * it had (the guides flag it, the publisher decides), and a band before the last with none is
 * refused here instead of being saved without a limit.
 */
function bandToStored(band: BandDraft, last: boolean, path: string, errors: FieldErrors) {
  if (band.upTo === null && !last) {
    errors[`${path}.upTo`] = 'Set a limit: only the last band catches the rest.'
  }
  return {
    ...band.extra,
    ...(band.upTo === null ? {} : { upTo: valueToStored(band.upTo, `${path}.upTo`, errors) }),
    ...(band.next === '' ? {} : { next: band.next }),
    ...(band.effects.length === 0
      ? {}
      : { effects: effectsToStored(band.effects, `${path}.effects`, errors) }),
  }
}

function outcomeToStored(outcome: OutcomeDraft, path: string, errors: FieldErrors) {
  return {
    ...outcome.extra,
    ...(outcome.next === '' ? {} : { next: outcome.next }),
    ...(outcome.effects.length === 0
      ? {}
      : { effects: effectsToStored(outcome.effects, `${path}.effects`, errors) }),
  }
}

/** The non-empty outcomes of an oracle or pick under their names, and what else `branches` held. */
function answersToStored<Name extends string>(
  step: { branches: Record<Name, OutcomeDraft>; branchesExtra: StoredItem },
  names: readonly Name[],
  index: number,
  errors: FieldErrors,
) {
  return {
    ...step.branchesExtra,
    ...Object.fromEntries(
      names
        .filter((name) => !isEmptyOutcome(step.branches[name]))
        .map((name) => [
          name,
          outcomeToStored(step.branches[name], `${String(index)}.branches.${name}`, errors),
        ]),
    ),
  }
}

/** The `count` to save, null for the default 1 to 1 with nothing else in it. */
function pickCountToStored(
  step: Extract<EditedStep, { kind: 'pick' }>,
  index: number,
  errors: FieldErrors,
) {
  const min = parseInteger(step.min.trim())
  const max = parseInteger(step.max.trim())
  if (min === null) {
    errors[`${String(index)}.count.min`] = WHOLE
  }
  if (max === null) {
    errors[`${String(index)}.count.max`] = WHOLE
  }
  if (min === 1 && max === 1) {
    return Object.keys(step.countExtra).length === 0 ? null : step.countExtra
  }
  return { ...step.countExtra, min: min ?? 0, max: max ?? 0 }
}

function stepToStored(step: EditedStep, index: number, errors: FieldErrors): StoredItem {
  const at = `${String(index)}.bands`
  const bandsOf = (bands: BandDraft[]) =>
    bands.map((band, position) =>
      bandToStored(band, position === bands.length - 1, `${at}.${String(position)}`, errors),
    )
  const item: StoredItem = {
    ...step.extra,
    key: step.key.trim(),
    kind: step.kind,
    title: step.title,
    ...(step.prompt === '' ? {} : { prompt: step.prompt }),
    ...(step.tip === '' ? {} : { tip: step.tip }),
    ...(step.mandatory && step.kind !== 'condition' ? { mandatory: true } : {}),
    ...(step.next === '' ? {} : { next: step.next }),
    ...(step.effects.length === 0
      ? {}
      : { effects: effectsToStored(step.effects, `${String(index)}.effects`, errors) }),
  }
  switch (step.kind) {
    case 'prompt':
      if (step.inspiration.length > 0) {
        item.inspiration = step.inspiration
      }
      break
    case 'roll':
      item.dice = step.dice.trim()
      if (step.bands.length > 0) {
        item.bands = bandsOf(step.bands)
      }
      break
    case 'condition':
      if (step.source === 'tracker') {
        item.tracker = step.tracker
      } else {
        item.count = {
          ...step.countExtra,
          of: step.of,
          ...(step.tags.length === 0 ? {} : { tags: step.tags }),
        }
      }
      item.bands = bandsOf(step.bands)
      break
    case 'oracle': {
      item.oracle = step.oracle
      if (step.level !== '') {
        item.likelihood = step.level
      }
      const branches = answersToStored(step, oracleAnswers, index, errors)
      if (Object.keys(branches).length > 0) {
        item.branches = branches
      }
      break
    }
    case 'pick': {
      item.of = step.of
      item.mode = step.mode
      if (step.tags.length > 0) {
        item.tags = step.tags
      }
      const count = pickCountToStored(step, index, errors)
      if (count !== null) {
        item.count = count
      }
      const branches = answersToStored(step, pickOutcomes, index, errors)
      if (Object.keys(branches).length > 0) {
        item.branches = branches
      }
      break
    }
    case 'check': {
      item.check = step.check
      if (step.difficulty.text.trim() !== '') {
        item.difficulty = valueToStored(step.difficulty, `${String(index)}.difficulty`, errors)
      }
      const outcomes = step.outcomes.flatMap(({ band, ...outcome }, position) =>
        isEmptyOutcome(outcome)
          ? []
          : [
              {
                ...outcomeToStored(
                  outcome,
                  `${String(index)}.outcomes.${String(position)}`,
                  errors,
                ),
                band,
              },
            ],
      )
      if (outcomes.length > 0) {
        item.outcomes = outcomes
      }
      break
    }
    case 'table':
      item.table = step.table
      if (step.branches.length > 0) {
        item.branches = step.branches.map(({ entry, ...outcome }, position) => ({
          ...outcomeToStored(outcome, `${String(index)}.branches.${String(position)}`, errors),
          entry,
        }))
      }
      if (!isEmptyOutcome(step.otherwise)) {
        item.otherwise = outcomeToStored(step.otherwise, `${String(index)}.otherwise`, errors)
      }
      break
    case 'choice':
      item.options = step.options.map(({ key, label, ...outcome }, position) => ({
        ...outcomeToStored(outcome, `${String(index)}.options.${String(position)}`, errors),
        key,
        label,
      }))
      if (step.skip !== '') {
        item.skip = step.skip
      }
      break
    case 'character':
      break
  }
  return item
}

/**
 * The steps to save, or the whole numbers that are not (keyed `<step>.bands.<band>.upTo`). A stored
 * step goes back exactly as it came.
 */
export function stepsToItems(steps: StepDraft[]): StepsResult {
  const errors: FieldErrors = {}
  const items = steps.map((step, index) =>
    step.kind === 'stored' ? (step.stored as StoredItem) : stepToStored(step, index, errors),
  )
  return Object.keys(errors).length > 0 ? { errors } : { steps: items }
}

export interface StepGuide {
  key?: string
  next?: string
  bands: Record<number, { upTo?: string; next?: string }>
  /** An oracle step's level the chosen oracle does not have. */
  level?: string
  /** A choice's `skip`. */
  skip?: string
  /** A pick's count. */
  count?: string
  /** A check step's check the game system does not have. */
  check?: string
  /** A check step's difficulty the chosen check does not allow. */
  difficulty?: string
  /** Per outcome (an answer, `otherwise`, a branch or option position): a `next` that goes backward. */
  nexts: Record<string, string>
  /** Per table branch (its entry), choice option (its key) or check outcome (its band). */
  items: Record<number, string>
}

const backward = 'Pick a later step: next only goes forward.'

/** A difficulty range as text; an open end is said so. */
export const rangeOf = (range: { min: number | null; max: number | null }) =>
  `${range.min === null ? 'no minimum' : String(range.min)} to ${range.max === null ? 'no maximum' : String(range.max)}`

/**
 * Client guides, nothing more: the publisher checks the same rules and stays the authority, so
 * none of them blocks a save. A `next` that is the same or an earlier key (after a move, say), a
 * repeated or reserved key, literal `upTo` values that do not increase and a limit on the last band;
 * with the pickers' `context`, a level or entry the chosen oracle or table does not have; and for
 * a choice, repeated option keys, a `skip` naming no option and a suggested choice without `skip`.
 */
export function stepGuides(steps: StepDraft[], context?: StepContext): StepGuide[] {
  const keys = steps.map(stepKey)
  const misplaced = (next: string, index: number) =>
    next !== '' && next !== END && keys.includes(next) && keys.indexOf(next) <= index
  return steps.map((step, index) => {
    const guide: StepGuide = { bands: {}, nexts: {}, items: {} }
    const key = keys[index] ?? ''
    if (key === END) {
      guide.key = 'end is reserved for the end of the list.'
    } else if (key !== '' && keys.indexOf(key) !== index) {
      guide.key = 'Another step has this key.'
    }
    if (step.kind === 'stored') {
      return guide
    }
    if (misplaced(step.next, index)) {
      guide.next = backward
    }
    const outcome = (id: string, { next }: OutcomeDraft) => {
      if (misplaced(next, index)) {
        guide.nexts[id] = backward
      }
    }
    if (step.kind === 'roll' || step.kind === 'condition') {
      let previous: number | null = null
      step.bands.forEach((band, position) => {
        const literal = band.upTo?.form === 'integer' ? parseInteger(band.upTo.text.trim()) : null
        const flags: { upTo?: string; next?: string } = {}
        if (literal !== null && previous !== null && literal <= previous) {
          flags.upTo = 'Must be above the previous limit.'
        }
        if (band.upTo !== null && position === step.bands.length - 1) {
          flags.upTo = 'The last band catches the rest: remove its limit.'
        }
        if (literal !== null) {
          previous = literal
        }
        if (misplaced(band.next, index)) {
          flags.next = backward
        }
        if (Object.keys(flags).length > 0) {
          guide.bands[position] = flags
        }
      })
    }
    if (step.kind === 'oracle') {
      oracleAnswers.forEach((answer) => {
        outcome(answer, step.branches[answer])
      })
      const levels = context?.likelihood.find((oracle) => oracle.key === step.oracle)?.levels ?? []
      if (context && step.level !== '' && !levels.includes(step.level)) {
        guide.level = 'Not a level of this oracle.'
      }
    }
    if (step.kind === 'table') {
      const entries = context?.tables.find((table) => table.key === step.table)?.entries ?? []
      step.branches.forEach((branch, position) => {
        outcome(String(position), branch)
        if (branch.entry === '') {
          return
        }
        if (step.branches.findIndex((other) => other.entry === branch.entry) !== position) {
          guide.items[position] = 'Another branch has this entry.'
        } else if (context && !entries.includes(branch.entry)) {
          guide.items[position] = 'Not an entry of this table.'
        }
      })
      outcome('otherwise', step.otherwise)
    }
    if (step.kind === 'pick') {
      pickOutcomes.forEach((name) => {
        outcome(name, step.branches[name])
      })
      const min = parseInteger(step.min.trim())
      const max = parseInteger(step.max.trim())
      if (min !== null && max !== null && max < min) {
        guide.count = 'Max must be at least min.'
      }
    }
    if (step.kind === 'check') {
      const chosen = context?.checks.find((check) => check.key === step.check)
      if (context && step.check !== '' && chosen === undefined) {
        guide.check = 'Not a check of this game system.'
      }
      const given = step.difficulty.text.trim()
      const literal = step.difficulty.form === 'integer' ? parseInteger(given) : null
      const range = chosen?.difficulty
      if (context && chosen !== undefined && range === null && given !== '') {
        guide.difficulty = 'This check declares no difficulty.'
      } else if (
        literal !== null &&
        range &&
        ((range.min !== null && literal < range.min) || (range.max !== null && literal > range.max))
      ) {
        guide.difficulty = `Outside the range of this check: ${rangeOf(range)}.`
      }
      step.outcomes.forEach((entry, position) => {
        outcome(String(position), entry)
        if (step.outcomes.findIndex((other) => other.band === entry.band) !== position) {
          guide.items[position] = 'Another outcome has this band.'
        } else if (context && !chosen?.bands.includes(entry.band)) {
          guide.items[position] = 'Not a band of this check.'
        }
      })
    }
    if (step.kind === 'choice') {
      const optionKeys = step.options.map((option) => option.key)
      step.options.forEach((option, position) => {
        outcome(String(position), option)
        if (optionKeys.indexOf(option.key) !== position) {
          guide.items[position] = 'Another option has this key.'
        }
      })
      if (step.skip !== '' && !optionKeys.includes(step.skip)) {
        guide.skip = 'No option has this key.'
      } else if (step.skip === '' && !step.mandatory) {
        guide.skip = 'A suggested choice must name the option taken when it is skipped.'
      }
    }
    return guide
  })
}
