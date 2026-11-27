import { render } from '@testing-library/react'

import { PaperBook } from './PaperBook'
import { BOOK_TITLE, type paperBook } from './paperEdition'

describe('PaperBook', () => {
  it('draws a diagram block as an SVG between the texts around it', () => {
    const book: ReturnType<typeof paperBook> = {
      title: BOOK_TITLE,
      parts: [
        {
          name: 'One',
          chapters: [
            {
              slug: 'a',
              title: 'A',
              blocks: [
                { kind: 'markdown', text: 'Before the diagram.' },
                { kind: 'flow-diagram', flow: 'cold-berth' },
                { kind: 'markdown', text: 'After the diagram.' },
              ],
            },
          ],
        },
      ],
    }

    const { container } = render(<PaperBook book={book} />)

    const article = container.querySelector('article')
    const svg = article?.querySelector('svg[role="img"]')
    expect(svg).not.toBeNull()
    const text = article?.textContent ?? ''
    expect(text).toContain('Before the diagram.')
    expect(text).toContain('After the diagram.')
    const before = [...(article?.querySelectorAll('p') ?? [])].find((p) =>
      p.textContent.includes('Before'),
    )
    const after = [...(article?.querySelectorAll('p') ?? [])].find((p) =>
      p.textContent.includes('After'),
    )
    const follows = (a: Node | undefined, b: Node | null | undefined) =>
      a !== undefined &&
      b != null &&
      Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)
    expect(follows(before, svg)).toBe(true)
    expect(follows(svg ?? undefined, after)).toBe(true)
  })
})
