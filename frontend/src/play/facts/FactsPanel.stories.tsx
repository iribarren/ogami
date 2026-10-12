import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import { StoryProviders } from '@/test/StoryProviders'

import { lostMineCampaign } from '../campaigns/fixtures'
import { FactsPanel } from './FactsPanel'
import { lostMineWithFacts } from './fixtures'

const meta = {
  title: 'Play/FactsPanel',
  component: FactsPanel,
  args: { campaign: lostMineWithFacts },
  decorators: [
    (Story) => (
      <StoryProviders
        handlers={{
          [`PUT /api/campaigns/${lostMineWithFacts.id}/fact-slots/city`]: () =>
            Response.json({ error: 'The fact text is too long.' }, { status: 422 }),
        }}
      >
        <div className="max-w-sm">
          <Story />
        </div>
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof FactsPanel>

export default meta
type Story = StoryObj<typeof meta>

/** Slots with hints (one unfilled), then free facts; links show by name. */
export const Facts: Story = {}

/** A campaign from before the facts: nothing yet. */
export const Empty: Story = { args: { campaign: lostMineCampaign } }

/** A refused fill keeps the form as typed and shows why. */
export const FillRefused: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Refill The city' }))
    await userEvent.click(canvas.getByRole('button', { name: 'Save' }))
    await expect(await canvas.findByRole('alert')).toHaveTextContent('The fact text is too long.')
    await expect(canvas.getByLabelText('Text')).toHaveValue('Ashford')
  },
}
