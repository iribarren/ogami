import { type Chapter, chapters } from '../chapters'
import type { Segment } from '../editionBlocks'
import { pageSegments } from '../pages'

/** The working title of the book, in one place: the title page, the PDF title and the card sheets. */
export const BOOK_TITLE = 'The Narrative Flow: Designing and Playing Solo Games'

export interface PaperChapter {
  slug: string
  title: string
  markdown: string
}

export interface PaperPart {
  name: string
  chapters: PaperChapter[]
}

/**
 * The Paper edition: the chapters of both editions in manifest order, grouped by part, each with
 * only its common text (no "In Ogami" block). A chapter with no common text throws, so a page can
 * never print empty.
 */
export function paperBook(
  list: readonly Chapter[] = chapters,
  segmentsOf: (slug: string) => Segment[] = pageSegments,
): { title: string; parts: PaperPart[] } {
  const parts: PaperPart[] = []
  for (const chapter of list.filter((entry) => entry.editions === 'both')) {
    const markdown = segmentsOf(chapter.slug)
      .filter((segment) => segment.edition === 'common')
      .map((segment) => segment.text)
      .join('\n\n')
    if (markdown === '') {
      throw new Error(`The Paper edition has nothing to print for "${chapter.slug}".`)
    }
    let part = parts.at(-1)
    if (part?.name !== chapter.part) {
      part = { name: chapter.part, chapters: [] }
      parts.push(part)
    }
    part.chapters.push({ slug: chapter.slug, title: chapter.title, markdown })
  }
  return { title: BOOK_TITLE, parts }
}
