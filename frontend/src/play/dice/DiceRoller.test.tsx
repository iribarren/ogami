import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { DiceRoller } from './DiceRoller'

describe('DiceRoller', () => {
  it('rolls the typed dice expression', async () => {
    const rolled: string[] = []
    render(<DiceRoller onRoll={(expression) => rolled.push(expression)} />)
    const user = userEvent.setup()

    await user.type(screen.getByLabelText('Dice expression'), '4d6k3 + 2{Enter}')

    expect(rolled).toEqual(['4d6k3 + 2'])
  })

  it('does not roll a blank expression', async () => {
    const rolled: string[] = []
    render(<DiceRoller initialExpression="   " onRoll={(expression) => rolled.push(expression)} />)
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll' }))

    expect(rolled).toEqual([])
  })

  it('rolls a preset immediately and puts it in the input', async () => {
    const rolled: string[] = []
    render(<DiceRoller onRoll={(expression) => rolled.push(expression)} />)
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll d%' }))

    expect(rolled).toEqual(['d%'])
    expect(screen.getByLabelText('Dice expression')).toHaveValue('d%')
  })

  it('disables rolling while a roll is pending', () => {
    render(<DiceRoller initialExpression="d20" onRoll={() => undefined} pending />)

    expect(screen.getByRole('button', { name: 'Roll' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Roll d20' })).toBeDisabled()
    expect(screen.getByLabelText('Dice expression')).toBeEnabled()
  })

  it('disables every control when rolling is not possible', () => {
    render(<DiceRoller onRoll={() => undefined} disabled />)

    expect(screen.getByLabelText('Dice expression')).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Roll' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Roll 2d6' })).toBeDisabled()
  })

  it('shows why the last roll was refused', () => {
    render(
      <DiceRoller
        onRoll={() => undefined}
        error={new Error('Unexpected character "x" at position 2.')}
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Unexpected character "x" at position 2.')
  })

  it('can hide the presets', () => {
    render(<DiceRoller onRoll={() => undefined} presets={[]} />)

    expect(screen.queryByRole('group', { name: 'Quick rolls' })).not.toBeInTheDocument()
  })
})
