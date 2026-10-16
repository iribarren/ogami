import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { narrativeScenes, narrativeV1 } from '../../fixtures'
import { SceneTypesTab } from './SceneTypesTab'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/SceneTypesTab',
  component: SceneTypesTab,
  args: { gameSystemKey: 'my-draft' },
  parameters: { api: { [`GET ${base}/narrative`]: json(narrativeScenes) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof SceneTypesTab>

export default meta
type Story = StoryObj<typeof meta>

/** A Scene Type a flow uses cannot be deleted; one of an unknown shape is listed read-only. */
export const Default: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('button', { name: 'Delete ambush' })).toBeDisabled()
    await expect(canvas.getByRole('button', { name: 'Delete odd' })).toBeEnabled()
  },
}

export const Empty: Story = {
  parameters: { api: { [`GET ${base}/narrative`]: json({ ...narrativeScenes, sceneTypes: [] }) } },
}

/** A schema version 1 draft has no narrative sections. */
export const SchemaVersion1: Story = {
  parameters: { api: { [`GET ${base}/narrative`]: json(narrativeV1) } },
}

export const NewSceneTypeDialog: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'New Scene Type' }))
    await expect(within(document.body).getByLabelText('Purpose')).toBeInTheDocument()
  },
}
