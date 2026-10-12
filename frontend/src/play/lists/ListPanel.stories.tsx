import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import { StoryProviders } from '@/test/StoryProviders'

import { lostMineCampaign } from '../campaigns/fixtures'
import { ListPanel } from './ListPanel'
import { lostMineWithLists, miraNpc } from './fixtures'

const meta = {
  title: 'Play/ListPanel',
  component: ListPanel,
  args: { campaign: lostMineWithLists, kind: 'npc' },
  decorators: [
    (Story) => (
      <StoryProviders
        handlers={{
          [`PUT /api/campaigns/${lostMineWithLists.id}/npcs/${miraNpc.id}`]: () =>
            Response.json({ error: 'The name is too long.' }, { status: 422 }),
        }}
      >
        <div className="max-w-sm">
          <Story />
        </div>
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof ListPanel>

export default meta
type Story = StoryObj<typeof meta>

/** Release Tags with hints, free tags, weights, and a closed NPC under "Closed". */
export const Npcs: Story = {}

export const Threads: Story = { args: { kind: 'thread' } }

/** A campaign from before the lists: nothing yet. */
export const Empty: Story = { args: { campaign: lostMineCampaign } }

/** A refused edit keeps the form as typed and shows why. */
export const EditRefused: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit Mira Vane' }))
    await userEvent.click(canvas.getByRole('button', { name: 'Save' }))
    await expect(await canvas.findByRole('alert')).toHaveTextContent('The name is too long.')
    await expect(canvas.getByLabelText('Name')).toHaveValue('Mira Vane')
  },
}
