import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { atStep, atTheScenePick, guided, promptStep } from '../flow/fixtures'
import { renderTableOf } from './testTable'

const tourCard = { key: 'tour', name: 'Tour', purpose: 'A tour.', tips: 'Go slow.', oracles: [] }
const picked = () => Response.json(guided(atStep(promptStep)))

describe('the deck', () => {
  it('lies face down and cannot be clicked without a scene pick', async () => {
    renderTableOf(guided(atStep(promptStep)))

    const deck = await screen.findByRole('button', { name: /^Deck, face down/ })
    expect(deck).toBeDisabled()
    expect(deck).toHaveAccessibleName('Deck, face down. Next: Setup: Who walks in?')
  })

  it('fans the offered cards out face up when the player chooses, and plays one', async () => {
    const user = userEvent.setup()
    const { sent } = renderTableOf(guided(atTheScenePick()), { 'flow-run/pick': picked })

    await user.click(await screen.findByRole('button', { name: 'Choose the next Scene Type' }))
    const dialog = within(await screen.findByRole('dialog'))
    expect(dialog.getAllByRole('article')).toHaveLength(2)
    expect(dialog.getByText('Rest and take stock.')).toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Play Quiet' }))

    await waitFor(() => {
      expect(sent['flow-run/pick']).toEqual({ sceneType: 'quiet' })
    })
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('turns the next card of a sequence and plays it', async () => {
    const user = userEvent.setup()
    const { sent } = renderTableOf(
      guided(atTheScenePick({ rule: 'sequence', cards: [tourCard] })),
      { 'flow-run/pick': picked },
    )

    expect(screen.queryByRole('button', { name: 'Play Tour' })).not.toBeInTheDocument()
    await user.click(await screen.findByRole('button', { name: 'Turn the next card' }))
    expect(screen.getByText('A tour.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Play Tour' }))

    await waitFor(() => {
      expect(sent['flow-run/pick']).toEqual({ sceneType: 'tour' })
    })
  })

  it('slides out only the forced card, with no click on the deck', async () => {
    const user = userEvent.setup()
    const { sent } = renderTableOf(guided(atTheScenePick({ forced: true, cards: [tourCard] })), {
      'flow-run/pick': picked,
    })

    expect(await screen.findByText('Forced for this scene')).toBeInTheDocument()
    expect(screen.getAllByRole('article')).toHaveLength(1)
    expect(screen.getByRole('button', { name: /^Deck, one card/ })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Play Tour' }))

    await waitFor(() => {
      expect(sent['flow-run/pick']).toEqual({ sceneType: 'tour' })
    })
  })

  it('rolls the Scene Type on the oracle table when clicked', async () => {
    const user = userEvent.setup()
    const { sent } = renderTableOf(
      guided(atTheScenePick({ rule: 'oracle', cards: [], table: 'weather' })),
      { 'flow-run/pick/roll': picked },
    )

    await user.click(await screen.findByRole('button', { name: 'Roll on Weather' }))

    await waitFor(() => {
      expect(sent).toHaveProperty(['flow-run/pick/roll'])
    })
  })

  it('flips the card the oracle table rolled face up instead of offering a choice', async () => {
    const user = userEvent.setup()
    const { sent } = renderTableOf(
      guided(atTheScenePick({ rule: 'oracle', cards: [tourCard], table: 'weather' })),
      { 'flow-run/pick': picked },
    )

    expect(await screen.findByText('A tour.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Choose the next Scene Type' })).toBeNull()
    expect(screen.getByRole('button', { name: /^Deck, the table rolled/ })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Play Tour' }))

    await waitFor(() => {
      expect(sent['flow-run/pick']).toEqual({ sceneType: 'tour' })
    })
  })

  it('shows the refusal of a pick near the deck', async () => {
    const user = userEvent.setup()
    renderTableOf(guided(atTheScenePick({ rule: 'oracle', cards: [], table: 'weather' })), {
      'flow-run/pick/roll': () => Response.json({ error: 'Not now.' }, { status: 422 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Roll on Weather' }))

    expect((await screen.findByRole('alert')).textContent).toContain('Not now.')
  })
})
