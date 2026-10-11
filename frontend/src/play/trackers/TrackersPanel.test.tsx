import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import {
  alarmTracker,
  edgeTracker,
  heistTrackers,
  lostMineCampaign,
  lostMineWithTypes,
} from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { atStep, promptStep } from '../flow/fixtures'

const path = `/api/campaigns/${lostMineWithTypes.id}`
const alarmPath = `PUT ${path}/trackers/alarm`

/** The Play screen of `campaign`; `put` answers a tracker edit, recording the bodies sent. */
function renderTrackers(
  campaign: Campaign,
  handlers: Record<string, FakeHandler> = {},
  sent: unknown[] = [],
) {
  const server = { campaign }
  const result = renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(server.campaign),
    [`GET ${path}/journal`]: () => Response.json([]),
    ...Object.fromEntries(
      Object.entries(handlers).map(([route, handler]) => [
        route,
        async (request: Request) => {
          sent.push(await request.clone().json())
          return handler(request)
        },
      ]),
    ),
  })
  return { ...result, server }
}

async function panel() {
  return within(await screen.findByRole('region', { name: 'Trackers' }))
}

describe('the trackers panel', () => {
  it('shows each Tracker with its value, level and hint', async () => {
    renderTrackers(lostMineWithTypes)

    const trackers = await panel()
    const [alarm, heat, edge] = trackers.getAllByRole('listitem') as [
      HTMLElement,
      HTMLElement,
      HTMLElement,
    ]
    expect([alarm, heat, edge].map((item) => item.querySelector('span')?.textContent)).toEqual([
      'Alarm',
      'Heat',
      'Edge',
    ])
    expect(within(alarm).getByText('2/6')).toBeInTheDocument()
    expect(within(alarm).getByText('At 6/6 security locks down')).toBeInTheDocument()
    expect(within(heat).getByText('Warm')).toBeInTheDocument()
    expect(within(edge).queryByText('Warm')).not.toBeInTheDocument()
    expect(within(edge).getByLabelText('Edge value')).toHaveValue(1)
  })

  it('is hidden when the release has no Trackers', async () => {
    renderTrackers({ ...lostMineWithTypes, trackers: [] })

    await screen.findByRole('heading', { level: 1, name: 'The lost mine' })
    expect(screen.queryByRole('region', { name: 'Trackers' })).not.toBeInTheDocument()
    expect(lostMineCampaign.trackers).toEqual([])
  })

  it('shows only the Trackers of the Flow, in its order, while the FlowRun is active', async () => {
    renderTrackers({
      ...lostMineWithTypes,
      flowKey: 'heist',
      flowRun: atStep(promptStep, { status: 'active' }),
    })

    const trackers = await panel()
    expect(trackers.getAllByRole('listitem')).toHaveLength(2)
    expect(trackers.getByText('Alarm')).toBeInTheDocument()
    expect(trackers.getByText('Heat')).toBeInTheDocument()
    expect(trackers.queryByText('Edge')).not.toBeInTheDocument()
  })

  it.each(['paused', 'completed'] as const)(
    'shows every Tracker once the FlowRun is %s',
    async (status) => {
      renderTrackers({
        ...lostMineWithTypes,
        flowKey: 'heist',
        flowRun: atStep(promptStep, { status }),
      })

      expect((await panel()).getAllByRole('listitem')).toHaveLength(3)
    },
  )

  it('sets a value with the buttons and shows the Tracker the API answers', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderTrackers(
      lostMineWithTypes,
      { [alarmPath]: () => Response.json({ ...alarmTracker, value: 3 }) },
      sent,
    )

    await user.click(await screen.findByRole('button', { name: 'Increase Alarm' }))

    expect(await (await panel()).findByText('3/6')).toBeInTheDocument()
    expect(sent).toEqual([{ value: 3 }])
  })

  it('sends a typed value clamped to the range', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderTrackers(
      lostMineWithTypes,
      { [alarmPath]: () => Response.json({ ...alarmTracker, value: 6 }) },
      sent,
    )

    const input = await screen.findByLabelText('Alarm value')
    await user.clear(input)
    await user.type(input, '99')
    await user.click(
      within(screen.getByRole('form', { name: 'Edit Alarm' })).getByRole('button', { name: 'Set' }),
    )

    await waitFor(() => {
      expect(sent).toEqual([{ value: 6 }])
    })
  })

  it('stops the buttons at the ends of the range', async () => {
    renderTrackers({ ...lostMineWithTypes, trackers: [{ ...edgeTracker, value: 3 }] })

    expect(await screen.findByRole('button', { name: 'Increase Edge' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Decrease Edge' })).toBeEnabled()
  })

  it('shows why an edit was refused and keeps the value', async () => {
    const user = userEvent.setup()
    renderTrackers(lostMineWithTypes, {
      [alarmPath]: () => Response.json({ error: 'The campaign is gone.' }, { status: 404 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Increase Alarm' }))

    expect(await (await panel()).findByRole('alert')).toHaveTextContent('The campaign is gone.')
    expect((await panel()).getByText('2/6')).toBeInTheDocument()
  })

  it('refetches the campaign when the edit is refused with 409', async () => {
    const user = userEvent.setup()
    const { server } = renderTrackers(lostMineWithTypes, {
      [alarmPath]: () => Response.json({ error: 'Modified meanwhile.' }, { status: 409 }),
    })

    const trackers = await panel()
    server.campaign = {
      ...lostMineWithTypes,
      trackers: [{ ...alarmTracker, value: 5 }, ...heistTrackers.slice(1)],
    }
    await user.click(trackers.getByRole('button', { name: 'Increase Alarm' }))

    expect(await trackers.findByText('5/6')).toBeInTheDocument()
  })
})
