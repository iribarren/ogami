import type { Meta, StoryObj } from '@storybook/react-vite'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { CampaignPage } from './CampaignPage'
import { freeJournal, lostMine, lostMineCampaign, newCampaign } from './fixtures'

const created = newCampaign('The haunted keep', freeJournal)
const unknownId = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999'

const campaignApi = {
  [`GET /api/campaigns/${lostMine.id}`]: () => Response.json(lostMineCampaign),
  [`GET /api/campaigns/${created.id}`]: () => Response.json(created),
} satisfies Record<string, FakeHandler>

const meta = {
  title: 'Play/CampaignPage',
  component: CampaignPage,
  args: { campaignId: lostMine.id },
  parameters: { api: campaignApi },
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

export const InAScene: Story = {}

export const JustCreated: Story = { args: { campaignId: created.id } }

/** Unknown, or someone else's: the API answers 404 either way. */
export const NotFound: Story = {
  args: { campaignId: unknownId },
  parameters: {
    api: {
      [`GET /api/campaigns/${unknownId}`]: () =>
        Response.json({ error: 'Campaign not found.' }, { status: 404 }),
    },
  },
}
