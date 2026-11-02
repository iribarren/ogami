import { render, screen } from '@testing-library/react'

import { CharCount } from './char-count'

describe('the character counter', () => {
  it('shows the length against the cap, with the same figures for assistive technology', () => {
    render(<CharCount id="c" length={37} max={100} />)

    expect(screen.getByText('37/100')).toBeInTheDocument()
    expect(screen.getByText('37 of 100 characters')).toBeInTheDocument()
    expect(screen.getByText('37/100')).not.toHaveClass('text-destructive')
  })

  it('turns destructive past the cap and says by how much', () => {
    render(<CharCount id="c" length={103} max={100} />)

    expect(screen.getByText('103/100')).toHaveClass('text-destructive')
    expect(screen.getByText('103 of 100 characters (3 over)')).toBeInTheDocument()
  })

  it('stays calm at exactly the cap', () => {
    render(<CharCount id="c" length={100} max={100} />)

    expect(screen.getByText('100/100')).not.toHaveClass('text-destructive')
  })
})
