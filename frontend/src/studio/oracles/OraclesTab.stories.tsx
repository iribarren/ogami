import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { oracles } from '../fixtures'
import { OraclesTab } from './OraclesTab'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/OraclesTab',
  component: OraclesTab,
  args: { gameSystemKey: 'my-draft' },
  parameters: { api: { [`GET ${base}/oracles`]: json(oracles) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof OraclesTab>

export default meta
type Story = StoryObj<typeof meta>

/** A table the draft uses cannot be deleted; its row says where it is used. */
export const Default: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('button', { name: 'Delete weather' })).toBeDisabled()
  },
}

export const Empty: Story = {
  parameters: {
    api: { [`GET ${base}/oracles`]: json({ ...oracles, tables: [], likelihood: [] }) },
  },
}

export const LoadFailed: Story = {
  parameters: {
    api: { [`GET ${base}/oracles`]: () => new Response('Oops', { status: 500 }) },
  },
}

export const NewTableDialog: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'New table' }))
    await expect(within(document.body).getByRole('dialog')).toBeInTheDocument()
  },
}
