/** `both` pages are in the Paper and the Full edition; `full` pages only in the Full one. */
export type Editions = 'both' | 'full'

export interface Chapter {
  /** The URL segment and the name of the file in `content/`, without `.md`. */
  slug: string
  title: string
  part: string
  editions: Editions
}

const PART_I = 'Part I, The Narrative Flow'
const PART_II = 'Part II, Solo Toolbox'
const PART_III = 'Part III, Authoring a GameSystem'

/** The manual in reading order. Each entry has a Markdown file in `content/`. */
export const chapters: readonly Chapter[] = [
  { slug: 'how-to-use', title: 'How to use this book', part: 'Front matter', editions: 'both' },
  { slug: 'what-you-need', title: 'What you need', part: 'Front matter', editions: 'both' },
  { slug: 'the-two-games', title: 'The two games', part: 'Front matter', editions: 'both' },
  {
    slug: 'credits-and-license',
    title: 'Credits and license',
    part: 'Front matter',
    editions: 'both',
  },
  { slug: 'the-promise', title: 'The Promise', part: PART_I, editions: 'both' },
  { slug: 'the-arc', title: 'The Arc', part: PART_I, editions: 'both' },
  { slug: 'scene-types', title: 'Scene Types', part: PART_I, editions: 'both' },
  { slug: 'steps', title: 'Steps', part: PART_I, editions: 'both' },
  {
    slug: 'choosing-the-next-scene',
    title: 'Choosing the Next Scene',
    part: PART_I,
    editions: 'both',
  },
  { slug: 'memory', title: 'Memory', part: PART_I, editions: 'both' },
  { slug: 'pressure', title: 'Pressure', part: PART_I, editions: 'both' },
  { slug: 'session-rituals', title: 'Session Rituals', part: PART_I, editions: 'both' },
  { slug: 'truths-and-endings', title: 'Truths and Endings', part: PART_I, editions: 'both' },
  { slug: 'cards-and-print', title: 'Cards and Print', part: PART_I, editions: 'both' },
  { slug: 'playtest-and-share', title: 'Playtest and Share', part: PART_I, editions: 'both' },
  { slug: 'likelihood-oracles', title: 'Likelihood Oracles', part: PART_II, editions: 'both' },
  { slug: 'oracle-tables', title: 'Oracle Tables', part: PART_II, editions: 'both' },
  {
    slug: 'inspiration-and-random-events',
    title: 'Inspiration and Random Events',
    part: PART_II,
    editions: 'both',
  },
  {
    slug: 'trackers-and-clocks',
    title: 'Trackers and Clocks at the Table',
    part: PART_II,
    editions: 'both',
  },
  { slug: 'cards-and-dice', title: 'Cards and Dice as Oracles', part: PART_II, editions: 'both' },
  { slug: 'journaling-and-lists', title: 'Journaling and Lists', part: PART_II, editions: 'both' },
  { slug: 'rules-and-story', title: 'Rules and Story', part: PART_III, editions: 'full' },
  { slug: 'sheets-and-groups', title: 'Sheets and Groups', part: PART_III, editions: 'full' },
  {
    slug: 'derived-values-and-formulas',
    title: 'Derived Values and Formulas',
    part: PART_III,
    editions: 'full',
  },
  {
    slug: 'checks-and-outcome-bands',
    title: 'Checks and Outcome Bands',
    part: PART_III,
    editions: 'full',
  },
  { slug: 'gear-and-currency', title: 'Gear and Currency', part: PART_III, editions: 'full' },
  {
    slug: 'licensing-and-credits',
    title: 'Licensing and Credits',
    part: PART_III,
    editions: 'full',
  },
]

export function findChapter(slug: string): Chapter | undefined {
  return chapters.find((chapter) => chapter.slug === slug)
}

/** The chapters grouped by part, in reading order. */
export function parts(): { name: string; chapters: Chapter[] }[] {
  const grouped: { name: string; chapters: Chapter[] }[] = []
  for (const chapter of chapters) {
    const last = grouped.at(-1)
    if (last?.name === chapter.part) {
      last.chapters.push(chapter)
    } else {
      grouped.push({ name: chapter.part, chapters: [chapter] })
    }
  }
  return grouped
}

/** The chapters on each side of `slug`; `undefined` at the ends. */
export function neighbours(slug: string): { previous?: Chapter; next?: Chapter } {
  const at = chapters.findIndex((chapter) => chapter.slug === slug)
  return { previous: chapters[at - 1], next: chapters[at + 1] }
}
