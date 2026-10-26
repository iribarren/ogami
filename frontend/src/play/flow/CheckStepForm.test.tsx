import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { gripCheck, sneakCheck } from '../campaigns/fixtures'
import { guidedWithChecks, atStep, checkSetStep, checkStep } from './fixtures'
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

  describe('a check set', () => {
    const withSet = { ...campaign, checks: [sneakCheck, gripCheck] }

    it('preselects no check and cannot roll until the player picks one', async () => {
      const user = userEvent.setup()
      const onRoll = vi.fn()
      render(
        <CheckStepForm campaign={withSet} step={checkSetStep} pending={false} onRoll={onRoll} />,
      )

      expect(screen.getByLabelText('Check')).toHaveValue('')
      expect(screen.getByRole('button', { name: 'Roll' })).toBeDisabled()
      expect(screen.queryByLabelText('Extra dice')).not.toBeInTheDocument()
      await user.selectOptions(screen.getByLabelText('Check'), 'Grip')

      expect(screen.getByRole('button', { name: 'Roll' })).toBeEnabled()
      expect(screen.getByText('Pool: 4d6, success on 6+')).toBeInTheDocument()
    })

    it('sends the picked check as the checkKey', async () => {
      const user = userEvent.setup()
      const onRoll = vi.fn()
      render(
        <CheckStepForm campaign={withSet} step={checkSetStep} pending={false} onRoll={onRoll} />,
      )

      await user.selectOptions(screen.getByLabelText('Check'), 'Sneak')
      await user.click(screen.getByRole('button', { name: 'Roll' }))

      expect(onRoll).toHaveBeenCalledWith({
        characterId: checkSetStep.characters[0]?.id,
        difficulty: undefined,
        situational: undefined,
        checkKey: 'sneak',
      })
    })

    it('sends no checkKey for a single-check step', async () => {
      const user = userEvent.setup()
      const onRoll = vi.fn()
      render(<CheckStepForm campaign={campaign} step={checkStep} pending={false} onRoll={onRoll} />)

      await user.click(screen.getByRole('button', { name: 'Roll' }))

      expect(onRoll).toHaveBeenCalledOnce()
      expect(onRoll.mock.calls[0]?.[0]).not.toHaveProperty('checkKey')
    })

    it('fails closed when a check of the set is not in the release', () => {
      render(
        <CheckStepForm
          campaign={{ ...campaign, checks: [sneakCheck] }}
          step={checkSetStep}
          pending={false}
          onRoll={() => undefined}
        />,
      )

      expect(screen.getByRole('alert')).toHaveTextContent(
        'This step rolls the check “grip”, which the release does not define.',
      )
    })
  })
})
