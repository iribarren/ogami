import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { ListLinks, OpenListProvider } from './ListLinks'

describe('ListLinks', () => {
  it('shows nothing where there are no list panels to open', () => {
    render(<ListLinks />)

    expect(screen.queryByRole('navigation', { name: 'Lists' })).not.toBeInTheDocument()
  })

  it('opens the Threads, Places or NPCs panel from its link', async () => {
    const user = userEvent.setup()
    const open = vi.fn()
    render(
      <OpenListProvider value={open}>
        <ListLinks />
      </OpenListProvider>,
    )

    await user.click(screen.getByRole('button', { name: 'NPCs' }))
    await user.click(screen.getByRole('button', { name: 'Threads' }))
    await user.click(screen.getByRole('button', { name: 'Places' }))

    expect(open.mock.calls).toEqual([['npc'], ['thread'], ['place']])
  })
})
