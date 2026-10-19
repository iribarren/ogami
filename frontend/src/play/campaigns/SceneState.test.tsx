import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { atStep, guided, promptStep } from '../flow/fixtures'
import { lostMineWithTypes } from './fixtures'
import type { Campaign } from './useCampaigns'

const path = `/api/campaigns/${lostMineWithTypes.id}`

/** Session 2's scene of `campaign` with this title (and Scene Type, when given). */
function withTitle(
  campaign: Campaign,
  title: string,
  sceneType?: { key: string; name: string },
): Campaign {
  return {
    ...campaign,
    sessions: campaign.sessions.map((session) => ({
      ...session,
      scenes: session.scenes.map((scene) =>
        session.number === 2
          ? {
              ...scene,
              title,
              ...(sceneType === undefined
                ? {}
                : { sceneType: sceneType.key, sceneTypeName: sceneType.name }),
            }
          : scene,
      ),
    })),
  }
}

/** The Play screen of `campaign`; `server.campaign` is what the next refetch answers. */
function renderScreen(campaign: Campaign, handlers: Record<string, FakeHandler> = {}) {
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
    [`GET /api/campaigns/${campaign.id}`]: () => Response.json(server.campaign),
    [`GET /api/campaigns/${campaign.id}/journal`]: () => Response.json([]),
    ...recorded,
  })
  return { sent, server }
}

function standing() {
  return screen.getByRole('region', { name: 'Where play stands' })
}

const renamePath = `PUT ${path}/scenes/current/title`
const switchPath = `POST ${path}/scenes/current/scene-type`
const sceneResponse = {
  number: 1,
  title: 'The letter',
  startedAt: '2026-10-06T12:05:00+00:00',
  kind: 'scene',
  sceneType: 'legwork',
  sceneTypeName: 'Legwork',
  hook: null,
  cast: [],
  characters: [],
}

describe('renaming the current scene', () => {
  it('renames from where play stands and shows the title the server then holds', async () => {
    const user = userEvent.setup()
    const { sent, server } = renderScreen(lostMineWithTypes, {
      [renamePath]: () => Response.json(sceneResponse),
    })

    await user.click(await screen.findByRole('button', { name: 'Rename scene' }))
    const input = screen.getByLabelText('Scene name')
    expect(input).toHaveValue('Into the dark')
    expect(input).toHaveAttribute('maxlength', '100')
    server.campaign = withTitle(lostMineWithTypes, 'The letter')
    await user.clear(input)
    await user.type(input, 'The letter')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(standing()).toHaveTextContent('Session 2 · Scene 1: The letter')
    })
    expect(sent).toEqual([{ title: 'The letter' }])
    await waitFor(() => {
      expect(screen.queryByRole('form', { name: 'Rename scene' })).not.toBeInTheDocument()
    })
  })

  it('starts empty on a default title, which is not the name the player gave', async () => {
    const user = userEvent.setup()
    renderScreen(withTitle(lostMineWithTypes, 'Legwork 3', { key: 'legwork', name: 'Legwork' }))

    await user.click(await screen.findByRole('button', { name: 'Rename scene' }))

    expect(screen.getByLabelText('Scene name')).toHaveValue('')
  })

  it("shows the API's reason and keeps the form open when the name is refused", async () => {
    const user = userEvent.setup()
    renderScreen(lostMineWithTypes, {
      [renamePath]: () =>
        Response.json({ error: 'A scene title must not be blank.' }, { status: 422 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Rename scene' }))
    await user.type(screen.getByLabelText('Scene name'), ' ')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(
      await within(screen.getByRole('form', { name: 'Rename scene' })).findByRole('alert'),
    ).toHaveTextContent('A scene title must not be blank.')
    expect(standing()).toHaveTextContent('Session 2 · Scene 1: Into the dark')
  })

  it('does not save a blank name and leaves the title alone on Cancel', async () => {
    const user = userEvent.setup()
    const { sent } = renderScreen(lostMineWithTypes)

    await user.click(await screen.findByRole('button', { name: 'Rename scene' }))
    await user.clear(screen.getByLabelText('Scene name'))
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('form', { name: 'Rename scene' })).not.toBeInTheDocument()
    expect(standing()).toHaveTextContent('Session 2 · Scene 1: Into the dark')
    expect(sent).toEqual([])
  })

  it('is not offered before a scene starts', async () => {
    renderScreen({ ...lostMineWithTypes, currentSceneNumber: null })

    await screen.findByRole('region', { name: 'Where play stands' })
    expect(screen.queryByRole('button', { name: 'Rename scene' })).not.toBeInTheDocument()
  })
})

describe('the title after a Scene Type switch', () => {
  it('follows the new type when it was the old default, and shows a custom title as it is', async () => {
    const user = userEvent.setup()
    const firefight = { key: 'firefight', name: 'Firefight' }
    const { server } = renderScreen(withTitle(lostMineWithTypes, 'Legwork 1'), {
      [switchPath]: () => Response.json(sceneResponse),
    })

    expect(await screen.findByLabelText('Scene Type')).toHaveValue('legwork')
    server.campaign = withTitle(lostMineWithTypes, 'Firefight 1', firefight)
    await user.selectOptions(screen.getByLabelText('Scene Type'), 'Firefight')

    await waitFor(() => {
      expect(within(standing()).getByText(/^Session 2 · /)).toHaveTextContent(
        /^Session 2 · Scene 1: Firefight$/,
      )
    })
  })

  it('shows a title the player gave next to the Scene Type switch', async () => {
    renderScreen(withTitle(lostMineWithTypes, 'Casing the bank'))

    expect(await screen.findByLabelText('Scene Type')).toHaveValue('legwork')
    expect(standing()).toHaveTextContent('Session 2 · Scene 1: Casing the bank')
  })
})

describe('starting scenes by hand', () => {
  it('is not offered while the Flow guides, but the Scene Type switch stays', async () => {
    renderScreen({ ...guided(atStep(promptStep)), sceneTypes: lostMineWithTypes.sceneTypes })

    await screen.findByRole('region', { name: 'Where play stands' })
    expect(screen.queryByRole('form', { name: 'New scene' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Rename scene' })).toBeInTheDocument()
  })

  it.each(['paused', 'completed'] as const)(
    'is offered again once the Flow is %s',
    async (status) => {
      renderScreen(guided({ ...atStep(promptStep), status }))

      expect(await screen.findByRole('form', { name: 'New scene' })).toBeInTheDocument()
    },
  )

  it('is offered in free play', async () => {
    renderScreen(lostMineWithTypes)

    expect(await screen.findByRole('form', { name: 'New scene' })).toBeInTheDocument()
  })
})
