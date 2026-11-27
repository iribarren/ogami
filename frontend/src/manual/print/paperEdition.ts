import { type Chapter, chapters } from '../chapters'
import type { ImageSize, Segment } from '../editionBlocks'
import { pageSegments } from '../pages'

/** The working title of the book, in one place: the title page, the PDF title and the card sheets. */
export const BOOK_TITLE = 'The Narrative Flow: Designing and Playing Solo Games'

/** A run of a printed chapter, in page order: Markdown text, a diagram of a manual flow, or an image. */
export type PaperBlock =
  | { kind: 'markdown'; text: string }
  | { kind: 'flow-diagram'; flow: string; phase?: string }
  | { kind: 'image'; file: string; style: string; size: ImageSize; alt: string; subject: string }

export interface PaperChapter {
  slug: string
  title: string
  blocks: PaperBlock[]
}

function blockOf(segment: Segment): PaperBlock {
  if (segment.kind === 'markdown') {
    return { kind: 'markdown', text: segment.text }
  }
  if (segment.kind === 'image') {
    const { file, style, size, alt, subject } = segment
    return { kind: 'image', file, style, size, alt, subject }
  }
  return segment.phase === undefined
    ? { kind: 'flow-diagram', flow: segment.flow }
    : { kind: 'flow-diagram', flow: segment.flow, phase: segment.phase }
}

export interface PaperPart {
  name: string
  chapters: PaperChapter[]
}

/**
 * The Paper edition: the chapters of both editions in manifest order, grouped by part, each with
 * only its common runs (text, diagrams and images, no "In Ogami" block), in page order. A chapter with none
 * throws, so a page can never print empty.
 */
export function paperBook(
  list: readonly Chapter[] = chapters,
  segmentsOf: (slug: string) => Segment[] = pageSegments,
): { title: string; parts: PaperPart[] } {
  const parts: PaperPart[] = []
  for (const chapter of list.filter((entry) => entry.editions === 'both')) {
    const blocks = segmentsOf(chapter.slug)
      .filter((segment) => segment.edition === 'common')
      .map(blockOf)
    if (blocks.length === 0) {
      throw new Error(`The Paper edition has nothing to print for "${chapter.slug}".`)
    }
    let part = parts.at(-1)
    if (part?.name !== chapter.part) {
      part = { name: chapter.part, chapters: [] }
      parts.push(part)
    }
    part.chapters.push({ slug: chapter.slug, title: chapter.title, blocks })
  }
  return { title: BOOK_TITLE, parts }
}
