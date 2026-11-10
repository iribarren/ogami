import { render, screen } from '@testing-library/react'

import { PrintApp } from './PrintApp'

describe('PrintApp', () => {
  it.each([null, '', 'nope'])(
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
})
