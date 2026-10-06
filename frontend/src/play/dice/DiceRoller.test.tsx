import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'
import { renderWithApi } from '@/test/renderWithApi'

import { DiceRoller } from './DiceRoller'
import type { Roll } from './useRollDice'

const fourD6KeepThree: Roll = {
  expression: '4d6kh3+2',
  total: 17,
  groups: [
    {
      notation: '4d6kh3',
      sides: 6,
      dice: [
        { value: 6, kept: true },
        { value: 1, kept: false },
        { value: 5, kept: true },
        { value: 4, kept: true },
      ],
      subtotal: 15,
    },
  ],
}

/** Answers `POST /api/rolls` with `roll`, recording each expression sent. */
function rollsWith(roll: Roll, sent: string[] = []): FakeHandler {
  return async (request) => {
    const body = (await request.json()) as { expression: string }
    sent.push(body.expression)
    return Response.json(roll)
  }
}

function result() {
  return screen.getByRole('region', { name: 'Roll result' })
}

describe('DiceRoller', () => {
  it('rolls the typed dice expression and shows the total and every die', async () => {
    const sent: string[] = []
    renderWithApi(<DiceRoller />, { 'POST /api/rolls': rollsWith(fourD6KeepThree, sent) })
    const user = userEvent.setup()

    await user.type(screen.getByLabelText('Dice expression'), '4d6k3 + 2{Enter}')

    expect(await within(result()).findByText('4d6kh3+2')).toBeInTheDocument()
    expect(sent).toEqual(['4d6k3 + 2'])
    expect(result()).toHaveAttribute('aria-live', 'polite')
    expect(within(result()).getByText('17')).toHaveAccessibleDescription('Total')
    const dice = within(result()).getByRole('list', { name: '4d6kh3 dice' })
    expect(
      within(dice)
        .getAllByRole('listitem')
        .map((die) => die.textContent),
    ).toEqual(['6', '1 (dropped)', '5', '4'])
  })

  it('de-emphasizes dropped dice', async () => {
    renderWithApi(<DiceRoller initialExpression="4d6kh3+2" />, {
      'POST /api/rolls': rollsWith(fourD6KeepThree),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll' }))

    const dice = await within(result()).findByRole('list', { name: '4d6kh3 dice' })
    const [kept, dropped] = within(dice).getAllByRole('listitem')
    expect(dropped).toHaveClass('line-through')
    expect(kept).not.toHaveClass('line-through')
  })

  it('shows the API message when the expression is invalid', async () => {
    renderWithApi(<DiceRoller />, {
      'POST /api/rolls': () =>
        Response.json({ error: 'Unexpected character "x" at position 2.' }, { status: 422 }),
    })
    const user = userEvent.setup()

    await user.type(screen.getByLabelText('Dice expression'), '2x6')
    await user.click(screen.getByRole('button', { name: 'Roll' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Unexpected character "x" at position 2.',
    )
  })

  it('shows a generic message when the API fails unexpectedly', async () => {
    renderWithApi(<DiceRoller initialExpression="d20" />, {
      'POST /api/rolls': () => new Response('Oops', { status: 500 }),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Rolling the dice failed with HTTP 500.',
    )
  })

  it('rolls a preset immediately and reports the roll', async () => {
    const sent: string[] = []
    const rolled: Roll[] = []
    const d100: Roll = {
      expression: 'd100',
      total: 42,
      groups: [{ notation: 'd100', sides: 100, dice: [{ value: 42, kept: true }], subtotal: 42 }],
    }
    renderWithApi(<DiceRoller onRolled={(roll) => rolled.push(roll)} />, {
      'POST /api/rolls': rollsWith(d100, sent),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll d%' }))

    expect(await within(result()).findByRole('list', { name: 'd100 dice' })).toHaveTextContent('42')
    expect(sent).toEqual(['d%'])
    expect(rolled).toEqual([d100])
    expect(screen.getByLabelText('Dice expression')).toHaveValue('d%')
  })

  it('disables rolling while a roll is pending', async () => {
    let answer: (response: Response) => void = () => undefined
    renderWithApi(<DiceRoller initialExpression="4d6kh3+2" />, {
      'POST /api/rolls': () =>
        new Promise<Response>((resolve) => {
          answer = resolve
        }),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll' }))

    expect(screen.getByRole('button', { name: 'Roll' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Roll d20' })).toBeDisabled()
    answer(Response.json(fourD6KeepThree))
    expect(await within(result()).findByText('4d6kh3+2')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Roll' })).toBeEnabled()
  })

  it('is on the Play home page', async () => {
    renderAppAt('/play', {
      'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
      'GET /api/campaigns': () => Response.json([]),
      'GET /api/play/game-systems': () => Response.json([]),
      'POST /api/rolls': rollsWith(fourD6KeepThree),
    })

    expect(await screen.findByRole('heading', { level: 1, name: 'Play' })).toBeInTheDocument()
    expect(screen.getByLabelText('Dice expression')).toBeInTheDocument()
  })
})
