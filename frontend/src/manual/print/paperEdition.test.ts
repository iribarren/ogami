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
      { edition: 'common', kind: 'markdown', text: `Common ${slug}` },
      { edition: 'in-ogami', kind: 'markdown', text: `Screen ${slug}` },
    ])

    expect(book.parts).toEqual([
      {
        name: 'One',
        chapters: [{ slug: 'a', title: 'A', blocks: [{ kind: 'markdown', text: 'Common a' }] }],
      },
    ])
  })

  it('keeps the diagrams of the common text in order and leaves out those in an In Ogami block', () => {
    const list: Chapter[] = [{ slug: 'a', title: 'A', part: 'One', editions: 'both' }]
    const book = paperBook(list, () => [
      { edition: 'common', kind: 'markdown', text: 'Before.' },
      { edition: 'common', kind: 'flow-diagram', flow: 'cold-berth' },
      { edition: 'in-ogami', kind: 'flow-diagram', flow: 'the-long-carry' },
      { edition: 'common', kind: 'markdown', text: 'Middle.' },
      { edition: 'common', kind: 'flow-diagram', flow: 'the-long-carry', phase: 'the-road' },
    ])

    expect(book.parts[0]?.chapters[0]?.blocks).toEqual([
      { kind: 'markdown', text: 'Before.' },
      { kind: 'flow-diagram', flow: 'cold-berth' },
      { kind: 'markdown', text: 'Middle.' },
      { kind: 'flow-diagram', flow: 'the-long-carry', phase: 'the-road' },
    ])
  })

  it('keeps the images of the common text in order and leaves out those in an In Ogami block', () => {
    const list: Chapter[] = [{ slug: 'a', title: 'A', part: 'One', editions: 'both' }]
    const image = {
      kind: 'image',
      file: 'house-dice.webp',
      style: 'house',
      size: 'spot',
      alt: 'Dice.',
      subject: 'Two dice.',
    } as const
    const book = paperBook(list, () => [
      { edition: 'common', kind: 'markdown', text: 'Before.' },
      { edition: 'common', ...image },
      { edition: 'in-ogami', ...image, file: 'house-other.webp' },
      { edition: 'common', kind: 'markdown', text: 'After.' },
    ])

    expect(book.parts[0]?.chapters[0]?.blocks).toEqual([
      { kind: 'markdown', text: 'Before.' },
      image,
      { kind: 'markdown', text: 'After.' },
    ])
  })

  it('throws on a chapter with nothing common, never printing an empty page', () => {
    const list: Chapter[] = [{ slug: 'a', title: 'A', part: 'One', editions: 'both' }]

    expect(() =>
      paperBook(list, () => [{ edition: 'in-ogami', kind: 'markdown', text: 'Screen' }]),
    ).toThrow(/"a"/)
  })

  it('has no app wording in any chapter', () => {
    const text = paperBook().parts.flatMap((part) =>
      part.chapters.flatMap((chapter) =>
        chapter.blocks.flatMap((block) => (block.kind === 'markdown' ? [block.text] : [])),
      ),
    )

    for (const markdown of text) {
      expect(markdown).not.toMatch(/\b(Ogami|Studio|app)\b/i)
    }
  })
})
