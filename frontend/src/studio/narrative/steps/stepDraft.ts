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
  'shop',
] as const
export type StepKind = (typeof stepKinds)[number]

/** How a shop's `stock` is written: absent, a whole number or a dice expression (ADR 0045). */
export const stockForms = ['none', 'fixed', 'dice'] as const
export type StockForm = (typeof stockForms)[number]

export const countTargets = ['npc', 'thread', 'character'] as const
export type CountTarget = (typeof countTargets)[number]

export interface BandDraft {
  /** Null for the last band, which catches the rest. */
  upTo: ValueDraft | null
  /** Names the outcome of the band; '' leaves it unnamed (the field is not sent). */
  label: string
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

/** The special results a roll can have (ADR 0044), in the order their outcomes are written. */
export const checkSpecials = ['critical', 'messyCritical', 'bestialFailure'] as const
export type CheckSpecial = (typeof checkSpecials)[number]

/** A check step outcome for a band of the check, and what follows it. */
export interface BandOutcomeDraft extends OutcomeDraft {
  band: string
}

/** A check step outcome for a special result, which replaces the band's, and what follows it. */
export interface SpecialOutcomeDraft extends OutcomeDraft {
  special: CheckSpecial
}

/** A check step outcome: for a band of the check or for a special result. */
export type CheckOutcomeDraft = BandOutcomeDraft | SpecialOutcomeDraft

export const isSpecialOutcome = (outcome: CheckOutcomeDraft): outcome is SpecialOutcomeDraft =>
  'special' in outcome

/** A table step branch: the entry it is for and what follows it. */
export interface BranchDraft extends OutcomeDraft {
  entry: string
}

/** A choice option (the publisher's own limits, echoed to disable the buttons). */
export interface OptionDraft extends OutcomeDraft {
  key: string
  label: string
}

/** A table step's table for one Scene Type (ADR 0042); the pair is read as typed, '' while a select is unset. */
export interface SceneTableDraft {
  sceneType: string
  table: string
}

export const MAX_BRANCHES = 1000
export const MIN_CHECKS = 2
export const MAX_CHECKS = 10
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
  | (StepBase & {
      kind: 'table'
      table: string
      /** The table each Scene Type rolls instead; null when the step has no map. A map has no branches. */
      bySceneType: SceneTableDraft[] | null
      branches: BranchDraft[]
      otherwise: OutcomeDraft
    })
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
      /** The one check; '' while the step offers a set. */
      check: string
      /** The checks the player picks from (2 to 10 sharing their band keys); null for a single check. */
      checks: string[] | null
      /** Empty text is not saved. */
      difficulty: ValueDraft
      /** As stored: in the order of the check's bands once one is edited, strays (no such band, repeated) kept. */
      outcomes: CheckOutcomeDraft[]
    })
  | (StepBase & {
      kind: 'shop'
      /** The offered catalog keys. */
      gear: string[]
      /** The offered categories. */
      categories: string[]
      /** What the stored `offer` carries beyond `gear` and `categories`. */
      offerExtra: StoredItem
      stockForm: StockForm
      /** The whole number or dice expression, as text; unused while the form is `none`. */
      stock: string
      /** The percent of the cost a sale pays; '' means the shop does not buy. */
      sellRate: string
    })
  | { kind: 'stored'; stored: unknown }

export type EditedStep = Exclude<StepDraft, { kind: 'stored' }>

export type StepsResult = { steps: StoredItem[] } | { errors: FieldErrors }

const commonFields = ['key', 'kind', 'title', 'prompt', 'tip', 'mandatory', 'next', 'effects']
const bandFields = ['upTo', 'label', 'next', 'effects']
const countFields = ['of', 'tags']
const pickCountFields = ['min', 'max']
const offerFields = ['gear', 'categories']
const outcomeFields = ['next', 'effects']

/** The forms a band limit and a check difficulty take. */
export const bandForms = ['integer', 'tracker', 'formula'] as const satisfies readonly ValueForm[]

function readBand(stored: unknown): BandDraft | null {
  // An empty band is `{}` in the release, but the server decodes stored content to arrays and sends it back as `[]`.
  if (Array.isArray(stored) && stored.length === 0) {
    return readBand({})
  }
  if (!isObject(stored)) {
    return null
  }
  const upTo = stored.upTo === undefined ? null : readValue(stored.upTo, bandForms)
  const label = textOf(stored, 'label')
  const next = textOf(stored, 'next')
  const effects = listOf(stored, 'effects')
  if (
    (stored.upTo !== undefined && upTo === null) ||
    label === null ||
    next === null ||
    effects === null
  ) {
    return null
  }
  return {
    upTo,
    label,
    next,
    effects: effectsToDrafts(effects),
    extra: unknownFields(stored, bandFields),
  }
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

/** The table by Scene Type as rows (null: none stored); undefined when it cannot be read. */
function readSceneTables(stored: StoredItem): SceneTableDraft[] | null | undefined {
  const map = stored.tableBySceneType
  if (map === undefined) {
    return null
  }
  return isObject(map) && Object.values(map).every((table) => typeof table === 'string')
    ? Object.entries(map).map(([sceneType, table]) => ({ sceneType, table: table as string }))
    : undefined
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
  // A step names one check or a set, never both: anything else is the publisher's to refuse, kept as stored.
  const set = stored.checks === undefined ? null : stringsOf(stored, 'checks')
  if (stored.checks !== undefined && (set === null || stored.check !== undefined)) {
    return null
  }
  const difficulty =
    stored.difficulty === undefined
      ? { form: 'integer' as const, text: '' }
      : readValue(stored.difficulty, bandForms)
  // An entry names exactly one of band and special; anything else is the publisher's to refuse.
  const outcomes = (list ?? []).map((outcome): CheckOutcomeDraft | null => {
    const read = readOutcome(outcome, ['band', 'special'])
    const band = isObject(outcome) ? outcome.band : undefined
    const special = isObject(outcome) ? outcome.special : undefined
    if (read === null || (band === undefined) === (special === undefined)) {
      return null
    }
    if (typeof band === 'string') {
      return { ...read, band }
    }
    return checkSpecials.find((name) => name === special) === undefined
      ? null
      : { ...read, special: special as CheckSpecial }
  })
  return list === null ||
    difficulty === null ||
    outcomes.includes(null) ||
    (set === null && typeof stored.check !== 'string')
    ? null
    : {
        ...base,
        kind: 'check',
        check: set === null ? (stored.check as string) : '',
        checks: set,
        difficulty,
        outcomes: outcomes as CheckOutcomeDraft[],
      }
}

/** The shop's stock as a form and text; null when it is neither a whole number nor `{dice: string}`. */
function readStock(stored: StoredItem): { stockForm: StockForm; stock: string } | null {
  const { stock } = stored
  if (stock === undefined) {
    return { stockForm: 'none', stock: '' }
  }
  if (typeof stock === 'number') {
    return Number.isInteger(stock) ? { stockForm: 'fixed', stock: String(stock) } : null
  }
  return isObject(stock) && Object.keys(stock).length === 1 && typeof stock.dice === 'string'
    ? { stockForm: 'dice', stock: stock.dice }
    : null
}

function readShop(stored: StoredItem, base: StepBase): StepDraft | null {
  const offer = stored.offer === undefined ? {} : stored.offer
  const stock = readStock(stored)
  const sellRate = stored.sellRate
  if (
    !isObject(offer) ||
    stock === null ||
    (sellRate !== undefined && !Number.isInteger(sellRate))
  ) {
    return null
  }
  const rate = typeof sellRate === 'number' ? String(sellRate) : ''
  const gear = stringsOf(offer, 'gear')
  const categories = stringsOf(offer, 'categories')
  return gear === null || categories === null
    ? null
    : {
        ...base,
        kind: 'shop',
        gear,
        categories,
        offerExtra: unknownFields(offer, offerFields),
        ...stock,
        sellRate: rate,
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
    table: [...commonFields, 'table', 'tableBySceneType', 'branches', 'otherwise'],
    choice: [...commonFields, 'options', 'skip'],
    pick: [...commonFields, 'of', 'tags', 'mode', 'count', 'branches'],
    check: [...commonFields, 'check', 'checks', 'difficulty', 'outcomes'],
    shop: [...commonFields, 'offer', 'stock', 'sellRate'],
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
      const bySceneType = readSceneTables(stored)
      return branches === null ||
        otherwise === null ||
        bySceneType === undefined ||
        typeof stored.table !== 'string'
        ? unreadable
        : { ...base, kind, table: stored.table, bySceneType, branches, otherwise }
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
    case 'shop':
      return readShop(stored, base) ?? unreadable
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
  return { upTo: { form: 'integer', text: '' }, label: '', next: '', effects: [], extra: {} }
}

export const newSceneTable = (): SceneTableDraft => ({ sceneType: '', table: '' })

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
      return {
        ...base,
        kind,
        table: '',
        bySceneType: null,
        branches: [],
        otherwise: emptyOutcome(),
      }
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
      return {
        ...base,
        kind,
        check: '',
        checks: null,
        difficulty: { form: 'integer', text: '' },
        outcomes: [],
      }
    case 'shop':
      return {
        ...base,
        kind,
        gear: [],
        categories: [],
        offerExtra: {},
        stockForm: 'none',
        stock: '',
        sellRate: '',
      }
  }
}

/** The key of a step, '' when a stored step has none. */
export function stepKey(step: StepDraft): string {
  const key = step.kind === 'stored' && isObject(step.stored) ? step.stored.key : undefined
  return step.kind === 'stored' ? (typeof key === 'string' ? key : '') : step.key
}

/**
 * The outcomes with the one of `target` (a band key, or a special result) set to `outcome` (added
 * when it had none), bands first in the order of the check's `bands`, then the special results in
 * their fixed order; outcomes for no band of the check keep their order, last. `origin` says where
 * each had been (null: new), so messages about outcomes follow them.
 */
export function setCheckOutcome(
  outcomes: CheckOutcomeDraft[],
  bands: string[],
  target: { band: string } | { special: CheckSpecial },
  outcome: OutcomeDraft,
) {
  const entries: { outcome: CheckOutcomeDraft; from: number | null }[] = outcomes.map(
    (current, from) => ({ outcome: current, from }),
  )
  const edited: CheckOutcomeDraft = { ...outcome, ...target }
  const at = outcomes.findIndex((current) =>
    'band' in target
      ? !isSpecialOutcome(current) && current.band === target.band
      : isSpecialOutcome(current) && current.special === target.special,
  )
  if (at === -1) {
    entries.push({ outcome: edited, from: null })
  } else {
    entries[at] = { outcome: edited, from: at }
  }
  const rank = (current: CheckOutcomeDraft) => {
    if (isSpecialOutcome(current)) {
      return bands.length + checkSpecials.indexOf(current.special)
    }
    return bands.includes(current.band) ? bands.indexOf(current.band) : bands.length + 3
  }
  entries.sort((a, b) => rank(a.outcome) - rank(b.outcome))
  return { outcomes: entries.map((entry) => entry.outcome), origin: entries.map((e) => e.from) }
}

/** The outcomes with the one of `band` set to `outcome`, as {@link setCheckOutcome}. */
export const setBandOutcome = (
  outcomes: CheckOutcomeDraft[],
  bands: string[],
  band: string,
  outcome: OutcomeDraft,
) => setCheckOutcome(outcomes, bands, { band }, outcome)

export const NO_SUCH_SPECIAL = 'This check cannot produce this special result.'

/**
 * The special results every check the step names can produce, in their fixed order; null when the
 * context does not say (a check the pickers lack, or no pool data loaded), so nothing is flagged.
 */
export function producibleSpecials(
  step: Extract<EditedStep, { kind: 'check' }>,
  context: StepContext,
): CheckSpecial[] | null {
  if (checkKeysOf(step).length === 0) {
    return []
  }
  const known = checkKeysOf(step).map(
    (key) => context.checks.find((check) => check.key === key)?.specials,
  )
  if (known.includes(undefined)) {
    return null
  }
  return checkSpecials.filter((name) => known.every((specials) => specials?.includes(name)))
}

/** The checks a check step names, in order: its one check, or its set. */
export function checkKeysOf(step: Extract<EditedStep, { kind: 'check' }>): string[] {
  return step.checks ?? (step.check === '' ? [] : [step.check])
}

/** Whether two checks have the same band keys, in any order. */
export const sameBands = (a: string[], b: string[]) =>
  a.length === b.length && a.every((x) => b.includes(x))

/**
 * What is wrong with a set of checks, if anything: a size outside 2 to 10 or a repeat (always), and
 * with the pickers' `context` a check the game system lacks or one with other band keys than the
 * first, since a set shares one difficulty and one outcome per band.
 */
export function checkSetProblem(checks: string[], context?: StepContext): string | undefined {
  if (
    checks.length < MIN_CHECKS ||
    checks.length > MAX_CHECKS ||
    new Set(checks).size !== checks.length
  ) {
    return `A check set needs ${String(MIN_CHECKS)} to ${String(MAX_CHECKS)} distinct checks.`
  }
  if (context === undefined) {
    return undefined
  }
  let first: string[] | undefined
  for (const key of checks) {
    const found = context.checks.find((check) => check.key === key)
    if (found === undefined) {
      return `Not a check of this game system: ${key}.`
    }
    if (first !== undefined && !sameBands(found.bands, first)) {
      return `Check "${key}" has other band keys than the first check of the set.`
    }
    first ??= found.bands
  }
  return undefined
}

/** What is wrong with the rows of a table by Scene Type, if anything. */
function sceneTablesProblem(rows: SceneTableDraft[]): string | undefined {
  if (rows.length === 0) {
    return 'Add a Scene Type, or turn the table by Scene Type off.'
  }
  if (rows.some((row) => row.sceneType === '' || row.table === '')) {
    return 'Pick a Scene Type and a table for every row.'
  }
  return new Set(rows.map((row) => row.sceneType)).size === rows.length
    ? undefined
    : 'Each Scene Type can appear once.'
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
    ...(band.label.trim() === '' ? {} : { label: band.label }),
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
      if (step.checks === null) {
        item.check = step.check
      } else {
        item.checks = step.checks
        const problem = checkSetProblem(step.checks)
        if (problem !== undefined) {
          errors[`${String(index)}.checks`] = problem
        }
      }
      if (step.difficulty.text.trim() !== '') {
        item.difficulty = valueToStored(step.difficulty, `${String(index)}.difficulty`, errors)
      }
      const outcomes = step.outcomes.flatMap((entry, position) => {
        const { band, special, ...outcome } = entry as OutcomeDraft & {
          band?: string
          special?: CheckSpecial
        }
        return isEmptyOutcome(outcome)
          ? []
          : [
              {
                ...outcomeToStored(
                  outcome,
                  `${String(index)}.outcomes.${String(position)}`,
                  errors,
                ),
                ...(special === undefined ? { band } : { special }),
              },
            ]
      })
      if (outcomes.length > 0) {
        item.outcomes = outcomes
      }
      break
    }
    case 'table':
      item.table = step.table
      if (step.bySceneType !== null) {
        item.tableBySceneType = Object.fromEntries(
          step.bySceneType.map(({ sceneType, table }) => [sceneType, table]),
        )
        const problem = sceneTablesProblem(step.bySceneType)
        if (problem !== undefined) {
          errors[`${String(index)}.tableBySceneType`] = problem
        }
        if (step.branches.length > 0) {
          errors[`${String(index)}.branches`] =
            'A table by Scene Type has no branches: delete them or turn the map off.'
        }
      } else if (step.branches.length > 0) {
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
    case 'shop': {
      const offer = {
        ...step.offerExtra,
        ...(step.gear.length === 0 ? {} : { gear: step.gear }),
        ...(step.categories.length === 0 ? {} : { categories: step.categories }),
      }
      if (Object.keys(offer).length > 0) {
        item.offer = offer
      }
      if (step.stockForm === 'fixed') {
        const stock = parseInteger(step.stock.trim())
        if (stock === null) {
          errors[`${String(index)}.stock`] = WHOLE
        }
        item.stock = stock ?? 0
      } else if (step.stockForm === 'dice') {
        item.stock = { dice: step.stock.trim() }
      }
      if (step.sellRate.trim() !== '') {
        const sellRate = parseInteger(step.sellRate.trim())
        if (sellRate === null) {
          errors[`${String(index)}.sellRate`] = WHOLE
        }
        item.sellRate = sellRate ?? 0
      }
      break
    }
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
  /** A check step's set: its size, a repeat, a check the game system lacks or one with other bands. */
  checks?: string
  /** Per table by Scene Type row: a Scene Type or table the game system lacks, a repeated Scene Type. */
  scenes: Record<number, { sceneType?: string; table?: string }>
  /** A check step's difficulty the chosen check does not allow. */
  difficulty?: string
  /** Per outcome (an answer, `otherwise`, a branch or option position): a `next` that goes backward. */
  nexts: Record<string, string>
  /** Per table branch (its entry), choice option (its key) or check outcome (its band). */
  items: Record<number, string>
}

const backward = 'Pick a later step: next only goes forward.'

/** Said when the ranges of a set's checks share no value, so no difficulty suits them all. */
export const DISJOINT_DIFFICULTY =
  'No difficulty fits every check of the set: leave it empty, each check rolls at its own.'

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
    const guide: StepGuide = { bands: {}, scenes: {}, nexts: {}, items: {} }
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
      step.bySceneType?.forEach(({ sceneType, table }, position) => {
        const flags: { sceneType?: string; table?: string } = {}
        if (step.bySceneType?.findIndex((row) => row.sceneType === sceneType) !== position) {
          flags.sceneType = 'Another row has this Scene Type.'
        } else if (
          sceneType !== '' &&
          context?.sceneTypes &&
          !context.sceneTypes.some((candidate) => candidate.key === sceneType)
        ) {
          flags.sceneType = 'Not a Scene Type of this game system.'
        }
        if (
          table !== '' &&
          context &&
          !context.tables.some((candidate) => candidate.key === table)
        ) {
          flags.table = 'Not an oracle table of this game system.'
        }
        if (Object.keys(flags).length > 0) {
          guide.scenes[position] = flags
        }
      })
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
      const keys = checkKeysOf(step)
      const chosen = context?.checks.find((check) => check.key === keys[0])
      if (step.checks !== null) {
        guide.checks = checkSetProblem(step.checks, context)
      } else if (context && step.check !== '' && chosen === undefined) {
        guide.check = 'Not a check of this game system.'
      }
      const given = step.difficulty.text.trim()
      const literal = step.difficulty.form === 'integer' ? parseInteger(given) : null
      if (context && given !== '' && checkDifficulty(step, context).disjoint) {
        guide.difficulty = DISJOINT_DIFFICULTY
      }
      for (const key of keys) {
        const range = context?.checks.find((check) => check.key === key)?.difficulty
        const which = step.checks === null ? 'this check' : `check "${key}"`
        if (context && range === null && given !== '') {
          guide.difficulty ??= `${which.charAt(0).toUpperCase()}${which.slice(1)} declares no difficulty.`
        } else if (
          literal !== null &&
          range &&
          ((range.min !== null && literal < range.min) ||
            (range.max !== null && literal > range.max))
        ) {
          guide.difficulty ??= `Outside the range of ${which}: ${rangeOf(range)}.`
        }
      }
      const producible = context ? producibleSpecials(step, context) : null
      step.outcomes.forEach((entry, position) => {
        outcome(String(position), entry)
        const special = isSpecialOutcome(entry)
        const repeated =
          step.outcomes.findIndex((other) =>
            special
              ? isSpecialOutcome(other) && other.special === entry.special
              : !isSpecialOutcome(other) && other.band === entry.band,
          ) !== position
        if (repeated) {
          guide.items[position] = special
            ? 'Another outcome has this special result.'
            : 'Another outcome has this band.'
        } else if (special) {
          if (producible !== null && !producible.includes(entry.special)) {
            guide.items[position] = NO_SUCH_SPECIAL
          }
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

/**
 * The difficulty the check step's checks allow. For a set, the range every check allows at once
 * (null when one declares none; no default, each check rolls at its own). When the ranges share
 * no value there is no range to show: `disjoint` says so instead of an inverted one. A value the
 * checks do not take stays visible, to be seen and cleared.
 */
export function checkDifficulty(step: Extract<StepDraft, { kind: 'check' }>, context: StepContext) {
  const ranges = checkKeysOf(step).map(
    (key) => context.checks.find((check) => check.key === key)?.difficulty ?? null,
  )
  const [first] = ranges
  const shared =
    step.checks === null || first === null || first === undefined || ranges.includes(null)
      ? first
      : {
          default: null,
          min: bound(ranges, 'min', Math.max),
          max: bound(ranges, 'max', Math.min),
        }
  const disjoint = !!shared && shared.min !== null && shared.max !== null && shared.min > shared.max
  const range = disjoint ? null : (shared ?? null)
  return {
    range,
    disjoint,
    set: step.checks !== null,
    show: ranges.some(Boolean) || step.difficulty.text.trim() !== '',
  }
}

/** The tightest limit among the ranges that have one, null when none does. */
function bound(
  ranges: ({ min: number | null; max: number | null } | null)[],
  end: 'min' | 'max',
  tightest: (...values: number[]) => number,
) {
  const limits = ranges.flatMap((range) => (range?.[end] == null ? [] : [range[end]]))
  return limits.length === 0 ? null : tightest(...limits)
}
