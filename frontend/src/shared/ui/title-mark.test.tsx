import { render } from '@testing-library/react'

import { OrnamentDivider } from './ornament-divider'
import { TitleMark } from './title-mark'

describe('TitleMark', () => {
  it('is hidden from assistive technology and shows the first letter of the name', () => {
    const { container } = render(<TitleMark name="  the lost mine" />)

    const mark = container.firstElementChild
    expect(mark).toHaveAttribute('aria-hidden', 'true')
    expect(mark).toHaveTextContent(/^T$/)
  })

  it('falls back to a glyph when the name has no letter or digit', () => {
    const { container } = render(<TitleMark name="🐉" />)

    expect(container.firstElementChild).toHaveTextContent(/^✦$/)
  })
})

describe('OrnamentDivider', () => {
  it('is decorative and empty', () => {
    const { container } = render(<OrnamentDivider />)

    expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true')
    expect(container.firstElementChild).toBeEmptyDOMElement()
  })
})
