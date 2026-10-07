import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { midSessionInputs, sunkenGateSession } from './sunkenGate'
import { Wizard } from './Wizard'

// A smoke test of the prototype: it plays the mock session to the end.
describe('Wizard prototype', () => {
  it('plays the scene step by step, always showing the next step', async () => {
    render(<Wizard session={sunkenGateSession()} />)
    const user = userEvent.setup()

    expect(screen.getByText('Step 1 of about 5–6')).toBeInTheDocument()
    expect(
      within(screen.getByRole('region', { name: 'Next step' })).getByText('Ask the oracle'),
    ).toBeInTheDocument()
    await user.type(screen.getByRole('textbox'), 'Wet stone.')
    await user.click(screen.getByRole('button', { name: 'Record' }))

    expect(screen.getByRole('heading', { name: 'Ask the oracle' })).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Ask the oracle' }))
    await user.click(screen.getByRole('button', { name: 'Climb the broken wall' }))
    await user.click(screen.getByRole('button', { name: 'Roll 2d6+1' }))
    expect(screen.getByText('Roll to climb:')).toBeInTheDocument()
    expect(
      within(screen.getByRole('region', { name: 'Next step' })).getByText('End of scene'),
    ).toBeInTheDocument()
    await user.type(screen.getByRole('textbox'), 'A stair.')
    await user.click(screen.getByRole('button', { name: 'Write the entry' }))

    expect(screen.getByRole('heading', { name: 'Scene finished' })).toBeInTheDocument()
    expect(
      within(screen.getByRole('list', { name: 'Scene journal' })).getAllByRole('article'),
    ).toHaveLength(5)
  })

  it('goes back one step', async () => {
    render(<Wizard session={sunkenGateSession()} initialInputs={midSessionInputs} />)
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Back' }))

    expect(screen.getByRole('heading', { name: 'Choose a way in' })).toBeInTheDocument()
  })
})
