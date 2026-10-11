import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { sceneShiftRoll } from '../journal/fixtures'
import { lostMineCampaign, lostMineWithTypes } from './fixtures'
import type { Campaign } from './useCampaigns'

const path = `/api/campaigns/${lostMineWithTypes.id}`
const switchPath = `POST ${path}/scenes/current/scene-type`

/** The scene played in session 2 of `lostMineWithTypes` with another Scene Type. */
function withSceneType(sceneType: string | null, sceneTypeName: string | null): Campaign {
  return {
    ...lostMineWithTypes,
    sessions: lostMineWithTypes.sessions.map((session) => ({
      ...session,
      scenes: session.scenes.map((scene) =>
        session.number === 2 ? { ...scene, sceneType, sceneTypeName } : scene,
      ),
    })),
  }
}

/** The Play screen of `campaign`, collecting the bodies sent to the endpoints `handlers` answer. */
function renderScreen(
  campaign: Campaign,
  handlers: Record<string, FakeHandler> = {},
  journal: unknown[] = [],
) {
  const sent: unknown[] = []
  const server = { campaign }
  const recorded = Object.fromEntries(
    Object.entries(handlers).map(([route, handler]) => [
      route,
      async (request: Request) => {
        const text = await request.clone().text()
        sent.push(text === '' ? undefined : JSON.parse(text))
        return handler(request)
      },
    ]),
  )
  renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(server.campaign),
    [`GET ${path}/journal`]: () => Response.json(journal),
    ...recorded,
  })
  return { sent, server }
}

const sceneResponse = {
  number: 1,
  title: 'Into the dark',
  startedAt: '2026-10-06T12:05:00+00:00',
  kind: 'scene',
  sceneType: 'firefight',
  sceneTypeName: 'Firefight',
  hook: null,
}

describe('the Scene Type of the current scene', () => {
  it('switches by hand and shows the campaign the server then holds', async () => {
    const user = userEvent.setup()
    const { sent, server } = renderScreen(lostMineWithTypes, {
      [switchPath]: () => Response.json(sceneResponse),
    })

    const select = await screen.findByLabelText('Scene Type')
    expect(select).toHaveValue('legwork')
    server.campaign = withSceneType('firefight', 'Firefight')
    await user.selectOptions(select, 'Firefight')

    await waitFor(() => {
      expect(screen.getByLabelText('Scene Type')).toHaveValue('firefight')
    })
    expect(sent).toEqual([{ sceneType: 'firefight' }])
  })

  it('shows why a switch was refused', async () => {
    const user = userEvent.setup()
    renderScreen(lostMineWithTypes, {
      [switchPath]: () => Response.json({ error: 'The scene is a hook Scene.' }, { status: 409 }),
    })

    await user.selectOptions(await screen.findByLabelText('Scene Type'), 'Firefight')

    const controls = within(screen.getByRole('region', { name: 'Where play stands' }))
    expect(await controls.findByRole('alert')).toHaveTextContent('The scene is a hook Scene.')
  })

  it('shows a Scene Type the release does not know as unprocessable', async () => {
    const user = userEvent.setup()
    renderScreen(lostMineWithTypes, {
      [switchPath]: () => Response.json({ error: 'Unknown Scene Type.' }, { status: 422 }),
    })

    await user.selectOptions(await screen.findByLabelText('Scene Type'), 'Firefight')

    expect(await screen.findByRole('alert')).toHaveTextContent('Unknown Scene Type.')
  })

  it('offers no choice for a scene without a Scene Type until one is picked', async () => {
    renderScreen(withSceneType(null, null))

    const select = await screen.findByLabelText('Scene Type')
    expect(select).toHaveValue('')
    expect(within(select).getByRole('option', { name: 'None' })).toBeDisabled()
  })

  it.each([
    ['the release has no Scene Types', { ...lostMineWithTypes, sceneTypes: [] }],
    ['there is no scene', { ...lostMineWithTypes, currentSceneNumber: null }],
    [
      'the scene is a hook Scene',
      {
        ...lostMineWithTypes,
        sessions: lostMineWithTypes.sessions.map((session) => ({
          ...session,
          scenes: session.scenes.map((held) => ({ ...held, kind: 'hook' as const })),
        })),
      },
    ],
  ])('has no Scene Type switch when %s', async (_why, campaign) => {
    renderScreen(campaign)

    await screen.findByRole('region', { name: 'Where play stands' })
    expect(screen.queryByLabelText('Scene Type')).not.toBeInTheDocument()
  })
})

describe('starting a scene with a Scene Type', () => {
  const startPath = `POST ${path}/scenes`

  it('starts a scene named after its Scene Type when no title is typed', async () => {
    const user = userEvent.setup()
    const { sent } = renderScreen(lostMineWithTypes, {
      [startPath]: () => Response.json(lostMineWithTypes, { status: 201 }),
    })
    const form = within(await screen.findByRole('form', { name: 'New scene' }))

    expect(form.getByRole('button', { name: 'Start scene' })).toBeDisabled()
    await user.selectOptions(form.getByLabelText('Scene Type (optional)'), 'Firefight')
    await user.click(form.getByRole('button', { name: 'Start scene' }))

    await waitFor(() => {
      expect(sent).toEqual([{ sceneType: 'firefight' }])
    })
  })

  it('sends the title next to the Scene Type', async () => {
    const user = userEvent.setup()
    const { sent } = renderScreen(lostMineWithTypes, {
      [startPath]: () => Response.json(lostMineWithTypes, { status: 201 }),
    })
    const form = within(await screen.findByRole('form', { name: 'New scene' }))

    await user.type(form.getByLabelText('Scene title'), 'The vault')
    await user.selectOptions(form.getByLabelText('Scene Type (optional)'), 'Legwork')
    await user.click(form.getByRole('button', { name: 'Start scene' }))

    await waitFor(() => {
      expect(sent).toEqual([{ title: 'The vault', sceneType: 'legwork' }])
    })
  })

  it('offers no Scene Type when the release has none', async () => {
    renderScreen(lostMineCampaign)

    await screen.findByRole('form', { name: 'New scene' })
    expect(screen.queryByLabelText('Scene Type (optional)')).not.toBeInTheDocument()
  })
})

describe('the offer to switch to a rolled Scene Type', () => {
  const offer = (name: string) => screen.queryByRole('button', { name: `Switch to ${name}` })

  async function journalLoaded() {
    await screen.findByRole('article', { name: 'Oracle Scene shift' })
  }

  it('switches the current scene to the Scene Type a rolled entry offers', async () => {
    const user = userEvent.setup()
    const { sent } = renderScreen(
      lostMineWithTypes,
      { [switchPath]: () => Response.json(sceneResponse) },
      [sceneShiftRoll('firefight')],
    )

    await user.click(await screen.findByRole('button', { name: 'Switch to Firefight' }))

    await waitFor(() => {
      expect(sent).toEqual([{ sceneType: 'firefight' }])
    })
  })

  it('shows why the switch was refused', async () => {
    const user = userEvent.setup()
    renderScreen(
      lostMineWithTypes,
      { [switchPath]: () => Response.json({ error: 'No current scene.' }, { status: 409 }) },
      [sceneShiftRoll('firefight')],
    )

    await user.click(await screen.findByRole('button', { name: 'Switch to Firefight' }))

    const entry = within(
      screen.getByRole('article', { name: 'Oracle Scene shift' }).closest('li') as HTMLElement,
    )
    expect(await entry.findByRole('alert')).toHaveTextContent('No current scene.')
  })

  it.each([
    ['the scene already has that Scene Type', lostMineWithTypes, sceneShiftRoll('legwork')],
    ['the entry offers none', lostMineWithTypes, sceneShiftRoll(null)],
    ['the release does not list it', lostMineWithTypes, sceneShiftRoll('chase')],
    [
      'the entry is in an earlier scene',
      lostMineWithTypes,
      sceneShiftRoll('firefight', { sessionNumber: 1, sceneNumber: 1 }),
    ],
    [
      'the session has ended',
      { ...lostMineWithTypes, currentSessionNumber: null, currentSceneNumber: null },
      sceneShiftRoll('firefight'),
    ],
  ])('is not made when %s', async (_why, campaign, entry) => {
    renderScreen(campaign, {}, [entry])

    await journalLoaded()
    expect(offer('Firefight')).not.toBeInTheDocument()
    expect(offer('Legwork')).not.toBeInTheDocument()
  })
})
