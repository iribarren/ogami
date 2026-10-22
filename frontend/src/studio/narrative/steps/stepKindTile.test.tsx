import { render } from '@testing-library/react'

import { StepKindTile } from './stepKindTile'
import { stepKinds } from './stepDraft'

const markup = (kind: string) => render(<StepKindTile kind={kind} />).container.innerHTML

describe('the step kind tile', () => {
  it('is decorative for every kind', () => {
    for (const kind of [...stepKinds, 'unknown']) {
      const { container } = render(<StepKindTile kind={kind} />)
      expect(container.firstElementChild).toHaveAttribute('aria-hidden', 'true')
      expect(container.querySelector('svg')).not.toBeNull()
    }
  })

  it('draws a die for rolls and checks and a different icon for every other kind', () => {
    expect(markup('roll')).toBe(markup('check'))
    const others = stepKinds.filter((kind) => kind !== 'check').map(markup)
    expect(new Set(others).size).toBe(others.length)
  })

  it('does not pass a kind it does not know for one it does', () => {
    const known = stepKinds.map(markup)
    expect(known).not.toContain(markup('invented'))
    expect(markup('invented')).toBe(markup('another'))
  })
})
