import { BOOK_TITLE } from './paperEdition'
import type { PaperFlow } from './paperFlow'
import {
  type Preset,
  type PresetEffect,
  type PresetSceneType,
  type PresetStep,
  type PresetTracker,
  readPreset,
} from './preset'
import { tableRanges } from './tableRanges'

export interface BackSection {
  heading: 'Setup' | 'Play' | 'Closing'
  lines: string[]
}

export interface SceneCard {
  name: string
  purpose: string
  tips: string
  back: BackSection[]
}

export type DeckCard =
  | { kind: 'entry'; text: string; starts: string | null }
  | { kind: 'finale'; text: string; starts: string; note: string }
  | { kind: 'compartment' }

export interface TableSheet {
  name: string
  die: number
  rollAgainAbove: number | null
  rows: { range: string; text: string; effects: string[] }[]
}

export interface RulesSheet {
  title: string
  question: string
  sections: { heading: string; lines: string[] }[]
  credit: string
}

export interface FlowCards {
  sceneCards: SceneCard[]
  deck: DeckCard[]
  deckBack: string
  rulesSheet: RulesSheet
  tables: TableSheet[]
}

/** Words that only make sense on a screen. A card that holds one was not given paper wording. */
const APP_WORDING = [/\b(Ogami|Studio|app)\b/i, /leave it empty/i]
const PLACEHOLDER = /\{[^}]*\}/

/** The text of a card, or a throw naming where it came from: nothing screen-only reaches paper. */
function guard(text: string, where: string): string {
  if (APP_WORDING.some((pattern) => pattern.test(text))) {
    throw new Error(`${where}: "${text}" names the app. Give it paper wording in the paper file.`)
  }
  if (PLACEHOLDER.test(text)) {
    throw new Error(
      `${where}: "${text}" holds a placeholder. Give it paper wording in the paper file.`,
    )
  }
  return text
}

/** The title of a step as a sentence, so the text after it reads on: "Laid down in time." */
const sentence = (title: string) => (/[.?!:]$/.test(title) ? title : `${title}.`)
const words = (...parts: (string | undefined)[]) => parts.filter((part) => part).join(' ')

/** What a band of a check does: a Tracker change, or a sheet field gained or lost. */
type OutcomeEffect =
  | { kind: 'tracker'; tracker: string; op: string; value: number }
  | { kind: 'sheet'; field: string; op: string; value: number | string }

type Outcomes = { band: string; effects: OutcomeEffect[] }[]

type Rec = Record<string, unknown>

function asRec(value: unknown, at: string): Rec {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new Error(`Preset ${at}: expected an object.`)
  }
  return value as Rec
}

function asList(value: unknown, at: string): unknown[] {
  if (!Array.isArray(value)) {
    throw new Error(`Preset ${at}: expected a list.`)
  }
  return value as unknown[]
}

function asStr(value: unknown, at: string): string {
  if (typeof value !== 'string' || value === '') {
    throw new Error(`Preset ${at}: expected a non-empty string.`)
  }
  return value
}

function asNum(value: unknown, at: string): number {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new Error(`Preset ${at}: expected a number.`)
  }
  return value
}

function readOutcomeEffect(raw: unknown, at: string): OutcomeEffect {
  const o = asRec(raw, at)
  const kind = asStr(o.kind, `${at}.kind`)
  if (kind === 'tracker') {
    return {
      kind,
      tracker: asStr(o.tracker, `${at}.tracker`),
      op: asStr(o.op, `${at}.op`),
      value: asNum(o.value, `${at}.value`),
    }
  }
  if (kind === 'sheet') {
    const dice = typeof o.value === 'object' && o.value !== null
    return {
      kind,
      field: asStr(o.field, `${at}.field`),
      op: asStr(o.op, `${at}.op`),
      value: dice
        ? asStr(asRec(o.value, `${at}.value`).dice, `${at}.value.dice`)
        : asNum(o.value, `${at}.value`),
    }
  }
  throw new Error(`Preset ${at}: a "${kind}" effect in a check outcome has no paper wording.`)
}

/** The bands of every `check` step of the preset file, by `'sceneTypeKey.stepKey'`. */
function readOutcomes(raw: unknown): Map<string, Outcomes> {
  const found = new Map<string, Outcomes>()
  asList(asRec(raw, 'file').sceneTypes, 'file.sceneTypes').forEach((rawType, t) => {
    const type = asRec(rawType, `file.sceneTypes[${String(t)}]`)
    for (const section of ['setup', 'play', 'closing']) {
      asList(type[section], `file.sceneTypes[${String(t)}].${section}`).forEach((rawStep, s) => {
        const at = `file.sceneTypes[${String(t)}].${section}[${String(s)}]`
        const step = asRec(rawStep, at)
        if (step.kind !== 'check') {
          return
        }
        found.set(
          `${asStr(type.key, at)}.${asStr(step.key, at)}`,
          asList(step.outcomes ?? [], `${at}.outcomes`).map((rawOutcome, o) => {
            const here = `${at}.outcomes[${String(o)}]`
            const outcome = asRec(rawOutcome, here)
            return {
              band: asStr(outcome.band, `${here}.band`),
              effects: asList(outcome.effects, `${here}.effects`).map((effect, e) =>
                readOutcomeEffect(effect, `${here}.effects[${String(e)}]`),
              ),
            }
          }),
        )
      })
    }
  })
  return found
}

/** How a card names each band of a check; the wording of a check step must give each of its bands. */
const BAND_LABELS = new Map([
  ['failure', 'Failing:'],
  ['badly', 'Going badly'],
  ['done', 'Done:'],
  ['short', 'Short:'],
  ['wrong', 'Wrong:'],
])

const capitalised = (word: string) => `${word.charAt(0).toUpperCase()}${word.slice(1)}`

/** "-1d6" is "d6" and "+2d4" is "2d4": the dice a card names, without the sign or a lone 1. */
function diceText(dice: string, where: string): { gain: boolean; text: string } {
  const match = /^([+-]?)(\d*)d(\d+)$/.exec(dice)
  if (match === null) {
    throw new Error(`${where}: the dice "${dice}" have no paper wording.`)
  }
  const count = match[2] === '1' || match[2] === '' ? '' : (match[2] ?? '')
  return { gain: match[1] !== '-', text: `${count}d${match[3] ?? ''}` }
}

/** The effect of a band as the tokens a card writes: "Winter +1", "lose d6 Wind", "regain 1 Nerve". */
function outcomeToken(preset: Preset, effect: OutcomeEffect, where: string): string {
  if (effect.kind === 'tracker') {
    const tracker = preset.trackers.find((candidate) => candidate.key === effect.tracker)
    if (tracker === undefined || effect.op !== 'add') {
      throw new Error(
        `${where}: a ${effect.op} effect on "${effect.tracker}" has no paper wording.`,
      )
    }
    return `${tracker.name} ${effect.value < 0 ? '-' : '+'}${String(Math.abs(effect.value))}`
  }
  const field = capitalised(effect.field)
  if (typeof effect.value === 'string') {
    const { gain, text } = diceText(effect.value, where)
    return `${gain === (effect.op === 'add') ? 'regain' : 'lose'} ${text} ${field}`
  }
  if (effect.op !== 'add' && effect.op !== 'subtract') {
    throw new Error(`${where}: a ${effect.op} effect on "${effect.field}" has no paper wording.`)
  }
  const gain = (effect.op === 'add') === effect.value >= 0
  return `${gain ? 'regain' : 'lose'} ${String(Math.abs(effect.value))} ${field}`
}

/** The tokens a stretch of paper wording gives, in the form `outcomeToken` writes them. */
function wordedTokens(text: string): string[] {
  const tracker = [...text.matchAll(/\b([A-Z][a-z]+) ([+-]\d+)\b/g)].map(
    (m) => `${m[1] ?? ''} ${m[2] ?? ''}`,
  )
  const sheet = [...text.matchAll(/\b(lose|regain) (d\d+|\d+d\d+|\d+) ([A-Z][a-z]+)/g)].map(
    (m) => `${m[1] ?? ''} ${m[2] ?? ''} ${m[3] ?? ''}`,
  )
  return [...tracker, ...sheet].sort()
}

/**
 * The paper wording of a check must give every band of the step with the effects the preset
 * gives it, and no band the step does not have: a band that changes fails the build.
 */
function checkOutcomes(preset: Preset, outcomes: Outcomes, wording: string, where: string) {
  const marks = [...BAND_LABELS]
    .map(([band, label]) => ({ band, at: wording.indexOf(label) }))
    .filter((mark) => mark.at >= 0)
    .sort((a, b) => a.at - b.at)
  for (const outcome of outcomes) {
    if (!BAND_LABELS.has(outcome.band)) {
      throw new Error(`${where}: the band "${outcome.band}" has no label on paper.`)
    }
    if (!marks.some((mark) => mark.band === outcome.band)) {
      throw new Error(`${where}: the paper wording does not give the "${outcome.band}" outcome.`)
    }
  }
  marks.forEach((mark, index) => {
    const outcome = outcomes.find((candidate) => candidate.band === mark.band)
    if (outcome === undefined) {
      throw new Error(`${where}: the paper wording gives "${mark.band}", which the check lacks.`)
    }
    const given = wordedTokens(wording.slice(mark.at, marks[index + 1]?.at))
    const expected = outcome.effects.map((effect) => outcomeToken(preset, effect, where)).sort()
    if (given.join('|') !== expected.join('|')) {
      throw new Error(
        `${where}: "${mark.band}" is "${given.join(', ')}" on paper but "${expected.join(', ')}" in the preset.`,
      )
    }
  })
}

/** The line a step puts on a card: its paper wording, or its own text; `null` for no line. */
function stepLine(
  preset: Preset,
  outcomes: Map<string, Outcomes>,
  sceneType: PresetSceneType,
  step: PresetStep,
  paper: PaperFlow,
): string | null {
  const id = `${sceneType.key}.${step.key}`
  if (Object.hasOwn(paper.steps, id)) {
    const wording = paper.steps[id] ?? null
    if (wording !== null && step.kind === 'check') {
      checkOutcomes(preset, outcomes.get(id) ?? [], wording, id)
    }
    return wording === null ? null : guard(wording, id)
  }
  switch (step.kind) {
    case 'prompt':
      return guard(words(sentence(step.title), step.prompt ?? step.tip), id)
    case 'choice':
      return guard(words(sentence(step.title), step.tip, step.options.join(' / ')), id)
    case 'table':
    case 'pick':
      return guard(words(sentence(step.title), step.tip), id)
    default:
      throw new Error(`${id}: a ${step.kind} step needs paper wording in the paper file.`)
  }
}

function sceneCard(
  preset: Preset,
  outcomes: Map<string, Outcomes>,
  sceneType: PresetSceneType,
  paper: PaperFlow,
): SceneCard {
  const sections = [
    ['Setup', sceneType.setup],
    ['Play', sceneType.play],
    ['Closing', sceneType.closing],
  ] as const
  return {
    name: guard(sceneType.name, sceneType.key),
    purpose: guard(sceneType.purpose, sceneType.key),
    tips: guard(paper.tips?.[sceneType.key] ?? sceneType.tips ?? '', `${sceneType.key} tips`),
    back: sections
      .map(([heading, steps]) => ({
        heading,
        lines: steps.flatMap((step) => stepLine(preset, outcomes, sceneType, step, paper) ?? []),
      }))
      .filter((section) => section.lines.length > 0),
  }
}

/** Every paper entry must still match a step or Scene Type of the preset. */
function checkPaperEntries(preset: Preset, paper: PaperFlow) {
  const steps = new Set(
    preset.sceneTypes.flatMap((st) =>
      [...st.setup, ...st.play, ...st.closing].map((step) => `${st.key}.${step.key}`),
    ),
  )
  for (const id of Object.keys(paper.steps)) {
    if (!steps.has(id)) {
      throw new Error(`The paper file has wording for ${id}, which is not a step of the preset.`)
    }
  }
  const keys = new Set(preset.sceneTypes.map((st) => st.key))
  for (const key of Object.keys(paper.tips ?? {})) {
    if (!keys.has(key)) {
      throw new Error(
        `The paper file has tips for "${key}", which is not a Scene Type of the preset.`,
      )
    }
  }
}

function sceneName(preset: Preset, key: string, where: string): string {
  const found = preset.sceneTypes.find((st) => st.key === key)
  if (found === undefined) {
    throw new Error(`${where} names the Scene Type "${key}", which the preset does not have.`)
  }
  return found.name
}

function deckCards(preset: Preset, paper: PaperFlow): { cards: DeckCard[]; back: string } {
  const deck = paper.deck
  if (deck.kind === 'compartments') {
    return {
      cards: Array.from({ length: deck.count }, () => ({ kind: 'compartment' })),
      back: deck.back,
    }
  }
  const table = preset.tables.find((candidate) => candidate.key === deck.table)
  if (table === undefined) {
    throw new Error(`The deck table "${deck.table}" is not a table of the preset.`)
  }
  tableRanges(table.entries.map((entry) => entry.weight ?? 1))
  for (const entry of table.entries) {
    if (entry.effects.length > 0) {
      throw new Error(
        `The deck table "${table.key}" has an entry with effects, which a card cannot print: "${entry.text}".`,
      )
    }
  }
  const cards = table.entries.flatMap((entry) =>
    Array.from({ length: entry.weight ?? 1 }, (): DeckCard => ({
      kind: 'entry',
      text: guard(entry.text, table.key),
      starts: entry.sceneType === undefined ? null : sceneName(preset, entry.sceneType, table.key),
    })),
  )
  const finale = preset.sceneTypes.find((st) => st.key === deck.finale.sceneType)
  if (finale === undefined) {
    throw new Error(`The finale Scene Type "${deck.finale.sceneType}" is not in the preset.`)
  }
  cards.push({
    kind: 'finale',
    text: guard(`${finale.name}: ${finale.purpose}`, finale.key),
    starts: finale.name,
    note: guard(deck.finale.note, `${finale.key} finale`),
  })
  return { cards, back: guard(deck.back, 'deck back') }
}

function effectText(preset: Preset, effect: PresetEffect, where: string): string {
  if (effect.kind === 'tracker') {
    const tracker = preset.trackers.find((candidate) => candidate.key === effect.tracker)
    if (tracker === undefined || effect.op !== 'add') {
      throw new Error(
        `${where}: a ${effect.op} effect on "${effect.tracker}" has no paper wording.`,
      )
    }
    return `${tracker.name} ${effect.value < 0 ? '-' : '+'}${String(Math.abs(effect.value))}`
  }
  if (effect.kind === 'nextScene') {
    return `Next scene: ${sceneName(preset, effect.sceneType, where)}`
  }
  if (effect.kind === 'createThread') {
    return `Start a Thread: ${effect.name}`
  }
  throw new Error(`${where}: a ${effect.name} effect has no paper wording.`)
}

/** The tables of the flow to print: all but the likelihood oracle and the deck. */
function tableSheets(preset: Preset, oracles: string[], deckKey: string | undefined): TableSheet[] {
  return oracles
    .filter((key) => key !== deckKey && !preset.likelihoods.includes(key))
    .map((key) => {
      const table = preset.tables.find((candidate) => candidate.key === key)
      if (table === undefined) {
        throw new Error(`The flow's oracle "${key}" is neither a table nor a likelihood oracle.`)
      }
      const { die, rollAgainAbove, ranges } = tableRanges(table.entries.map((e) => e.weight ?? 1))
      return {
        name: guard(table.name, key),
        die,
        rollAgainAbove,
        rows: table.entries.map((entry, index) => ({
          range: ranges[index] ?? '',
          text: guard(entry.text, key),
          effects: entry.effects.map((effect) => guard(effectText(preset, effect, key), key)),
        })),
      }
    })
}

function trackerLine(tracker: PresetTracker): string {
  if (tracker.kind === 'clock') {
    return `${tracker.name}: a clock of ${String(tracker.size)} boxes. ${tracker.hint}`
  }
  let from = tracker.min
  const stretches = tracker.levels.map((level) => {
    const to = level.upTo ?? tracker.size
    const span = from === to ? String(from) : `${String(from)}-${String(to)}`
    from = to + 1
    return `${level.label} ${span}`
  })
  const named = stretches.length > 0 ? ` (${stretches.join(', ')})` : ''
  return `${tracker.name}: a counter from ${String(tracker.min)} to ${String(tracker.size)}${named}. ${tracker.hint}`
}

function rulesSheet(preset: Preset, flow: Preset['flows'][number], paper: PaperFlow): RulesSheet {
  for (const key of Object.keys(paper.rules.slots)) {
    if (!preset.factSlots.some((slot) => slot.key === key)) {
      throw new Error(
        `The paper file describes the Fact slot "${key}", which the preset does not have.`,
      )
    }
  }
  const trackers = flow.trackers.map((key) => {
    const tracker = preset.trackers.find((candidate) => candidate.key === key)
    if (tracker === undefined) {
      throw new Error(`The flow uses the Tracker "${key}", which the preset does not have.`)
    }
    return trackerLine(tracker)
  })
  const slots = preset.factSlots.map((slot) => {
    const wording = paper.rules.slots[slot.key]
    if (wording === undefined) {
      throw new Error(`The paper file does not say what the Fact slot "${slot.key}" is on paper.`)
    }
    return `${slot.label}: ${wording}`
  })
  const sections = [
    { heading: 'Trackers', lines: trackers },
    { heading: 'Fact slots', lines: slots },
    ...paper.rules.sections.map(({ heading, lines }) => ({ heading, lines: [...lines] })),
  ]
  for (const { heading, lines } of sections) {
    lines.forEach((line) => guard(line, `rules sheet, ${heading}`))
  }
  return {
    title: flow.name,
    question: guard(flow.description, 'flow description'),
    sections,
    credit: guard(
      `${flow.name} is a flow for ${preset.systemName}, an original game written for ${BOOK_TITLE}. ${preset.license}.`,
      'credit',
    ),
  }
}

/**
 * Everything printed for one flow, from its preset and its paper file. Any drift between the two,
 * a screen-only word on a card, or a malformed preset throws instead of printing a wrong card.
 */
export function buildFlowCards(raw: unknown, paper: PaperFlow): FlowCards {
  const preset = readPreset(raw)
  const flow = preset.flows.find((candidate) => candidate.key === paper.flow)
  if (flow === undefined) {
    throw new Error(`The preset has no flow "${paper.flow}".`)
  }
  checkPaperEntries(preset, paper)
  const outcomes = readOutcomes(raw)
  const deck = deckCards(preset, paper)
  return {
    sceneCards: preset.sceneTypes.map((sceneType) => sceneCard(preset, outcomes, sceneType, paper)),
    deck: deck.cards,
    deckBack: deck.back,
    rulesSheet: rulesSheet(preset, flow, paper),
    tables: tableSheets(
      preset,
      flow.oracles,
      paper.deck.kind === 'table' ? paper.deck.table : undefined,
    ),
  }
}
