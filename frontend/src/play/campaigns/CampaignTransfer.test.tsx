import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { oldCharacter, viCharacter } from '../characters/fixtures'
import { freeJournal, freeJournalV3, lostMineCampaign } from './fixtures'
import type { Campaign } from './useCampaigns'

// Radix checkboxes in a form render a hidden input whose size it measures.
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
  },
)

const flow = (key: string, name: string, isDefault: boolean): Campaign['flows'][number] => ({
  key,
  name,
  description: null,
  introduction: null,
  default: isDefault,
  defaultView: 'journal',
  oracles: [],
  trackers: [],
})

/** A one-shot "The lost mine" played along the heist Flow, with two Characters. */
const source: Campaign = {
  ...lostMineCampaign,
  flowKey: 'heist',
  flows: [flow('heist', 'Heist', true), flow('campaign', 'Making a Name', false)],
  characters: [viCharacter, oldCharacter],
}
const created: Campaign = {
  ...lostMineCampaign,
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6777',
  name: 'The lost mine: Making a Name',
}
const sourcePath = `/api/campaigns/${source.id}`

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

/** The Play screen of `campaign`; the transfer answers `transferred`, recording the body sent. */
function renderPlayScreen(
  campaign: Campaign = source,
  transferred: FakeHandler = json(created, 201),
  latest: unknown[] = [freeJournal],
) {
  const sent: unknown[] = []
  const rendered = renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${sourcePath}`]: json(campaign),
    [`GET ${sourcePath}/journal`]: json([]),
    [`GET /api/campaigns/${created.id}`]: json(created),
    [`GET /api/campaigns/${created.id}/journal`]: json([]),
    'GET /api/campaigns': json([]),
    'GET /api/play/game-systems': json(latest),
    [`POST ${sourcePath}/transfer`]: async (request) => {
      sent.push(await request.clone().json())
      return transferred(request)
    },
  })
  return { ...rendered, sent }
}

async function openDialog(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Continue in a new campaign' }))
  return screen.findByRole('dialog')
}

describe('continuing a campaign in a new one', () => {
  it('prefills the name and the release default Flow, with every Character checked', async () => {
    renderPlayScreen()
    const dialog = await openDialog(userEvent.setup())

    expect(within(dialog).getByLabelText('New campaign name')).toHaveValue('The lost mine: Heist')
    expect(within(dialog).getByLabelText('Flow')).toHaveDisplayValue('Heist')
    expect(
      within(dialog)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['Play freely', 'Heist', 'Making a Name'])
    expect(within(dialog).getByRole('checkbox', { name: 'Vi' })).toBeChecked()
    expect(within(dialog).getByRole('checkbox', { name: 'Old hand' })).toBeChecked()
  })

  it('follows the chosen Flow in the name until the player types one', async () => {
    const user = userEvent.setup()
    renderPlayScreen()
    const dialog = await openDialog(user)
    const name = within(dialog).getByLabelText('New campaign name')

    await user.selectOptions(within(dialog).getByLabelText('Flow'), 'Making a Name')
    expect(name).toHaveValue('The lost mine: Making a Name')
    await user.selectOptions(within(dialog).getByLabelText('Flow'), 'Play freely')
    expect(name).toHaveValue('The lost mine: Free play')

    await user.clear(name)
    await user.type(name, 'Second job')
    await user.selectOptions(within(dialog).getByLabelText('Flow'), 'Heist')
    expect(name).toHaveValue('Second job')
  })

  it('sends the name, the Flow and the checked Characters, then opens the new campaign', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlayScreen()
    const dialog = await openDialog(user)

    await user.selectOptions(within(dialog).getByLabelText('Flow'), 'Making a Name')
    await user.click(within(dialog).getByRole('checkbox', { name: 'Old hand' }))
    await user.click(within(dialog).getByRole('button', { name: 'Create new campaign' }))

    await screen.findByRole('heading', { level: 1, name: 'The lost mine: Making a Name' })
    expect(sent).toEqual([
      { name: 'The lost mine: Making a Name', flowKey: 'campaign', characterIds: [viCharacter.id] },
    ])
  })

  it('plays freely with a null Flow key and may bring no Character', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlayScreen()
    const dialog = await openDialog(user)

    await user.selectOptions(within(dialog).getByLabelText('Flow'), 'Play freely')
    await user.click(within(dialog).getByRole('checkbox', { name: 'Vi' }))
    await user.click(within(dialog).getByRole('checkbox', { name: 'Old hand' }))
    await user.click(within(dialog).getByRole('button', { name: 'Create new campaign' }))

    await waitFor(() => {
      expect(sent).toEqual([{ name: 'The lost mine: Free play', flowKey: null, characterIds: [] }])
    })
  })

  it('sends characters in the order of the sheet, whatever order they were checked in', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlayScreen()
    const dialog = await openDialog(user)

    await user.click(within(dialog).getByRole('checkbox', { name: 'Vi' }))
    await user.click(within(dialog).getByRole('checkbox', { name: 'Vi' }))
    await user.click(within(dialog).getByRole('button', { name: 'Create new campaign' }))

    await waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(sent[0]).toMatchObject({ characterIds: [viCharacter.id, oldCharacter.id] })
  })

  it('does not send a blank name', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlayScreen()
    const dialog = await openDialog(user)

    await user.clear(within(dialog).getByLabelText('New campaign name'))
    await user.type(within(dialog).getByLabelText('New campaign name'), '   ')

    expect(within(dialog).getByRole('button', { name: 'Create new campaign' })).toBeDisabled()
    expect(sent).toEqual([])
  })

  it('has no Flow select for a release without Flows, and says when there are no Characters', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlayScreen({ ...lostMineCampaign, flows: [], characters: [] })
    const dialog = await openDialog(user)

    expect(within(dialog).queryByLabelText('Flow')).not.toBeInTheDocument()
    expect(within(dialog).getByText('This campaign has no Characters.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('New campaign name')).toHaveValue(
      'The lost mine: Free play',
    )
    await user.click(within(dialog).getByRole('button', { name: 'Create new campaign' }))

    await waitFor(() => {
      expect(sent).toEqual([{ name: 'The lost mine: Free play', flowKey: null, characterIds: [] }])
    })
  })

  it('asks to upgrade the release first when the campaign is not on the latest one', async () => {
    const user = userEvent.setup()
    const { sent } = renderPlayScreen(source, json(created, 201), [freeJournalV3])
    const dialog = await openDialog(user)

    expect(within(dialog).getByText(/Upgrade the release first/)).toBeInTheDocument()
    expect(within(dialog).queryByLabelText('New campaign name')).not.toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: 'Create new campaign' })).toBeNull()
    expect(sent).toEqual([])
  })

  it.each([
    [404, 'Campaign not found.'],
    [409, 'The campaign plays release 1 and release 2 is the latest: upgrade the campaign first.'],
    [422, 'The Flow "x" does not exist in this release.'],
  ])('shows the API message of a %i and keeps the dialog open', async (status, error) => {
    const user = userEvent.setup()
    renderPlayScreen(source, json({ error }, status))
    const dialog = await openDialog(user)

    await user.click(within(dialog).getByRole('button', { name: 'Create new campaign' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(error)
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('says so when the request fails without a reason', async () => {
    const user = userEvent.setup()
    renderPlayScreen(source, json({ error: 'Oops' }, 500))
    const dialog = await openDialog(user)

    await user.click(within(dialog).getByRole('button', { name: 'Create new campaign' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Continuing in a new campaign failed with HTTP 500.',
    )
  })
})
