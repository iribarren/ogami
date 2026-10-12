import type { Meta, StoryObj } from '@storybook/react-vite'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { CampaignPage } from '../campaigns/CampaignPage'
import { lostMineCampaign } from '../campaigns/fixtures'
import { lostMineJournal } from '../journal/fixtures'
import { castCampaign, castFocus } from './fixtures'

const json =
  (body: unknown): FakeHandler =>
  () =>
    Response.json(body)

const path = `/api/campaigns/${lostMineCampaign.id}`

const meta = {
  title: 'Play/Scene cast',
  component: CampaignPage,
  args: { campaignId: lostMineCampaign.id },
  parameters: {
    api: { [`GET ${path}`]: json(castCampaign), [`GET ${path}/journal`]: json(lostMineJournal) },
  },
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

/** The journal's scene headers: the past scene's cast read-only, the current scene's editable. */
export const JournalHeaders: Story = {}

export const FocusMode: Story = {
  parameters: {
    api: { [`GET ${path}`]: json(castFocus), [`GET ${path}/journal`]: json(lostMineJournal) },
  },
}
