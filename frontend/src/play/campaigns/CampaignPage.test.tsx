import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { atStep, guided, promptStep } from '../flow/fixtures'
import { lostMineJournal, recordedEntry } from '../journal/fixtures'
import {
  fanPreset,
  freeJournal,
  lostMine,
  lostMineCampaign,
  lostMineWithTypes,
  lostMineInNewSession,
  newCampaign,
} from './fixtures'
import type { Campaign } from './useCampaigns'

const soloPlayer = signedInAs('SOLO_PLAYER')
const created: Campaign = newCampaign('The haunted keep', freeJournal)
const campaignPath = (campaign: Campaign) => `/api/campaigns/${campaign.id}`
const noSceneHint = 'Start a scene to write in the journal.'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

/** Answers with `body`, recording each JSON request body sent. */
function recording(body: unknown, sent: unknown[], status = 201): FakeHandler {
  return async (request) => {
    const text = await request.text()
    sent.push(text === '' ? undefined : JSON.parse(text))
    return Response.json(body, { status })
  }
}

/** The Play screen of `campaign` with its journal, plus any extra API handlers. */
function renderPlayScreen(
  campaign: Campaign,
  journal: unknown[] = [],
  handlers: Record<string, FakeHandler> = {},
) {
  return renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': soloPlayer,
    [`GET ${campaignPath(campaign)}`]: json(campaign),
    [`GET ${campaignPath(campaign)}/journal`]: json(journal),
    'GET /api/play/game-systems': json([freeJournal]),
    ...handlers,
  })
}

function playPosition() {
  return screen.getByRole('region', { name: 'Where play stands' })
}

function journalRegion() {
  return screen.getByRole('region', { name: 'Journal' })
}

async function findJournalScene(session: number, scene: string) {
  const journal = await screen.findByRole('region', { name: 'Journal' })
  const sessionRegion = await within(journal).findByRole('region', {
    name: `Session ${String(session)}`,
  })
  return within(sessionRegion).getByRole('region', { name: scene })
}

describe('the Play screen', () => {
  afterEach(() => {
    // Panels remember being collapsed in this browser; start every test with them open.
    localStorage.clear()
  })

  it('shows the campaign name, its pinned release and where play stands', async () => {
    renderPlayScreen(lostMineCampaign)

    expect(
      await screen.findByRole('heading', { level: 1, name: 'The lost mine' }),
    ).toBeInTheDocument()
    // The title mark and the divider are decorative: the heading keeps its plain name.
    const heading = screen.getByRole('heading', { level: 1 })
    expect(heading).toHaveTextContent(/^The lost mine$/)
    expect(heading.previousElementSibling).toHaveAttribute('aria-hidden', 'true')
    expect(screen.getByText('Free journal v2')).toBeInTheDocument()
    expect(playPosition()).toHaveTextContent('Session 2 · Scene 1: Into the dark')
    expect(screen.getByRole('link', { name: 'Back to your campaigns' })).toHaveAttribute(
      'href',
      '/play',
    )
  })

  it('shows the credits of the pinned release in an About this game area', async () => {
    const campaign: Campaign = {
      ...lostMineCampaign,
      pinnedRelease: { ...lostMineCampaign.pinnedRelease, credits: fanPreset.credits },
    }
    renderPlayScreen(campaign)

    const about = within(await screen.findByRole('region', { name: 'About this game' }))
    expect(about.getByText(/is not approved or endorsed by the publisher/)).toBeInTheDocument()
    expect(about.getByRole('link', { name: 'Homebrew Content Policy' })).toHaveAttribute(
      'href',
      'https://example.com/homebrew-policy',
    )
  })

  it('shows no About this game area when the release has no credits', async () => {
    renderPlayScreen(lostMineCampaign)

    await screen.findByRole('heading', { level: 1, name: 'The lost mine' })
    expect(screen.queryByRole('region', { name: 'About this game' })).not.toBeInTheDocument()
  })

  it('applies the theme of the pinned release while the campaign is open', async () => {
    const root = document.documentElement
    const themed: Campaign = {
      ...lostMineCampaign,
      pinnedRelease: { ...lostMineCampaign.pinnedRelease, theme: 'noir' },
    }
    const { router } = renderPlayScreen(themed, [], { 'GET /api/campaigns': json([]) })

    await screen.findByRole('heading', { level: 1, name: 'The lost mine' })
    expect(root).toHaveAttribute('data-theme', 'noir')
    expect(root).toHaveClass('dark')

    router.history.push('/play')

    await waitFor(() => {
      expect(root).not.toHaveAttribute('data-theme')
    })
  })

  it('leaves the default theme when the pinned release names none', async () => {
    renderPlayScreen(lostMineCampaign)

    await screen.findByRole('heading', { level: 1, name: 'The lost mine' })
    expect(document.documentElement).not.toHaveAttribute('data-theme')
  })

  it('keeps the tools in a Play tools sidebar of collapsible panels, with counts where known', async () => {
    const user = userEvent.setup()
    renderPlayScreen(lostMineCampaign)

    const tools = await screen.findByRole('complementary', { name: 'Play tools' })
    for (const name of ['Dice', 'Facts', 'Threads', 'NPCs', 'Oracles']) {
      expect(within(tools).getByRole('region', { name })).toBeInTheDocument()
    }
    expect(
      within(within(tools).getByRole('region', { name: 'NPCs' })).getByText(
        String(lostMineCampaign.npcs.length),
      ),
    ).toBeInTheDocument()

    await user.click(within(tools).getByRole('button', { name: 'Dice' }))

    expect(within(tools).getByRole('button', { name: 'Dice' })).toHaveAttribute(
      'aria-expanded',
      'false',
    )
    expect(within(tools).queryByRole('form', { name: 'Roll dice' })).not.toBeInTheDocument()
  })

  it('shows the journal grouped by session and scene, entries in recorded order', async () => {
    renderPlayScreen(lostMineCampaign, lostMineJournal)

    const atTheGate = await findJournalScene(1, 'Scene 1 — At the gate')
    expect(
      within(atTheGate)
        .getAllByRole('article')
        .map((entry) => entry.getAttribute('aria-label')),
    ).toEqual(['Note', 'Roll 4d6kh3+2'])
    const intoTheDark = await findJournalScene(2, 'Scene 1 — Into the dark')
    expect(
      within(intoTheDark)
        .getAllByRole('article')
        .map((entry) => entry.getAttribute('aria-label')),
    ).toEqual(['Oracle Weather', 'Oracle Fate question', 'Oracle Yes/no question'])
  })

  it('says when the journal or a scene is still empty', async () => {
    renderPlayScreen(lostMineInNewSession)

    expect(
      await within(await findJournalScene(1, 'Scene 1 — At the gate')).findByText(
        'Nothing recorded in this scene yet.',
      ),
    ).toBeInTheDocument()
    const session3 = within(journalRegion()).getByRole('region', { name: 'Session 3' })
    expect(within(session3).getByText('No scene in this session yet.')).toBeInTheDocument()
  })

  it('says the journal is empty before the first session, and disables what needs a scene', async () => {
    renderPlayScreen(created)

    expect(
      await within(await screen.findByRole('region', { name: 'Journal' })).findByText(
        /The journal is empty/,
      ),
    ).toBeInTheDocument()
    expect(playPosition()).toHaveTextContent('No session yet')
    expect(screen.getAllByText(noSceneHint).length).toBeGreaterThan(0)
    expect(screen.getByLabelText('New note')).toBeDisabled()
    expect(screen.getByLabelText('Dice expression')).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Roll' })).toBeDisabled()
    expect(screen.getByLabelText('Scene title')).toBeDisabled()
    expect(screen.getByText('Start a session to begin a scene.')).toBeInTheDocument()
  })

  it('starts a session, then a scene, which enables the journal tools', async () => {
    const inSession: Campaign = {
      ...created,
      sessions: [
        { number: 1, startedAt: '2026-10-06T12:05:00+00:00', scenes: [], endedAt: null, party: [] },
      ],
      currentSessionNumber: 1,
    }
    const inScene: Campaign = {
      ...inSession,
      sessions: [
        {
          number: 1,
          startedAt: '2026-10-06T12:05:00+00:00',
          party: [],
          scenes: [
            {
              number: 1,
              title: 'At the gate',
              startedAt: '2026-10-06T12:06:00+00:00',
              kind: 'scene',
              sceneType: null,
              sceneTypeName: null,
              hook: null,
              cast: [],
              characters: [],
            },
          ],
          endedAt: null,
        },
      ],
      currentSceneNumber: 1,
    }
    const sceneRequests: unknown[] = []
    renderPlayScreen(created, [], {
      [`POST ${campaignPath(created)}/sessions`]: json(inSession, 201),
      [`POST ${campaignPath(created)}/scenes`]: recording(inScene, sceneRequests),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Start session' }))
    await waitFor(() => {
      expect(playPosition()).toHaveTextContent('Session 1 · No scene yet')
    })
    expect(screen.getByLabelText('New note')).toBeDisabled()

    await user.type(screen.getByLabelText('Scene title'), 'At the gate')
    await user.click(screen.getByRole('button', { name: 'Start scene' }))

    await waitFor(() => {
      expect(playPosition()).toHaveTextContent('Session 1 · Scene 1: At the gate')
    })
    expect(sceneRequests).toEqual([{ title: 'At the gate' }])
    expect(screen.getByLabelText('Scene title')).toHaveValue('')
    expect(screen.queryByText(noSceneHint)).not.toBeInTheDocument()
    expect(screen.getByLabelText('New note')).toBeEnabled()
    expect(screen.getByLabelText('Dice expression')).toBeEnabled()
    expect(
      within(await findJournalScene(1, 'Scene 1 — At the gate')).getByText(
        'Nothing recorded in this scene yet.',
      ),
    ).toBeInTheDocument()
  })

  it("shows the API's reason when a session cannot start", async () => {
    renderPlayScreen(created, [], {
      [`POST ${campaignPath(created)}/sessions`]: json(
        { error: 'The campaign has reached its limit of 500 sessions.' },
        409,
      ),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Start session' }))

    expect(await within(playPosition()).findByText(/limit of 500 sessions/)).toHaveAttribute(
      'role',
      'alert',
    )
  })

  it("shows the API's reason when a scene cannot start", async () => {
    renderPlayScreen(lostMineCampaign, [], {
      [`POST ${campaignPath(lostMineCampaign)}/scenes`]: json(
        { error: 'The scene title must be at most 100 characters.' },
        422,
      ),
    })
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Scene title'), 'Too long, says the server')
    await user.click(screen.getByRole('button', { name: 'Start scene' }))

    expect(
      await within(screen.getByRole('form', { name: 'New scene' })).findByRole('alert'),
    ).toHaveTextContent('The scene title must be at most 100 characters.')
  })

  it('offers Start session only when no session is under way', async () => {
    const ended: Campaign = {
      ...lostMineCampaign,
      sessions: lostMineCampaign.sessions.map((session) => ({
        ...session,
        endedAt: '2026-10-06T14:00:00+00:00',
      })),
      currentSessionNumber: null,
      currentSceneNumber: null,
    }
    renderPlayScreen(ended)

    expect(await screen.findByRole('button', { name: 'Start session' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'End session' })).not.toBeInTheDocument()
  })

  it('offers End session, not Start session, while a session is under way in free play', async () => {
    const afterEnd: Campaign = {
      ...lostMineCampaign,
      sessions: lostMineCampaign.sessions.map((session) => ({
        ...session,
        endedAt: '2026-10-06T14:00:00+00:00',
      })),
      currentSessionNumber: null,
      currentSceneNumber: null,
    }
    let ended = false
    renderPlayScreen(lostMineCampaign, [], {
      [`GET ${campaignPath(lostMineCampaign)}`]: () =>
        Response.json(ended ? afterEnd : lostMineCampaign),
      [`POST ${campaignPath(lostMineCampaign)}/sessions/current/end`]: () => {
        ended = true
        return Response.json(afterEnd.sessions[1], { status: 200 })
      },
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'End session' }))

    expect(await screen.findByRole('button', { name: 'Start session' })).toBeInTheDocument()
    expect(playPosition()).toHaveTextContent('No session yet')
    expect(screen.queryByRole('button', { name: 'End session' })).not.toBeInTheDocument()
  })

  it('offers End session once the Flow is paused, and shows why a session cannot end', async () => {
    const paused = guided({ ...atStep(promptStep), status: 'paused' })
    renderPlayScreen(paused, [], {
      [`POST ${campaignPath(paused)}/sessions/current/end`]: json(
        { error: 'No session is under way: start a session before ending one.' },
        409,
      ),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'End session' }))

    expect(await within(playPosition()).findByRole('alert')).toHaveTextContent(
      'No session is under way',
    )
  })

  it('explains each tool panel in one line', async () => {
    renderPlayScreen(lostMineWithTypes)

    const helps: [string, string][] = [
      ['Trackers', 'Counters and clocks the flow reads to decide what happens next.'],
      [
        'Facts',
        'What the story has settled. The flow asks for some; refill one when play changes it.',
      ],
      [
        'Threads',
        'The open questions and goals your story follows. Add one when something needs resolving, such as a mystery, a promise or a rival, and close it when it is settled.',
      ],
      ['NPCs', 'The people met in play. Add them as they appear; the flow adds some too.'],
      ['Characters', 'Your characters and their sheets. Open one to read or edit it.'],
      [
        'Oracles',
        'Ask a yes/no question or roll a table when you need ideas. The result is yours to interpret.',
      ],
    ]
    for (const [panel, help] of helps) {
      expect(
        within(await screen.findByRole('region', { name: panel })).getByText(help),
      ).toBeInTheDocument()
    }
  })

  it('names the scene once where play stands when its title only repeats the Scene Type', async () => {
    const campaign = {
      ...lostMineWithTypes,
      sessions: lostMineWithTypes.sessions.map((session) => ({
        ...session,
        scenes: session.scenes.map((scene) =>
          session.number === 2 ? { ...scene, title: 'Legwork 1' } : scene,
        ),
      })),
    }
    renderPlayScreen(campaign)

    await screen.findByRole('heading', { level: 1, name: 'The lost mine' })
    expect(playPosition()).toHaveTextContent(/Session 2 · Scene 1: Legwork(?! 1)/)
  })

  it('writes a note in the current scene, which appears in the journal', async () => {
    const sent: unknown[] = []
    const note = recordedEntry({ kind: 'note', text: 'A cold wind.\nTorches flicker.' })
    renderPlayScreen(lostMineCampaign, lostMineJournal, {
      [`POST ${campaignPath(lostMineCampaign)}/journal/notes`]: recording(note, sent),
    })
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('New note'), 'A cold wind.{Enter}Torches flicker.')
    await user.click(screen.getByRole('button', { name: 'Write note' }))

    const scene = await findJournalScene(2, 'Scene 1 — Into the dark')
    await waitFor(() => {
      expect(within(scene).getAllByRole('article')).toHaveLength(4)
    })
    expect(within(scene).getAllByRole('article').at(-1)).toHaveTextContent(
      'A cold wind. Torches flicker.',
      { normalizeWhitespace: true },
    )
    expect(sent).toEqual([{ text: 'A cold wind.\nTorches flicker.' }])
    expect(screen.getByLabelText('New note')).toHaveValue('')
  })

  it("shows the API's reason when a note is refused", async () => {
    renderPlayScreen(lostMineCampaign, [], {
      [`POST ${campaignPath(lostMineCampaign)}/journal/notes`]: json(
        { error: 'The campaign has no current scene.' },
        409,
      ),
    })
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('New note'), 'Hello')
    await user.click(screen.getByRole('button', { name: 'Write note' }))

    expect(
      await within(screen.getByRole('form', { name: 'Write a note' })).findByRole('alert'),
    ).toHaveTextContent('The campaign has no current scene.')
  })

  it('rolls dice into the journal', async () => {
    const sent: unknown[] = []
    const roll = recordedEntry({
      kind: 'roll',
      expression: '2d6+1',
      total: 8,
      groups: [
        {
          notation: '2d6',
          sides: 6,
          dice: [
            { value: 3, kept: true },
            { value: 4, kept: true },
          ],
          subtotal: 7,
        },
      ],
    })
    renderPlayScreen(lostMineCampaign, [], {
      [`POST ${campaignPath(lostMineCampaign)}/journal/rolls`]: recording(roll, sent),
    })
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Dice expression'), '2d6+1{Enter}')

    const scene = await findJournalScene(2, 'Scene 1 — Into the dark')
    expect(await within(scene).findByRole('article', { name: 'Roll 2d6+1' })).toHaveTextContent(
      '2d6+1=total8',
    )
    expect(sent).toEqual([{ expression: '2d6+1' }])
  })

  it("shows the API's reason when a roll is refused", async () => {
    renderPlayScreen(lostMineCampaign, [], {
      [`POST ${campaignPath(lostMineCampaign)}/journal/rolls`]: json(
        { error: 'Unexpected character "x" at position 2.' },
        422,
      ),
    })
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Dice expression'), '2x6{Enter}')

    expect(
      await within(screen.getByRole('region', { name: 'Dice' })).findByRole('alert'),
    ).toHaveTextContent('Unexpected character "x" at position 2.')
  })

  it('asks the oracles of the pinned release into the journal', async () => {
    const [, , weather, fate] = lostMineJournal
    renderPlayScreen(lostMineCampaign, [], {
      [`POST ${campaignPath(lostMineCampaign)}/journal/oracle-tables/weather`]: json(weather, 201),
      [`POST ${campaignPath(lostMineCampaign)}/journal/likelihood-oracles/fate`]: json(fate, 201),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Roll on Weather' }))
    const scene = await findJournalScene(2, 'Scene 1 — Into the dark')
    expect(await within(scene).findByRole('article', { name: 'Oracle Weather' })).toBeVisible()

    await user.click(
      within(screen.getByRole('form', { name: 'Fate question' })).getByRole('button', {
        name: 'Ask',
      }),
    )
    expect(
      await within(scene).findByRole('article', { name: 'Oracle Fate question' }),
    ).toHaveTextContent('Exceptional yes')
  })

  it('shows why the journal could not be loaded', async () => {
    renderPlayScreen(lostMineCampaign, [], {
      [`GET ${campaignPath(lostMineCampaign)}/journal`]: json({ error: 'Gone.' }, 404),
    })

    expect(
      await within(await screen.findByRole('region', { name: 'Journal' })).findByRole('alert'),
    ).toHaveTextContent('Gone.')
  })

  it('shows the API failure, not stale data, when a refetch fails', async () => {
    let refused = false
    const { queryClient } = renderAppAt(`/play/campaigns/${lostMine.id}`, {
      'GET /api/auth/me': soloPlayer,
      [`GET /api/campaigns/${lostMine.id}`]: () =>
        refused
          ? Response.json({ error: 'Campaign not found.' }, { status: 404 })
          : Response.json(lostMineCampaign),
      [`GET /api/campaigns/${lostMine.id}/journal`]: json([]),
    })
    expect(
      await screen.findByRole('heading', { level: 1, name: 'The lost mine' }),
    ).toBeInTheDocument()

    refused = true
    await queryClient.refetchQueries({ queryKey: ['play', 'campaigns', lostMine.id] })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Campaign not found' }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('heading', { level: 1, name: 'The lost mine' }),
    ).not.toBeInTheDocument()
  })

  it('shows a generic message when loading the campaign fails unexpectedly', async () => {
    renderAppAt(`/play/campaigns/${created.id}`, {
      'GET /api/auth/me': soloPlayer,
      [`GET /api/campaigns/${created.id}`]: () => new Response('Oops', { status: 500 }),
    })

    // The query retries once (about a second) before it gives up.
    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the campaign failed with HTTP 500.',
    )
    expect(screen.queryByRole('heading', { level: 1 })).not.toBeInTheDocument()
  })

  it('shows a not found state for an unknown or foreign campaign, without retrying', async () => {
    const { fakeApi } = renderAppAt(`/play/campaigns/${created.id}`, {
      'GET /api/auth/me': soloPlayer,
      [`GET /api/campaigns/${created.id}`]: json({ error: 'Campaign not found.' }, 404),
    })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Campaign not found' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to your campaigns' })).toBeInTheDocument()
    expect(fakeApi.requests.filter((r) => r.startsWith('GET /api/campaigns/'))).toHaveLength(1)
  })

  it('keeps the solo player guard', async () => {
    renderAppAt(`/play/campaigns/${created.id}`, { 'GET /api/auth/me': signedInAs('OWNER') })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Access denied' }),
    ).toBeInTheDocument()
    expect(screen.getByText("You don't have access to Play.")).toBeInTheDocument()
  })

  it('sends anonymous visitors to the sign-in page and back', async () => {
    const { router } = renderAppAt(`/play/campaigns/${created.id}`)

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeInTheDocument()
    expect(router.state.location.search).toEqual({ redirect: `/play/campaigns/${created.id}` })
  })

  describe('the Flow oracles section', () => {
    /** The Flow "tour" lists the oracle "action", the release also has "weather". */
    function tourCampaign(status: 'active' | 'paused' | 'completed'): Campaign {
      const campaign = guided({ ...atStep(promptStep), status })
      const [flow] = campaign.flows
      return { ...campaign, flows: flow === undefined ? [] : [{ ...flow, oracles: ['action'] }] }
    }

    it('orders the oracles by the Flow while its FlowRun is active', async () => {
      renderPlayScreen(tourCampaign('active'))

      expect(await screen.findByRole('heading', { name: 'Flow: Tour' })).toBeInTheDocument()
      expect(screen.getByText('More oracles')).toBeInTheDocument()
    })

    it.each(['paused', 'completed'] as const)(
      'shows every oracle flat when the FlowRun is %s, as the Trackers do',
      async (status) => {
        renderPlayScreen(tourCampaign(status))

        expect(await screen.findByRole('button', { name: 'Roll on Weather' })).toBeInTheDocument()
        expect(screen.queryByRole('heading', { name: 'Flow: Tour' })).not.toBeInTheDocument()
        expect(screen.queryByText('More oracles')).not.toBeInTheDocument()
      },
    )
  })
})
