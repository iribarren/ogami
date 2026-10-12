import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderWithApi } from '@/test/renderWithApi'

import { lostMineCampaign } from '../campaigns/fixtures'
import { fateDouble, gateNote, lockedDoorAnswer, stormyWeather } from './fixtures'
import { RandomEventOffer } from './RandomEventOffer'

const campaignId = lostMineCampaign.id
const rollPath = `POST /api/campaigns/${campaignId}/journal/oracle-tables/weather`

function renderOffer(
  entry: Parameters<typeof RandomEventOffer>[0]['entry'],
  handlers: Record<string, FakeHandler> = {},
) {
  return renderWithApi(
    <RandomEventOffer
      campaignId={campaignId}
      entry={entry}
      tables={lostMineCampaign.oracleTables}
    />,
    handlers,
  )
}

describe('RandomEventOffer', () => {
  it('offers to roll the table a random event names, by the table name', () => {
    renderOffer(fateDouble)

    expect(screen.getByRole('button', { name: 'Roll Weather' })).toBeEnabled()
  })

  it('rolls that table through the oracle table request', async () => {
    const { fakeApi } = renderOffer(fateDouble, {
      [rollPath]: () => Response.json(stormyWeather, { status: 201 }),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll Weather' }))

    expect(fakeApi.requests).toEqual([rollPath])
  })

  it('replaces the button with a "Rolled" mark after a success, so it cannot roll again', async () => {
    const { fakeApi } = renderOffer(fateDouble, {
      [rollPath]: () => Response.json(stormyWeather, { status: 201 }),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll Weather' }))

    expect(await screen.findByText('Rolled Weather')).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    expect(fakeApi.requests).toEqual([rollPath])
  })

  it('keeps the button usable when the roll fails', async () => {
    renderOffer(fateDouble, {
      [rollPath]: () => Response.json({ error: 'Start a scene first.' }, { status: 409 }),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll Weather' }))
    await screen.findByRole('alert')

    expect(screen.getByRole('button', { name: 'Roll Weather' })).toBeEnabled()
  })

  it('shows the refusal when the roll fails', async () => {
    renderOffer(fateDouble, {
      [rollPath]: () => Response.json({ error: 'Start a scene first.' }, { status: 409 }),
    })
    const user = userEvent.setup()

    await user.click(screen.getByRole('button', { name: 'Roll Weather' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Start a scene first.')
  })

  it.each([
    ['an answer without a random event', lockedDoorAnswer],
    ['a note', gateNote],
    ['an oracle table result', stormyWeather],
  ])('offers nothing for %s', (_name, entry) => {
    renderOffer(entry)

    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
