import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { JournalWithPrompts } from './JournalWithPrompts'
import { sunkenGateSession } from './sunkenGate'

// A smoke test of the prototype: it plays the mock session to the end, with a free note.
describe('Journal with prompts prototype', () => {
  it('answers each step in place, appending entries, with free notes between steps', async () => {
    render(<JournalWithPrompts session={sunkenGateSession({ gateGuarded: true })} />)
    const user = userEvent.setup()
    const prompt = () => screen.getByRole('region', { name: /^Current step/ })
    const entries = () =>
      within(screen.getByRole('list', { name: 'Scene journal' })).getAllByRole('article')

    expect(within(prompt()).getByRole('heading', { name: 'Set the scene' })).toBeInTheDocument()
    await user.type(within(prompt()).getByRole('textbox'), 'Wet stone.')
    await user.click(within(prompt()).getByRole('button', { name: 'Record' }))
    expect(entries()).toHaveLength(1)

    await user.type(screen.getByRole('textbox', { name: 'Free note' }), 'A gull screams.')
    await user.click(screen.getByRole('button', { name: 'Write note' }))
    expect(entries()).toHaveLength(2)
    expect(within(prompt()).getByRole('heading', { name: 'Ask the oracle' })).toBeInTheDocument()

    await user.click(within(prompt()).getByRole('button', { name: 'Ask the oracle' }))
    expect(
      within(prompt()).getByRole('heading', { name: 'Describe the guard' }),
    ).toBeInTheDocument()
    await user.type(within(prompt()).getByRole('textbox'), 'A drowned knight.')
    await user.click(within(prompt()).getByRole('button', { name: 'Record' }))
    await user.click(within(prompt()).getByRole('button', { name: 'Swim under the flooded arch' }))
    await user.click(within(prompt()).getByRole('button', { name: 'Roll 1d20+2' }))
    expect(
      within(screen.getByRole('region', { name: 'Next step' })).getByText('End of scene'),
    ).toBeInTheDocument()
    await user.type(within(prompt()).getByRole('textbox'), 'A stair.')
    await user.click(within(prompt()).getByRole('button', { name: 'Write the entry' }))

    expect(screen.getByText('The scene is finished.')).toBeInTheDocument()
    expect(entries()).toHaveLength(7)
  })
})
