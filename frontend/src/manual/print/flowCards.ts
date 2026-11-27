import { buildCards, type DeckEntryCard, type SceneCard } from '@/shared/print/cards'
import {
  type EntryEffect,
  type Release,
  type ReleaseFlow,
  type ReleaseTracker,
  type StepOutcome,
  readRelease,
} from '@/shared/print/release'
import { BAND_LABELS, outcomeToken } from '@/shared/print/wording'

import { BOOK_TITLE } from './paperEdition'
import type { PaperFlow } from './paperFlow'
import { tableRanges } from './tableRanges'

export type { BackSection, SceneCard } from '@/shared/print/cards'

/** The paper edition's own deck cards: its table's entries, the finale and blank compartments. */
export type DeckCard =
  | Pick<DeckEntryCard, 'kind' | 'text' | 'starts'>
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

/** The step kinds a card can run from the step's own text; every other kind needs paper wording. */
const PAPER_KINDS = new Set(['prompt', 'choice', 'table', 'pick'])

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
function checkOutcomes(
  trackers: readonly ReleaseTracker[],
  outcomes: StepOutcome[],
  wording: string,
  where: string,
) {
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
    const expected = outcome.effects.map((effect) => outcomeToken(trackers, effect, where)).sort()
    if (given.join('|') !== expected.join('|')) {
      throw new Error(
        `${where}: "${mark.band}" is "${given.join(', ')}" on paper but "${expected.join(', ')}" in the preset.`,
      )
    }
  })
}

/** Every paper entry must still match a step or Scene Type of the preset. */
function checkPaperEntries(preset: Release, paper: PaperFlow) {
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

function sceneName(preset: Release, key: string, where: string): string {
  const found = preset.sceneTypes.find((st) => st.key === key)
  if (found === undefined) {
    throw new Error(`${where} names the Scene Type "${key}", which the preset does not have.`)
  }
  return found.name
}

function deckCards(
  preset: Release,
  paper: PaperFlow,
  decks: ReturnType<typeof buildCards>['decks'],
): { cards: DeckCard[]; back: string } {
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
  const printed = decks.find((candidate) => candidate.key === table.key)
  if (printed === undefined || printed.perEntry) {
    throw new Error(`The deck table "${table.key}" is too big to print one copy per weight.`)
  }
  const cards: DeckCard[] = printed.cards.map(({ kind, text, starts }) => ({ kind, text, starts }))
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

function effectText(preset: Release, effect: EntryEffect, where: string): string {
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
function tableSheets(
  preset: Release,
  oracles: string[],
  deckKey: string | undefined,
): TableSheet[] {
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

function trackerLine(tracker: ReleaseTracker): string {
  if (tracker.hint === undefined) {
    throw new Error(`The Tracker "${tracker.key}" needs a hint to print on the rules sheet.`)
  }
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

function rulesSheet(preset: Release, flow: ReleaseFlow, paper: PaperFlow): RulesSheet {
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
  if (flow.description === undefined || preset.license === undefined) {
    throw new Error(`The flow "${flow.key}" needs a description and its release a license.`)
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
 * The Scene Type cards and the deck come from the shared builder, with the paper wording, tips and
 * the guard plugged in; a card never splits, since a paper card is as big as its text needs.
 */
export function buildFlowCards(raw: unknown, paper: PaperFlow): FlowCards {
  const preset = readRelease(raw)
  const flow = preset.flows.find((candidate) => candidate.key === paper.flow)
  if (flow === undefined) {
    throw new Error(`The preset has no flow "${paper.flow}".`)
  }
  checkPaperEntries(preset, paper)
  const built = buildCards(preset, {
    flow: paper.flow,
    deckTables: paper.deck.kind === 'table' ? [paper.deck.table] : [],
    capacity: null,
    wording: (id, step) => {
      if (Object.hasOwn(paper.steps, id)) {
        const wording = paper.steps[id] ?? null
        if (wording !== null && step.kind === 'check') {
          checkOutcomes(preset.trackers, step.outcomes, wording, id)
        }
        return wording
      }
      if (!PAPER_KINDS.has(step.kind)) {
        throw new Error(`${id}: a ${step.kind} step needs paper wording in the paper file.`)
      }
      return undefined
    },
    tips: (sceneTypeKey) => paper.tips?.[sceneTypeKey],
    text: guard,
  })
  const deck = deckCards(preset, paper, built.decks)
  return {
    sceneCards: built.sceneCards,
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
