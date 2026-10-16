import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { narrative, narrativeV1 } from '../fixtures'
import { NarrativeTab } from './NarrativeTab'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/NarrativeTab',
  component: NarrativeTab,
  args: { gameSystemKey: 'my-draft' },
  parameters: { api: { [`GET ${base}/narrative`]: json(narrative) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof NarrativeTab>

export default meta
type Story = StoryObj<typeof meta>

/** An item the draft uses cannot be deleted; its row says where it is used. */
export const Default: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('button', { name: 'Delete heat' })).toBeDisabled()
  },
}

export const Empty: Story = {
  parameters: {
    api: {
      [`GET ${base}/narrative`]: json({ ...narrative, trackers: [], tags: [], factSlots: [] }),
    },
  },
}

/** A schema version 1 draft has no narrative sections. */
export const SchemaVersion1: Story = {
  parameters: { api: { [`GET ${base}/narrative`]: json(narrativeV1) } },
}

export const LoadFailed: Story = {
  parameters: {
    api: { [`GET ${base}/narrative`]: () => new Response('Oops', { status: 500 }) },
  },
}

export const NewTrackerDialog: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'New tracker' }))
    await expect(within(document.body).getByRole('dialog')).toBeInTheDocument()
  },
}

/** Editing a counter shows its levels, which can be added, moved and deleted. */
export const EditCounterDialog: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit heat' }))
    await expect(within(document.body).getByLabelText('Level 1 label')).toHaveValue('Quiet')
  },
}

export const NewTagDialog: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'New tag' }))
    await expect(within(document.body).getByRole('dialog')).toBeInTheDocument()
  },
}

export const NewFactSlotDialog: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'New fact slot' }))
    await expect(within(document.body).getByLabelText('Type')).toBeInTheDocument()
  },
}
