import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { lostMineWithLists, miraNpc, brannNpc, oldGuardNpc } from '../lists/fixtures'
import { viCharacter } from '../characters/fixtures'
import { castCampaign, castFocus, castWithCharacter, withCasts } from './fixtures'

const path = `/api/campaigns/${lostMineCampaign.id}`
const castUrl = `${path}/scenes/current/cast`

/** The Play screen of `campaign`; `put` answers the cast edit and `sent` collects its bodies. */
function renderCast(campaign: Campaign, put?: FakeHandler) {
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
    handlers[`PUT ${castUrl}`] = async (request) => {
      sent.push(await request.clone().json())
      return put(request)
    }
  }
  return { ...renderAppAt(`/play/campaigns/${campaign.id}`, handlers), sent, reads }
}

/** The scene as the API answers an edit: the current scene with the new cast. */
const sceneWith =
  (cast: { id: string; name: string }[]): FakeHandler =>
  () =>
    Response.json({ ...castCampaign.sessions[1]?.scenes[0], cast })

// The first render of a file is slow on a loaded machine.
const cast = async (name: string) =>
  within(await screen.findByRole('group', { name }, { timeout: 4000 }))
const currentCast = () => cast('Cast of session 2, scene 1')

describe('the Scene cast in the journal', () => {
  it('shows the NPCs of each scene in its header, the past ones without controls', async () => {
    renderCast(castCampaign)

    expect((await cast('Cast of session 1, scene 1')).getByText('Mira Vane')).toBeInTheDocument()
    expect((await cast('Cast of session 1, scene 1')).queryByRole('button')).not.toBeInTheDocument()
    expect((await currentCast()).getByText('Brann')).toBeInTheDocument()
    expect((await currentCast()).getByRole('button', { name: 'Edit cast' })).toBeInTheDocument()
  })

  it('reads a scene stored without a cast as an empty one: shown only where it can be edited', async () => {
    renderCast(withCasts(lostMineWithLists, [], []))

    expect((await currentCast()).getByText('No cast')).toBeInTheDocument()
    expect(
      screen.queryByRole('group', { name: 'Cast of session 1, scene 1' }),
    ).not.toBeInTheDocument()
    expect((await currentCast()).getByRole('button', { name: 'Edit cast' })).toBeInTheDocument()
  })

  it('adds any NPC, closed ones included, sending the whole cast, and shows the answer', async () => {
    const user = userEvent.setup()
    const { sent } = renderCast(
      castCampaign,
      sceneWith([
        { id: brannNpc.id, name: brannNpc.name },
        { id: oldGuardNpc.id, name: oldGuardNpc.name },
      ]),
    )

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))
    const editor = await currentCast()
    const options = within(editor.getByLabelText('Add to the cast')).getAllByRole('option')
    expect(options.map((option) => option.textContent)).toEqual([
      'Mira Vane',
      'The old guard (closed)',
    ])
    await user.selectOptions(editor.getByLabelText('Add to the cast'), 'The old guard (closed)')
    await user.click(editor.getByRole('button', { name: 'Add' }))

    await waitFor(() => {
      expect(sent).toEqual([{ npcIds: [brannNpc.id, oldGuardNpc.id], characterIds: [] }])
    })
    expect(await (await currentCast()).findByText('The old guard')).toBeInTheDocument()
  })

  it('removes an NPC, sending the cast without it', async () => {
    const user = userEvent.setup()
    const { sent } = renderCast(castCampaign, sceneWith([]))

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))
    await user.click((await currentCast()).getByRole('button', { name: 'Remove Brann' }))

    await waitFor(() => {
      expect(sent).toEqual([{ npcIds: [], characterIds: [] }])
    })
    expect(await (await currentCast()).findByText('No cast')).toBeInTheDocument()
  })

  it('offers no one to add when every NPC is in the cast', async () => {
    const user = userEvent.setup()
    const everyone = [miraNpc, brannNpc, oldGuardNpc].map(({ id, name }) => ({ id, name }))
    renderCast(withCasts(lostMineWithLists, [], everyone))

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))

    expect((await currentCast()).getByRole('button', { name: 'Add' })).toBeDisabled()
  })

  it.each([
    [404, 'NPC "x" not found.'],
    [422, 'NPC "x" is in the cast twice.'],
    [400, 'Send a JSON object with a list of strings "npcIds".'],
  ])('shows a refusal of %i on the cast and keeps it', async (status, message) => {
    const user = userEvent.setup()
    const { reads } = renderCast(castCampaign, () => Response.json({ error: message }, { status }))

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))
    await user.click((await currentCast()).getByRole('button', { name: 'Remove Brann' }))

    expect(await (await currentCast()).findByRole('alert')).toHaveTextContent(message)
    expect((await currentCast()).getByText('Brann')).toBeInTheDocument()
    expect(reads.campaign).toBe(1)
  })

  it('refetches the campaign on a conflict', async () => {
    const user = userEvent.setup()
    const { reads } = renderCast(castCampaign, () =>
      Response.json({ error: 'Start a scene before editing its cast.' }, { status: 409 }),
    )

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))
    await user.click((await currentCast()).getByRole('button', { name: 'Remove Brann' }))

    expect(await (await currentCast()).findByRole('alert')).toHaveTextContent(
      'Start a scene before editing its cast.',
    )
    await waitFor(() => {
      expect(reads.campaign).toBe(2)
    })
  })

  it('says a generic failure when the server gives no reason', async () => {
    const user = userEvent.setup()
    renderCast(castCampaign, () => new Response(null, { status: 500 }))

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))
    await user.click((await currentCast()).getByRole('button', { name: 'Remove Brann' }))

    expect(await (await currentCast()).findByRole('alert')).toHaveTextContent(
      'Editing the cast failed with HTTP 500.',
    )
  })
})

describe('Characters in the Scene cast', () => {
  const vi = { id: viCharacter.id, name: viCharacter.name }

  it('shows the Characters of the scene next to its NPCs', async () => {
    renderCast(castWithCharacter)

    const current = await currentCast()
    expect(current.getByText('Brann')).toBeInTheDocument()
    expect(current.getByText('Vi')).toBeInTheDocument()
    expect((await cast('Cast of session 1, scene 1')).queryByText('Vi')).not.toBeInTheDocument()
  })

  it('shows a scene with only Characters as a cast, and one with none as before', async () => {
    renderCast(withCasts(castWithCharacter, [], []))

    expect((await currentCast()).getByText('Vi')).toBeInTheDocument()
    expect((await currentCast()).queryByText('No cast')).not.toBeInTheDocument()
  })

  it('removes a Character, sending the NPCs and the remaining Characters', async () => {
    const user = userEvent.setup()
    const { sent } = renderCast(castWithCharacter, () =>
      Response.json({ ...castWithCharacter.sessions[1]?.scenes[0], characters: [] }),
    )

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))
    await user.click((await currentCast()).getByRole('button', { name: 'Remove Vi' }))

    await waitFor(() => {
      expect(sent).toEqual([{ npcIds: [brannNpc.id], characterIds: [] }])
    })
    await waitFor(async () => {
      expect((await currentCast()).queryByText('Vi')).not.toBeInTheDocument()
    })
  })

  it('adds a Character of the campaign, sending the whole cast of both', async () => {
    const user = userEvent.setup()
    const withoutVi = {
      ...castWithCharacter,
      sessions: castWithCharacter.sessions.map((session) => ({
        ...session,
        scenes: session.scenes.map((scene) => ({ ...scene, characters: [] })),
      })),
    }
    const { sent } = renderCast(withoutVi, () =>
      Response.json({ ...withoutVi.sessions[1]?.scenes[0], characters: [vi] }),
    )

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))
    const editor = await currentCast()
    await user.selectOptions(editor.getByLabelText('Add to the cast'), 'Vi (Character)')
    await user.click(editor.getByRole('button', { name: 'Add' }))

    await waitFor(() => {
      expect(sent).toEqual([{ npcIds: [brannNpc.id], characterIds: [viCharacter.id] }])
    })
    expect(await (await currentCast()).findByText('Vi')).toBeInTheDocument()
  })

  it('shows the refusal of a Character the campaign does not know', async () => {
    const user = userEvent.setup()
    renderCast(castWithCharacter, () =>
      Response.json({ error: 'Character "x" not found.' }, { status: 404 }),
    )

    await user.click((await currentCast()).getByRole('button', { name: 'Edit cast' }))
    await user.click((await currentCast()).getByRole('button', { name: 'Remove Vi' }))

    expect(await (await currentCast()).findByRole('alert')).toHaveTextContent(
      'Character "x" not found.',
    )
    expect((await currentCast()).getByText('Vi')).toBeInTheDocument()
  })
})

describe('the Scene cast in focus mode', () => {
  it('shows the cast of the current scene and edits it', async () => {
    const user = userEvent.setup()
    const { sent } = renderCast(castFocus, sceneWith([]))

    const focus = within(await screen.findByRole('region', { name: 'Focus mode' }))
    expect(focus.getByText('Brann')).toBeInTheDocument()
    expect(focus.queryByText('Mira Vane')).not.toBeInTheDocument()
    await user.click(focus.getByRole('button', { name: 'Edit cast' }))
    await user.click(focus.getByRole('button', { name: 'Remove Brann' }))

    await waitFor(() => {
      expect(sent).toEqual([{ npcIds: [], characterIds: [] }])
    })
  })
})
