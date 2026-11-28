import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { lostMineCampaign } from '../campaigns/fixtures'
import { gateNote, keepThreeRoll, recordedEntry, skillCheckEntry } from '../journal/fixtures'
import { renderTableOf } from './testTable'

const rolled = recordedEntry({
  kind: 'roll',
  expression: '2d6',
  total: 9,
  groups: [
    {
      notation: '2d6',
      sides: 6,
      dice: [
        { value: 4, kept: true },
        { value: 5, kept: true },
      ],
      subtotal: 9,
    },
  ],
})

const tray = async () => within(await screen.findByRole('region', { name: 'Dice tray' }))

describe('the dice tray', () => {
  it('records a roll with the expression of a preset', async () => {
    const user = userEvent.setup()
    const { sent } = renderTableOf(lostMineCampaign, {
      'journal/rolls': () => Response.json(rolled),
    })

    await user.click((await tray()).getByRole('button', { name: 'Roll 2d6' }))

    await waitFor(() => {
      expect(sent['journal/rolls']).toEqual({ expression: '2d6' })
    })
  })

  it('shows the latest roll as dice on their results, with the total', async () => {
    renderTableOf(lostMineCampaign, {}, [gateNote, keepThreeRoll])

    const latest = within(await (await tray()).findByRole('region', { name: 'Latest roll' }))
    const dice = within(latest.getByRole('list', { name: 'Dice' }))
    expect(dice.getAllByRole('listitem')).toHaveLength(4)
    expect(latest.getByText('Total 17')).toBeInTheDocument()
    expect(latest.getByText('4d6kh3+2')).toBeInTheDocument()
  })

  it('shows the roll of a check, and a die without a shape as a number', async () => {
    renderTableOf(lostMineCampaign, {}, [skillCheckEntry])

    const latest = within(await (await tray()).findByRole('region', { name: 'Latest roll' }))
    expect(latest.getByText('Skill check: 1d10')).toBeInTheDocument()
    expect(latest.getByText('7')).toBeInTheDocument()
    expect(latest.getByText('Total 15')).toBeInTheDocument()
  })

  it('says no dice were thrown while the journal has no roll', async () => {
    renderTableOf(lostMineCampaign)

    expect(await (await tray()).findByText('No dice thrown yet.')).toBeInTheDocument()
  })

  it('cannot throw without a current scene, as on the Play page', async () => {
    renderTableOf({ ...lostMineCampaign, currentSceneNumber: null })

    const dice = await tray()
    expect(dice.getByRole('button', { name: 'Roll 2d6' })).toBeDisabled()
    expect(dice.getByText(/Start a scene to throw dice/)).toBeInTheDocument()
  })

  it('opens the tray in a sheet from the dock', async () => {
    const user = userEvent.setup()
    renderTableOf(lostMineCampaign, {}, [keepThreeRoll])

    await user.click(await screen.findByRole('button', { name: 'Dice' }))

    const sheet = within(await screen.findByRole('dialog', { name: 'Dice tray' }))
    expect(sheet.getByRole('button', { name: 'Roll d20' })).toBeEnabled()
    expect(await sheet.findByText('Total 17')).toBeInTheDocument()
  })
})
