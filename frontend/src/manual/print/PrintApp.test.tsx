import { render, screen } from '@testing-library/react'

import { PrintApp } from './PrintApp'

describe('PrintApp', () => {
  it.each([null, '', 'nope', 'constructor', '__proto__', 'toString'])(
    'shows an error for the unknown document %j, never an empty page',
    (doc) => {
      render(<PrintApp doc={doc} />)

      expect(screen.getByRole('alert')).toHaveTextContent('Unknown document')
      expect(document.body.dataset.print).toBe('error')
    },
  )

  it('renders the Paper edition with the book title and no app wording', () => {
    const { container } = render(<PrintApp doc="paper" />)

    expect(document.body.dataset.print).toBe('ready')
    expect(document.title).toBe('The Narrative Flow: Designing and Playing Solo Games')
    expect(container.textContent).not.toMatch(/\b(Ogami|Studio)\b/)
  })

  it.each([
    ['cards-the-long-carry', 'The Shrine Gate, front'],
    ['cards-cold-berth', 'The Reveal, front'],
  ])('renders the card sheets of %s', (doc, card) => {
    const { container } = render(<PrintApp doc={doc} />)

    expect(document.body.dataset.print).toBe('ready')
    expect(container.querySelector(`[data-card="${card}"]`)).not.toBeNull()
  })
})
