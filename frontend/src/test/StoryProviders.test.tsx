import { render, screen } from '@testing-library/react'

import { StoryProviders } from './StoryProviders'

describe('StoryProviders', () => {
  it('renders the latest children after a re-render', async () => {
    const { rerender } = render(
      <StoryProviders handlers={{}}>
        <p>First render</p>
      </StoryProviders>,
    )
    expect(await screen.findByText('First render')).toBeInTheDocument()

    rerender(
      <StoryProviders handlers={{}}>
        <p>Second render</p>
      </StoryProviders>,
    )

    expect(await screen.findByText('Second render')).toBeInTheDocument()
    expect(screen.queryByText('First render')).not.toBeInTheDocument()
  })
})
