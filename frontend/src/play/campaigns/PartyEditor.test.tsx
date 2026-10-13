import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { nightJobCampaign, viCharacter } from '../characters/fixtures'
import { lostMineCampaign } from './fixtures'
import type { Campaign } from './useCampaigns'

const path = `/api/campaigns/${nightJobCampaign.id}`
const partyUrl = `${path}/sessions/current/party`
const kay = { ...viCharacter, id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9003', name: 'Kay' }
const ref = ({ id, name }: { id: string; name: string }) => ({ id, name })

/** The Night Job campaign in session 2, with Vi and Kay as Characters and `party` playing. */
function inSession(party: { id: string; name: string }[]): Campaign {
  return {
    ...nightJobCampaign,
    characters: [viCharacter, kay],
    sessions: nightJobCampaign.sessions.map((session) =>
      session.number === nightJobCampaign.currentSessionNumber ? { ...session, party } : session,
    ),
  }
}

/** The Play screen of `campaign`; `put` answers the party edit, `sent` collects its bodies. */
function renderParty(campaign: Campaign, put?: FakeHandler) {
  const sent: unknown[] = []
  const reads = { campaign: 0 }
  const handlers: Record<string, FakeHandler> = {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => {
      reads.campaign += 1
      return Response.json(campaign)
    },
    [`GET ${path}/journal`]: () => Response.json([]),
  }
  if (put !== undefined) {
    handlers[`PUT ${partyUrl}`] = async (request) => {
      sent.push(await request.clone().json())
      return put(request)
    }
  }
  return { ...renderAppAt(`/play/campaigns/${campaign.id}`, handlers), sent, reads }
}

const sessionWith = (party: { id: string; name: string }[]): FakeHandler => {
  return () => Response.json({ ...inSession(party).sessions[1], party }, { status: 200 })
}

async function party() {
  return within(await screen.findByRole('group', { name: 'Party' }, { timeout: 4000 }))
}

describe('the party of the current Session', () => {
  it('shows who is in the party', async () => {
    renderParty(inSession([ref(viCharacter)]))

    const panel = await party()
    expect(panel.getByText('Vi')).toBeInTheDocument()
    expect(panel.queryByText('Kay')).not.toBeInTheDocument()
  })

  it('reads a session stored without a party as an empty one', async () => {
    renderParty(inSession([]))

    expect((await party()).getByText('No party')).toBeInTheDocument()
  })

  it('is hidden without Characters or without a current Session', async () => {
    renderParty({ ...inSession([]), characters: [] })
    await screen.findByRole('heading', { level: 1, name: nightJobCampaign.name })
    expect(screen.queryByRole('group', { name: 'Party' })).not.toBeInTheDocument()
  })

  it('is hidden before the first Session', async () => {
    renderParty({
      ...inSession([]),
      currentSessionNumber: null,
      currentSceneNumber: null,
      sessions: [],
    })
    await screen.findByRole('heading', { level: 1, name: lostMineCampaign.name })
    expect(screen.queryByRole('group', { name: 'Party' })).not.toBeInTheDocument()
  })

  it('edits the party: keeps the order of those who stay, then adds, and shows the answer', async () => {
    const user = userEvent.setup()
    const { sent } = renderParty(
      inSession([ref(viCharacter)]),
      sessionWith([ref(viCharacter), ref(kay)]),
    )

    await user.click((await party()).getByRole('button', { name: 'Edit party' }))
    const editor = await party()
    expect(editor.getByRole('checkbox', { name: 'Vi' })).toBeChecked()
    await user.click(editor.getByRole('checkbox', { name: 'Kay' }))
    await user.click(editor.getByRole('button', { name: 'Save party' }))

    await waitFor(() => {
      expect(sent).toEqual([{ characterIds: [viCharacter.id, kay.id] }])
    })
    expect(await (await party()).findByText('Kay')).toBeInTheDocument()
    expect((await party()).getByRole('button', { name: 'Edit party' })).toBeInTheDocument()
  })

  it('can empty the party', async () => {
    const user = userEvent.setup()
    const { sent } = renderParty(inSession([ref(viCharacter)]), sessionWith([]))

    await user.click((await party()).getByRole('button', { name: 'Edit party' }))
    await user.click((await party()).getByRole('checkbox', { name: 'Vi' }))
    await user.click((await party()).getByRole('button', { name: 'Save party' }))

    await waitFor(() => {
      expect(sent).toEqual([{ characterIds: [] }])
    })
    expect(await (await party()).findByText('No party')).toBeInTheDocument()
  })

  it('cancels without sending anything', async () => {
    const user = userEvent.setup()
    const { sent } = renderParty(inSession([ref(viCharacter)]), sessionWith([]))

    await user.click((await party()).getByRole('button', { name: 'Edit party' }))
    await user.click((await party()).getByRole('checkbox', { name: 'Vi' }))
    await user.click((await party()).getByRole('button', { name: 'Cancel' }))

    expect(sent).toEqual([])
    expect((await party()).getByText('Vi')).toBeInTheDocument()
  })

  it.each([
    [404, 'Character "x" not found.'],
    [422, 'The party lists a Character twice.'],
  ])('shows a refusal of %i and keeps the editor open', async (status, message) => {
    const user = userEvent.setup()
    const { reads } = renderParty(inSession([ref(viCharacter)]), () =>
      Response.json({ error: message }, { status }),
    )

    await user.click((await party()).getByRole('button', { name: 'Edit party' }))
    await user.click((await party()).getByRole('button', { name: 'Save party' }))

    expect(await (await party()).findByRole('alert')).toHaveTextContent(message)
    expect((await party()).getByRole('button', { name: 'Save party' })).toBeEnabled()
    expect(reads.campaign).toBe(1)
  })

  it('refetches the campaign on a conflict and shows the message', async () => {
    const user = userEvent.setup()
    const { reads } = renderParty(inSession([ref(viCharacter)]), () =>
      Response.json({ error: 'No session is under way.' }, { status: 409 }),
    )

    await user.click((await party()).getByRole('button', { name: 'Edit party' }))
    await user.click((await party()).getByRole('button', { name: 'Save party' }))

    expect(await (await party()).findByRole('alert')).toHaveTextContent('No session is under way.')
    await waitFor(() => {
      expect(reads.campaign).toBe(2)
    })
  })

  it('disables Save party while the edit is pending', async () => {
    const user = userEvent.setup()
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const { sent } = renderParty(inSession([ref(viCharacter)]), async () => {
      await gate
      return Response.json(inSession([ref(viCharacter)]).sessions[1])
    })

    await user.click((await party()).getByRole('button', { name: 'Edit party' }))
    await user.click((await party()).getByRole('button', { name: 'Save party' }))

    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect((await party()).getByRole('button', { name: 'Save party' })).toBeDisabled()
    release()
  })
})
