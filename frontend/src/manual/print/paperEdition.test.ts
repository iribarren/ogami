import { chapters, type Chapter } from '../chapters'
import { BOOK_TITLE, paperBook } from './paperEdition'

describe('the Paper edition', () => {
  it('names the book without the app', () => {
    expect(BOOK_TITLE).toBe('The Narrative Flow: Designing and Playing Solo Games')
    expect(BOOK_TITLE).not.toMatch(/\b(Ogami|Studio)\b/i)
  })

  it('holds the chapters of both editions, in manifest order, grouped by part', () => {
    const slugs = paperBook().parts.flatMap((part) => part.chapters.map((chapter) => chapter.slug))

    expect(slugs).toEqual(
      chapters.filter((chapter) => chapter.editions === 'both').map((chapter) => chapter.slug),
    )
  })

  it('leaves every Full chapter of the manual out of the Paper edition', () => {
    const book = paperBook()
    const slugs = book.parts.flatMap((part) => part.chapters.map((chapter) => chapter.slug))
    const full = chapters.filter((chapter) => chapter.editions === 'full')

    expect(full.length).toBeGreaterThan(0)
    for (const chapter of full) {
      expect(slugs).not.toContain(chapter.slug)
    }
    expect(book.parts.map((part) => part.name).join('\n')).not.toMatch(/Part III/)
  })

  it('prints the Appendices that belong to both editions and no Full-only page', () => {
    const book = paperBook()
    const slugs = book.parts.flatMap((part) => part.chapters.map((chapter) => chapter.slug))

    expect(book.parts.map((part) => part.name)).toContain('Appendices')
    expect(slugs).toContain('flow-the-long-carry')
    expect(slugs).toContain('glossary')
    expect(slugs).not.toContain('paper-to-ogami')
    expect(book.parts.map((part) => part.name).join('\n')).not.toMatch(/Part IV/)
  })

  it('leaves out Full chapters and In Ogami blocks', () => {
    const list: Chapter[] = [
      { slug: 'a', title: 'A', part: 'One', editions: 'both' },
      { slug: 'b', title: 'B', part: 'One', editions: 'full' },
    ]
    const book = paperBook(list, (slug) => [
      { edition: 'common', text: `Common ${slug}` },
      { edition: 'in-ogami', text: `Screen ${slug}` },
    ])

    expect(book.parts).toEqual([
      { name: 'One', chapters: [{ slug: 'a', title: 'A', markdown: 'Common a' }] },
    ])
  })

  it('throws on a chapter with nothing common, never printing an empty page', () => {
    const list: Chapter[] = [{ slug: 'a', title: 'A', part: 'One', editions: 'both' }]

    expect(() => paperBook(list, () => [{ edition: 'in-ogami', text: 'Screen' }])).toThrow(/"a"/)
  })

  it('has no app wording in any chapter', () => {
    const text = paperBook().parts.flatMap((part) =>
      part.chapters.map((chapter) => chapter.markdown),
    )

    for (const markdown of text) {
      expect(markdown).not.toMatch(/\b(Ogami|Studio|app)\b/i)
    }
  })
})
