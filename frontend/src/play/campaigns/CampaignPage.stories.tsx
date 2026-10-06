import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { lostMineJournal, recordedEntry } from '../journal/fixtures'
import { CampaignPage } from './CampaignPage'
import {
  freeJournal,
  lostMine,
  lostMineCampaign,
  lostMineInNewSession,
  newCampaign,
} from './fixtures'

const created = newCampaign('The haunted keep', freeJournal)
const unknownId = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

/** The campaign API for one campaign; record requests answer like the API would. */
function campaignApi(campaign: typeof lostMineCampaign, journal = lostMineJournal) {
  const path = `/api/campaigns/${campaign.id}`
  return {
    [`GET ${path}`]: json(campaign),
    [`GET ${path}/journal`]: json(journal),
    [`POST ${path}/journal/notes`]: async (request: Request) => {
      const { text } = (await request.json()) as { text: string }
      return Response.json(
        recordedEntry({ kind: 'note', text: text.trim() }, crypto.randomUUID()),
        {
          status: 201,
        },
      )
    },
    [`POST ${path}/journal/rolls`]: async (request: Request) => {
      const { expression } = (await request.json()) as { expression: string }
      return Response.json(
        recordedEntry(
          {
            kind: 'roll',
            expression,
            total: 7,
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
          },
          crypto.randomUUID(),
        ),
        { status: 201 },
      )
    },
  } satisfies Record<string, FakeHandler>
}

const meta = {
  title: 'Play/CampaignPage',
  component: CampaignPage,
  args: { campaignId: lostMine.id },
  parameters: { api: campaignApi(lostMineCampaign) },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof CampaignPage>

export default meta
type Story = StoryObj<typeof meta>

/** A full journal with every entry kind, in a scene: every tool is enabled. */
export const FullJournal: Story = {}

export const WritingANote: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.type(await canvas.findByLabelText('New note'), 'The torches go out.')
    await userEvent.click(canvas.getByRole('button', { name: 'Write note' }))
    await expect(await canvas.findByText('The torches go out.')).toBeInTheDocument()
  },
}

/** Just created: no session, so no scene and an empty journal. */
export const NoSession: Story = {
  args: { campaignId: created.id },
  parameters: { api: campaignApi(created, []) },
}

/** A session has started but has no scene yet: the journal tools wait for one. */
export const SessionWithoutScene: Story = {
  parameters: { api: campaignApi(lostMineInNewSession) },
}

export const JournalUnavailable: Story = {
  parameters: {
    api: {
      ...campaignApi(lostMineCampaign),
      [`GET /api/campaigns/${lostMine.id}/journal`]: () => new Response('Oops', { status: 500 }),
    },
  },
}

/** Unknown, or someone else's: the API answers 404 either way. */
export const NotFound: Story = {
  args: { campaignId: unknownId },
  parameters: {
    api: {
      [`GET /api/campaigns/${unknownId}`]: json({ error: 'Campaign not found.' }, 404),
    },
  },
}
