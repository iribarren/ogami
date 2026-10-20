import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { lostMineCampaign } from '../campaigns/fixtures'
import {
  fateDouble,
  fateDoubleEvent,
  keepThreeRoll,
  lockedDoorAnswer,
  skillCheckEntry,
  stormyWeather,
} from './fixtures'
import { NoteComposer } from './NoteComposer'

const journal = [
  keepThreeRoll,
  stormyWeather,
  lockedDoorAnswer,
  fateDouble,
  fateDoubleEvent,
  skillCheckEntry,
]

const meta = {
  title: 'Play/NoteComposer',
  component: NoteComposer,
  args: { campaignId: lostMineCampaign.id, disabled: false, current: { session: 2, scene: 1 } },
  parameters: {
    api: {
      [`GET /api/campaigns/${lostMineCampaign.id}/journal`]: () => Response.json(journal),
    },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <div className="max-w-xl">
          <Story />
        </div>
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof NoteComposer>

export default meta
type Story = StoryObj<typeof meta>

/** The scene has results: "Attach results" appears. */
export const WithResults: Story = {}

/** The picker open with two results picked. */
export const PickingResults: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Attach results' }))
    await userEvent.click(await canvas.findByRole('checkbox', { name: /^Weather/ }))
    await userEvent.click(canvas.getByRole('checkbox', { name: /^Is the door locked/ }))
    await expect(canvas.getByRole('button', { name: 'Attach results (2)' })).toBeInTheDocument()
  },
}

/** No result yet in the scene: just the note. */
export const NoResults: Story = {
  parameters: {
    api: { [`GET /api/campaigns/${lostMineCampaign.id}/journal`]: () => Response.json([]) },
  },
}
