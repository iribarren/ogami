import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { lostMineWithTypes } from '../campaigns/fixtures'
import { atStep, promptStep } from '../flow/fixtures'
import { TrackersPanel } from './TrackersPanel'

const trackerApi = {
  [`PUT /api/campaigns/${lostMineWithTypes.id}/trackers/alarm`]: () =>
    Response.json({ error: 'The campaign changed meanwhile.' }, { status: 404 }),
} satisfies Record<string, FakeHandler>

const meta = {
  title: 'Play/TrackersPanel',
  component: TrackersPanel,
  args: { campaign: lostMineWithTypes },
  decorators: [
    (Story) => (
      <StoryProviders handlers={trackerApi}>
        <div className="max-w-sm">
          <Story />
        </div>
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof TrackersPanel>

export default meta
type Story = StoryObj<typeof meta>

/** Played freely: every Tracker of the release. */
export const AllTrackers: Story = {}

/** Guided: only the Trackers the Flow shows, in its order. */
export const GuidedByAFlow: Story = {
  args: {
    campaign: {
      ...lostMineWithTypes,
      flowKey: 'heist',
      flowRun: atStep(promptStep, { status: 'active' }),
    },
  },
}

export const NoTrackers: Story = { args: { campaign: { ...lostMineWithTypes, trackers: [] } } }

export const EditRefused: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Increase Alarm' }))
    await expect(await canvas.findByRole('alert')).toHaveTextContent(
      'The campaign changed meanwhile.',
    )
  },
}
