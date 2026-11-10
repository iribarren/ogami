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

/** The line a step puts on a card: its paper wording, or its own text; `null` for no line. */
function stepLine(sceneType: PresetSceneType, step: PresetStep, paper: PaperFlow): string | null {
  const id = `${sceneType.key}.${step.key}`
  if (Object.hasOwn(paper.steps, id)) {
    const wording = paper.steps[id] ?? null
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

function sceneCard(sceneType: PresetSceneType, paper: PaperFlow): SceneCard {
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
        lines: steps.flatMap((step) => stepLine(sceneType, step, paper) ?? []),
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
  const deck = deckCards(preset, paper)
  return {
    sceneCards: preset.sceneTypes.map((sceneType) => sceneCard(sceneType, paper)),
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
