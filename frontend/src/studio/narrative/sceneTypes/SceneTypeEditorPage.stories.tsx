import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { narrativeScenes } from '../../fixtures'
import { SceneTypeEditorPage } from './SceneTypeEditorPage'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/SceneTypeEditorPage',
  component: SceneTypeEditorPage,
  args: { gameSystemKey: 'my-draft', sceneType: 'ambush' },
  parameters: {
    api: { [`GET ${base}/narrative`]: json(narrativeScenes) },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof SceneTypeEditorPage>

export default meta
type Story = StoryObj<typeof meta>

/** A Scene Type with oracle shortcuts and steps in its setup, play and closing lists. */
export const WithSteps: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('region', { name: 'Closing' })).toBeVisible()
    await expect(canvas.getByLabelText('Purpose')).toHaveValue(
      'Something goes wrong on the street.',
    )
  },
}

/** A Scene Type with no tips and no steps yet. */
export const Empty: Story = { args: { sceneType: 'quiet' } }

/** A Scene Type of a shape the editor cannot read is not opened. */
export const UnreadableShape: Story = { args: { sceneType: 'odd' } }

export const NotFound: Story = { args: { sceneType: 'nope' } }
