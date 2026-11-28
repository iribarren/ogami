import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { Mat } from './Mat'
import type { Mat as MatModel, MatCard } from './mat'

const card = (
  session: number,
  number: number,
  title: string,
  extra: Partial<MatCard> = {},
): MatCard => ({
  sessionNumber: session,
  number,
  title,
  sceneType: 'Legwork',
  hook: false,
  current: false,
  ...extra,
})

const mat: MatModel = {
  row: [
    card(3, 1, 'Opening', { hook: true, sceneType: null }),
    card(3, 2, 'The vault'),
    card(3, 3, 'The getaway', { current: true }),
  ],
  piles: [
    {
      sessionNumber: 2,
      sceneCount: 2,
      top: card(2, 2, 'The stairs'),
      cards: [card(2, 1, 'The door'), card(2, 2, 'The stairs')],
    },
  ],
}

describe('Mat', () => {
  it('lays this session in play order, showing only title and Scene Type', () => {
    render(<Mat mat={mat} />)

    const row = screen.getByRole('list', { name: 'This session' })
    const titles = within(row)
      .getAllByTestId('mat-card')
      .map((element) => element.textContent)
    expect(titles).toEqual(['OpeningHook', 'The vaultLegwork', 'The getawayLegwork'])
  })

  it('marks the current scene and makes the hook scene small, without a click handler', async () => {
    render(<Mat mat={mat} />)
    const row = screen.getByRole('list', { name: 'This session' })
    const [hook, , current] = within(row).getAllByTestId('mat-card')

    expect(current).toHaveAttribute('aria-current', 'step')
    expect(current).toHaveClass('w-40')
    expect(hook).toHaveAttribute('data-hook', 'true')
    expect(hook).toHaveClass('w-20')
    await userEvent.click(screen.getByText('The getaway'))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('shows an earlier session as a pile that fans out when opened, by mouse or keyboard', async () => {
    const user = userEvent.setup()
    render(<Mat mat={mat} />)
    const pile = screen.getByRole('button', { name: 'Session 2, 2 scenes, top card The stairs' })

    await user.click(pile)
    const dialog = screen.getByRole('dialog', { name: 'Session 2' })
    const scenes = within(dialog).getByRole('list', { name: 'Scenes of session 2' })
    expect(
      within(scenes)
        .getAllByTestId('mat-card')
        .map((element) => element.textContent),
    ).toEqual(['The doorLegwork', 'The stairsLegwork'])

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(pile).toHaveFocus()

    await user.keyboard('{Enter}')
    expect(screen.getByRole('dialog', { name: 'Session 2' })).toBeInTheDocument()
  })

  it('says so on an empty mat and when no session is under way', () => {
    const { rerender } = render(<Mat mat={{ row: [], piles: [] }} />)
    expect(screen.getByText(/No scene has been played yet/)).toBeInTheDocument()

    rerender(<Mat mat={{ row: [], piles: mat.piles }} />)
    expect(screen.getByText('No session is under way.')).toBeInTheDocument()
    expect(screen.queryByText(/No scene has been played yet/)).not.toBeInTheDocument()
  })
})
