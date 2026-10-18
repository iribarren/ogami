import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import {
  freeJournal,
  freeJournalV3,
  lostMineCampaign,
  lostMineUpgrade,
  mythicStyle,
  trackerOnlyUpgrade,
  upToDateUpgrade,
} from './fixtures'
import type { Campaign } from './useCampaigns'
import type { ReleaseUpgrade } from './useReleaseUpgrade'

const campaignPath = `/api/campaigns/${lostMineCampaign.id}`
const upgradePath = `${campaignPath}/release-upgrade`
const upgraded: Campaign = {
  ...lostMineCampaign,
  pinnedRelease: { ...lostMineCampaign.pinnedRelease, version: 3 },
}

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

/** The Play screen of `lostMineCampaign` (release v2) while the GameSystems offer `latest`. */
function renderPlayScreen(
  latest: unknown[],
  handlers: Record<string, FakeHandler> = {},
  campaign: Campaign = lostMineCampaign,
) {
  return renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${campaignPath}`]: json(campaign),
    [`GET ${campaignPath}/journal`]: json([]),
    'GET /api/play/game-systems': json(latest),
    ...handlers,
  })
}

async function openDialog(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Upgrade to v3' }))
  return screen.findByRole('dialog')
}

describe('the release upgrade', () => {
  it('offers no upgrade while the campaign plays the latest release', async () => {
    const { fakeApi } = renderPlayScreen([freeJournal])

    await screen.findByText('Free journal v2')
    await waitFor(() => {
      expect(fakeApi.requests).toContain('GET /api/play/game-systems')
    })
    expect(screen.queryByRole('button', { name: /^Upgrade to/ })).not.toBeInTheDocument()
  })

  it('offers no upgrade for another GameSystem, only for the campaign’s own', async () => {
    const { fakeApi } = renderPlayScreen([{ ...mythicStyle, version: 9 }])

    await screen.findByText('Free journal v2')
    await waitFor(() => {
      expect(fakeApi.requests).toContain('GET /api/play/game-systems')
    })
    expect(screen.queryByRole('button', { name: /^Upgrade to/ })).not.toBeInTheDocument()
  })

  it('offers no upgrade when the GameSystems cannot be loaded', async () => {
    const { fakeApi } = renderPlayScreen([], {
      'GET /api/play/game-systems': json({ error: 'Oops' }, 500),
    })

    await screen.findByText('Free journal v2')
    await waitFor(() => {
      expect(fakeApi.requests).toContain('GET /api/play/game-systems')
    })
    expect(screen.queryByRole('button', { name: /^Upgrade to/ })).not.toBeInTheDocument()
  })

  it('lists every section of the plan, old to new, and what stays', async () => {
    renderPlayScreen([freeJournalV3], { [`GET ${upgradePath}`]: json(lostMineUpgrade) })
    const user = userEvent.setup()

    const dialog = await openDialog(user)

    expect(await within(dialog).findByText('Free journal v2 → v3')).toBeInTheDocument()
    const characters = within(dialog).getByRole('region', { name: 'Characters' })
    expect(within(characters).getByText('Vi')).toBeInTheDocument()
    expect(within(characters).getByText('Luck (clamped): 9 → 5')).toBeInTheDocument()
    expect(within(characters).getByText('Grit (added): none → 1')).toBeInTheDocument()
    expect(within(characters).getByText('Hit points (clamped): 8/8 → 6/6')).toBeInTheDocument()
    expect(
      within(characters).getByText('Gear (trimmed): rope, lamp, knife → rope, lamp'),
    ).toBeInTheDocument()
    expect(within(characters).getByText('Motto (reset): Never again → empty')).toBeInTheDocument()
    expect(within(characters).getByText('Fame (removed): 3 → none')).toBeInTheDocument()
    const tags = within(dialog).getByRole('region', { name: 'Tags' })
    expect(tags).toHaveTextContent('Vi (Character) loses thief')
    expect(tags).toHaveTextContent('Old Tam (NPC) loses guard')
    expect(tags).toHaveTextContent('The missing map (Thread) loses quest')
    const trackers = within(dialog).getByRole('region', { name: 'Trackers' })
    expect(within(trackers).getByText('Heat (clamped): 9 → 6')).toBeInTheDocument()
    expect(within(trackers).getByText('Luck pool (removed): 2')).toBeInTheDocument()
    const facts = within(dialog).getByRole('region', { name: 'Campaign facts' })
    expect(facts).toHaveTextContent('Patron loses its slot (patron) and stays as a free fact')
    const guidance = within(dialog).getByRole('region', { name: 'Guidance' })
    expect(guidance).toHaveTextContent(
      'Guidance restarts at the first phase of the quick Flow (it was full).',
    )
    expect(within(dialog).getByText('Nothing else changes.')).toBeInTheDocument()
  })

  it('omits the sections that have nothing to list', async () => {
    renderPlayScreen([freeJournalV3], { [`GET ${upgradePath}`]: json(trackerOnlyUpgrade) })
    const user = userEvent.setup()

    const dialog = await openDialog(user)

    expect(await within(dialog).findByRole('region', { name: 'Trackers' })).toBeInTheDocument()
    for (const name of ['Characters', 'Tags', 'Campaign facts', 'Guidance']) {
      expect(within(dialog).queryByRole('region', { name })).not.toBeInTheDocument()
    }
    expect(within(dialog).getByText('Nothing else changes.')).toBeInTheDocument()
  })

  it('says so when the campaign is up to date, with nothing to confirm', async () => {
    renderPlayScreen([freeJournalV3], { [`GET ${upgradePath}`]: json(upToDateUpgrade) })
    const user = userEvent.setup()

    const dialog = await openDialog(user)

    expect(
      await within(dialog).findByText('This campaign already plays the latest release.'),
    ).toBeInTheDocument()
    expect(
      within(dialog).queryByRole('button', { name: 'Confirm upgrade' }),
    ).not.toBeInTheDocument()
  })

  it('confirms exactly the previewed version and fingerprint, then shows the new release', async () => {
    const sent: unknown[] = []
    renderPlayScreen([freeJournalV3], {
      [`GET ${upgradePath}`]: json(lostMineUpgrade),
      [`POST ${upgradePath}`]: async (request) => {
        sent.push(await request.json())
        return Response.json(upgraded)
      },
    })
    const user = userEvent.setup()

    const dialog = await openDialog(user)
    await user.click(await within(dialog).findByRole('button', { name: 'Confirm upgrade' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(sent).toEqual([{ toVersion: 3, fingerprint: 'a'.repeat(64) }])
    expect(await screen.findByText('Free journal v3')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Upgrade to/ })).not.toBeInTheDocument()
  })

  it('closes without changing anything on Cancel', async () => {
    const { fakeApi } = renderPlayScreen([freeJournalV3], {
      [`GET ${upgradePath}`]: json(lostMineUpgrade),
    })
    const user = userEvent.setup()

    const dialog = await openDialog(user)
    await within(dialog).findByRole('button', { name: 'Confirm upgrade' })
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(fakeApi.requests).not.toContain(`POST ${upgradePath}`)
    expect(screen.getByText('Free journal v2')).toBeInTheDocument()
  })

  it('reloads the preview on a 409, says why, and confirms the new fingerprint', async () => {
    const sent: unknown[] = []
    let previews = 0
    renderPlayScreen([freeJournalV3], {
      [`GET ${upgradePath}`]: () => {
        previews += 1
        const stale: ReleaseUpgrade = previews === 1 ? lostMineUpgrade : trackerOnlyUpgrade
        return Response.json(stale)
      },
      [`POST ${upgradePath}`]: async (request) => {
        const body = (await request.json()) as { fingerprint: string }
        sent.push(body)
        return body.fingerprint === 'a'.repeat(64)
          ? Response.json({ error: 'The upgrade changed, review it again.' }, { status: 409 })
          : Response.json(upgraded)
      },
    })
    const user = userEvent.setup()

    const dialog = await openDialog(user)
    await user.click(await within(dialog).findByRole('button', { name: 'Confirm upgrade' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'The upgrade changed, review it again.',
    )
    expect(await within(dialog).findByRole('region', { name: 'Trackers' })).toBeInTheDocument()
    expect(within(dialog).queryByRole('region', { name: 'Characters' })).not.toBeInTheDocument()
    expect(sent).toHaveLength(1)

    await user.click(within(dialog).getByRole('button', { name: 'Confirm upgrade' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { toVersion: 3, fingerprint: 'a'.repeat(64) },
        { toVersion: 3, fingerprint: 'b'.repeat(64) },
      ])
    })
    expect(await screen.findByText('Free journal v3')).toBeInTheDocument()
  })

  it.each([
    [404, 'Campaign not found.'],
    [422, 'The "fingerprint" must not be blank.'],
  ])('shows the API message of a %i and keeps the dialog open', async (status, message) => {
    renderPlayScreen([freeJournalV3], {
      [`GET ${upgradePath}`]: json(lostMineUpgrade),
      [`POST ${upgradePath}`]: json({ error: message }, status),
    })
    const user = userEvent.setup()

    const dialog = await openDialog(user)
    await user.click(await within(dialog).findByRole('button', { name: 'Confirm upgrade' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(message)
  })

  it('shows a generic message when the upgrade fails without an explanation', async () => {
    renderPlayScreen([freeJournalV3], {
      [`GET ${upgradePath}`]: json(lostMineUpgrade),
      [`POST ${upgradePath}`]: () => new Response('Oops', { status: 502 }),
    })
    const user = userEvent.setup()

    const dialog = await openDialog(user)
    await user.click(await within(dialog).findByRole('button', { name: 'Confirm upgrade' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Upgrading the campaign failed with HTTP 502.',
    )
  })

  it('reloads the campaign and the preview when the saved upgrade cannot be read back', async () => {
    const message = 'The upgrade was saved, but the campaign cannot be read now. Reload the page.'
    let saved = false
    let releaseCampaign: () => void = () => undefined
    const campaignReadable = new Promise<void>((resolve) => {
      releaseCampaign = resolve
    })
    const { fakeApi } = renderPlayScreen([freeJournalV3], {
      [`GET ${campaignPath}`]: async () => {
        if (!saved) {
          return Response.json(lostMineCampaign)
        }
        await campaignReadable
        return Response.json(upgraded)
      },
      [`GET ${upgradePath}`]: () => Response.json(saved ? upToDateUpgrade : lostMineUpgrade),
      [`POST ${upgradePath}`]: () => {
        saved = true
        return Response.json({ error: message }, { status: 500 })
      },
    })
    const user = userEvent.setup()

    const dialog = await openDialog(user)
    await user.click(await within(dialog).findByRole('button', { name: 'Confirm upgrade' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(message)
    expect(
      await within(dialog).findByText('This campaign already plays the latest release.'),
    ).toBeInTheDocument()
    expect(
      within(dialog).queryByRole('button', { name: 'Confirm upgrade' }),
    ).not.toBeInTheDocument()

    releaseCampaign()

    expect(await screen.findByText('Free journal v3')).toBeInTheDocument()
    expect(fakeApi.requests.filter((request) => request === `GET ${campaignPath}`)).toHaveLength(2)
  })

  it('shows the failure when the preview cannot be loaded, with nothing to confirm', async () => {
    renderPlayScreen([freeJournalV3], {
      [`GET ${upgradePath}`]: () => new Response('Oops', { status: 500 }),
    })
    const user = userEvent.setup()

    const dialog = await openDialog(user)

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Loading the release upgrade failed with HTTP 500.',
    )
    expect(
      within(dialog).queryByRole('button', { name: 'Confirm upgrade' }),
    ).not.toBeInTheDocument()
  })
})
