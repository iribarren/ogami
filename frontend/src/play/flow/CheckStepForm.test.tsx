import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { gripCheck, sneakCheck } from '../campaigns/fixtures'
import { guidedWithChecks, atStep, checkStep } from './fixtures'
import { CheckStepForm } from './CheckStepForm'

const campaign = guidedWithChecks(atStep(checkStep))
const next = { ...checkStep, key: 'climb', title: 'Climb the wall' }

describe('CheckStepForm', () => {
  it('starts each check step from its own values, not the previous step’s edits', async () => {
    const user = userEvent.setup()
    const { rerender } = render(
      <CheckStepForm
        campaign={campaign}
        step={checkStep}
        pending={false}
        onRoll={() => undefined}
      />,
    )
    await user.clear(screen.getByLabelText('Situational modifier'))
    await user.type(screen.getByLabelText('Situational modifier'), '3')
    expect(screen.getByLabelText('Situational modifier')).toHaveValue(3)

    rerender(
      <CheckStepForm campaign={campaign} step={next} pending={false} onRoll={() => undefined} />,
    )

    expect(screen.getByLabelText('Situational modifier')).toHaveValue(0)
  })

  it('shows a pool check’s dice from the sheet and asks for extra dice', async () => {
    const user = userEvent.setup()
    const onRoll = vi.fn()
    render(
      <CheckStepForm
        campaign={{ ...campaign, checks: [sneakCheck, gripCheck] }}
        step={{ ...checkStep, check: 'sneak' }}
        pending={false}
        onRoll={onRoll}
      />,
    )

    expect(
      screen.getByText('Pool: Agility + Stealth − Physical d6, success on 5+'),
    ).toBeInTheDocument()
    expect(screen.queryByLabelText('Situational modifier')).not.toBeInTheDocument()
    await user.clear(screen.getByLabelText('Extra dice'))
    await user.type(screen.getByLabelText('Extra dice'), '2')
    await user.click(screen.getByRole('button', { name: 'Roll' }))

    expect(onRoll).toHaveBeenCalledWith(expect.objectContaining({ situational: 2 }))
  })
})
