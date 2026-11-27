import type { Release, ReleaseSceneType, ReleaseStep, StepOutcome, ReleaseTable } from './release'
import { BAND_LABELS, outcomeToken, sentence, UnwordableEffect, words } from './wording'

export interface BackSection {
  heading: 'Setup' | 'Play' | 'Closing'
  lines: string[]
}

export interface SceneCard {
  name: string
  purpose: string
  tips: string
  back: BackSection[]
  /** The Scene Type this card carries on, when it is a "(cont.)" card. */
  continues?: string
}

/** A Scene Type whose back did not fit one card, for the print view's list of continued cards. */
export interface ContinuedCard {
  sceneType: string
  name: string
  /** How many cards the Scene Type takes, the first one included. */
  cards: number
}

export interface DeckEntryCard {
  kind: 'entry'
  text: string
  /** The Scene Type the entry starts, by name. */
  starts: string | null
  /** The numbers the entry answers to; only on a deck that prints one card per entry. */
  range?: string
}

export interface Deck {
  key: string
  name: string
  cards: DeckEntryCard[]
  /** True when the table was too big to print one copy per weight: one card per entry, with its range. */
  perEntry: boolean
}

/**
 * How much text one card back holds, as a pure estimate (no DOM): the lines it has and the
 * characters of a line. A heading takes a line, and so does the gap before the next section.
 */
export interface CardCapacity {
  lines: number
  charsPerLine: number
}

/** The estimate for each card size, in a 9 pt face with the 5 mm safe area kept clear. */
export const CARD_CAPACITY = {
  /** 63 × 88 mm. */
  poker: { lines: 24, charsPerLine: 40 },
  /** 69 × 94 mm, with bleed. The safe area is the same, so a little more room. */
  bleed: { lines: 26, charsPerLine: 44 },
  /** 105 × 148 mm. */
  a6: { lines: 42, charsPerLine: 64 },
} as const satisfies Record<string, CardCapacity>

/** Past this many copies a deck prints one card per entry, with its range. */
export const MAX_DECK_CARDS = 54

export interface BuildOptions {
  /** The key of the flow to build. */
  flow: string
  /** Oracle tables to print as decks besides the flow's `oracle` selections. */
  deckTables?: readonly string[]
  /** The room on a card back; `null` never splits a card. Poker size by default. */
  capacity?: CardCapacity | null
  /**
   * Wording to use for a step instead of the generated one: a string, `null` to leave the step off
   * the card, `undefined` to generate it. May throw to refuse a step.
   */
  wording?: (
    id: string,
    step: ReleaseStep,
    sceneType: ReleaseSceneType,
  ) => string | null | undefined
  /** The tips of a Scene Type's front instead of the release's. */
  tips?: (sceneTypeKey: string) => string | undefined
  /** Applied to every text a card prints, with where it came from; returns the text to print. */
  text?: (text: string, where: string) => string
}

export interface Cards {
  sceneCards: SceneCard[]
  continued: ContinuedCard[]
  decks: Deck[]
}

const identity = (text: string) => text

const fail = (message: string): never => {
  throw new Error(message)
}

/** `{step:key}` is the title of that step of the Scene Type, `{fact:slot}` the label of the Fact slot. */
function resolvePlaceholders(
  text: string,
  release: Release,
  sceneType: ReleaseSceneType,
  where: string,
): string {
  const steps = [...sceneType.setup, ...sceneType.play, ...sceneType.closing]
  return text
    .replace(/\{step:([^}]*)\}/g, (_match, key: string) => {
      const step = steps.find((candidate) => candidate.key === key)
      return step?.title ?? fail(`${where}: {step:${key}} is not a step of the Scene Type.`)
    })
    .replace(/\{fact:([^}]*)\}/g, (_match, key: string) => {
      const slot = release.factSlots.find((candidate) => candidate.key === key)
      return slot?.label ?? fail(`${where}: {fact:${key}} is not a Fact slot of the release.`)
    })
}

/** "Failing: Winter +1." or "Going badly: Burden +1 and lose d6 Wind." for each band that has wording. */
function bandsText(release: Release, outcomes: StepOutcome[], where: string): string {
  return outcomes
    .flatMap((outcome) => {
      const tokens = outcome.effects.flatMap((effect) => {
        try {
          return outcomeToken(release.trackers, effect, where)
        } catch (error) {
          // A card prints what it can word; the rest of the effects stay on the screen.
          if (error instanceof UnwordableEffect) {
            return []
          }
          throw error
        }
      })
      if (tokens.length === 0) {
        return []
      }
      const label =
        BAND_LABELS.get(outcome.band) ??
        `${outcome.band.charAt(0).toUpperCase()}${outcome.band.slice(1)}`
      const joined =
        tokens.length > 1
          ? `${tokens.slice(0, -1).join(', ')} and ${tokens.at(-1) ?? ''}`
          : (tokens[0] ?? '')
      return `${label}${label.endsWith(':') ? '' : ':'} ${joined}.`
    })
    .join(' ')
}

/** The line a step prints when nothing overrides it: what its title and prompt say, and a check's bands. */
function generatedLine(release: Release, step: ReleaseStep, where: string): string {
  const title = sentence(step.title)
  switch (step.kind) {
    case 'check':
      return words(title, step.prompt ?? step.tip, bandsText(release, step.outcomes, where))
    case 'choice':
      return words(title, step.tip, step.options.join(' / '))
    case 'table':
    case 'pick':
      return words(title, step.tip)
    default:
      return words(title, step.prompt ?? step.tip)
  }
}

function stepLine(
  release: Release,
  sceneType: ReleaseSceneType,
  step: ReleaseStep,
  options: BuildOptions,
): string | null {
  const id = `${sceneType.key}.${step.key}`
  const override = options.wording?.(id, step, sceneType)
  if (override === null) {
    return null
  }
  const line =
    override ?? resolvePlaceholders(generatedLine(release, step, id), release, sceneType, id)
  return (options.text ?? identity)(line, id)
}

/** The lines a text takes at this width, never less than one. */
const linesOf = (text: string, width: number) => Math.max(1, Math.ceil(text.length / width))

/**
 * Cuts the sections of a card back into pages of at most `capacity.lines` lines. A heading goes
 * with its first line, and again on the page a section carries on to; a line is never cut, so a
 * line longer than a page sits on a page of its own.
 */
export function paginateBack(back: BackSection[], capacity: CardCapacity | null): BackSection[][] {
  if (capacity === null) {
    return [back]
  }
  const pages: BackSection[][] = [[]]
  let used = 0
  for (const section of back) {
    let current: BackSection | undefined
    for (const line of section.lines) {
      const need = linesOf(line, capacity.charsPerLine) + (current === undefined ? 2 : 0)
      if (used + need > capacity.lines && used > 0) {
        pages.push([])
        used = 0
        current = undefined
      }
      if (current === undefined) {
        current = { heading: section.heading, lines: [] }
        pages.at(-1)?.push(current)
        used += 2
      }
      current.lines.push(line)
      used += linesOf(line, capacity.charsPerLine)
    }
  }
  return pages
}

function sceneCards(
  release: Release,
  options: BuildOptions,
): { cards: SceneCard[]; continued: ContinuedCard[] } {
  const capacity = options.capacity === undefined ? CARD_CAPACITY.poker : options.capacity
  const text = options.text ?? identity
  const cards: SceneCard[] = []
  const continued: ContinuedCard[] = []
  for (const sceneType of release.sceneTypes) {
    const sections = [
      ['Setup', sceneType.setup],
      ['Play', sceneType.play],
      ['Closing', sceneType.closing],
    ] as const
    const back = sections
      .map(([heading, steps]) => ({
        heading,
        lines: steps.flatMap((step) => stepLine(release, sceneType, step, options) ?? []),
      }))
      .filter((section) => section.lines.length > 0)
    const tips = options.tips?.(sceneType.key) ?? sceneType.tips ?? ''
    const name = text(sceneType.name, sceneType.key)
    const purpose = text(sceneType.purpose, sceneType.key)
    const front = {
      purpose,
      tips: text(
        resolvePlaceholders(tips, release, sceneType, `${sceneType.key} tips`),
        `${sceneType.key} tips`,
      ),
    }
    const pages = paginateBack(back, capacity)
    pages.forEach((page, index) => {
      cards.push(
        index === 0
          ? { name, ...front, back: page }
          : { name: `${name} (cont.)`, purpose, tips: '', back: page, continues: sceneType.key },
      )
    })
    if (pages.length > 1) {
      continued.push({ sceneType: sceneType.key, name, cards: pages.length })
    }
  }
  return { cards, continued }
}

/** The numbers each entry answers to, in table order: "4" or "1-3". */
function entryRanges(table: ReleaseTable): string[] {
  let next = 1
  return table.entries.map((entry) => {
    const first = entry.min ?? next
    const last = entry.max ?? first + (entry.weight ?? 1) - 1
    next = last + 1
    return first === last ? String(first) : `${String(first)}-${String(last)}`
  })
}

/** Copies of an entry: its range width on a ranged table, its weight (1 if none) on a weighted one. */
const copiesOf = (entry: ReleaseTable['entries'][number]) =>
  entry.min !== undefined && entry.max !== undefined
    ? entry.max - entry.min + 1
    : (entry.weight ?? 1)

function deck(release: Release, key: string, options: BuildOptions): Deck {
  const table = release.tables.find((candidate) => candidate.key === key)
  if (table === undefined) {
    return fail(`The deck table "${key}" is not a table of the release.`)
  }
  const text = options.text ?? identity
  const startsOf = (entry: ReleaseTable['entries'][number]) => {
    if (entry.sceneType === undefined) {
      return null
    }
    return (
      release.sceneTypes.find((candidate) => candidate.key === entry.sceneType)?.name ??
      fail(`${key} names the Scene Type "${entry.sceneType}", which the release does not have.`)
    )
  }
  const copies = table.entries.reduce((sum, entry) => sum + copiesOf(entry), 0)
  const perEntry = copies > MAX_DECK_CARDS
  const ranges = entryRanges(table)
  const cards = table.entries.flatMap((entry, index): DeckEntryCard[] => {
    const card: DeckEntryCard = {
      kind: 'entry',
      text: text(entry.text, key),
      starts: startsOf(entry),
    }
    return perEntry
      ? [{ ...card, range: ranges[index] ?? '' }]
      : Array.from({ length: copiesOf(entry) }, () => ({ ...card }))
  })
  return { key, name: table.name, cards, perEntry }
}

/**
 * The Scene Type cards and the decks of one flow of a release. A Scene Type card whose back is
 * longer than `capacity` continues on "(cont.)" cards, and `continued` lists those Scene Types.
 * A deck is the table of each `oracle` selection of the flow, and any table of `deckTables`; a
 * table of up to 54 copies prints one card per copy, a bigger one a card per entry with its range.
 */
export function buildCards(release: Release, options: BuildOptions): Cards {
  const flow = release.flows.find((candidate) => candidate.key === options.flow)
  if (flow === undefined) {
    return fail(`The release has no flow "${options.flow}".`)
  }
  const { cards, continued } = sceneCards(release, options)
  const deckKeys = [...new Set([...flow.selectionTables, ...(options.deckTables ?? [])])]
  return {
    sceneCards: cards,
    continued,
    decks: deckKeys.map((key) => deck(release, key, options)),
  }
}
