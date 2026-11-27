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

  it('draws an image block as a placeholder of its size between the texts around it', () => {
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
                { kind: 'markdown', text: 'Before the image.' },
                {
                  kind: 'image',
                  file: 'house-dice.webp',
                  style: 'house',
                  size: 'spot',
                  alt: 'Two dice on a notebook.',
                  subject: 'Two dice mid-tumble.',
                },
                { kind: 'markdown', text: 'After the image.' },
              ],
            },
          ],
        },
      ],
    }

    const { container } = render(<PaperBook book={book} />)

    const figure = container.querySelector('article figure[data-image-size="spot"]')
    expect(figure).not.toBeNull()
    expect(figure?.textContent).toContain('Illustration to come')
    expect(figure?.textContent).toContain('house-dice.webp')
    expect(figure?.textContent).toContain('Two dice on a notebook.')
    expect(figure?.textContent).toContain('Two dice mid-tumble.')
    const paragraphs = [...container.querySelectorAll('article p')]
    const before = paragraphs.find((p) => p.textContent.includes('Before'))
    const after = paragraphs.find((p) => p.textContent.includes('After'))
    const follows = (a: Node | undefined, b: Node | null | undefined) =>
      a !== undefined &&
      b != null &&
      Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)
    expect(follows(before, figure)).toBe(true)
    expect(follows(figure ?? undefined, after)).toBe(true)
  })
})
