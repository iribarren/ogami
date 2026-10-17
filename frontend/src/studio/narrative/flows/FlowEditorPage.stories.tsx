import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { narrativeFlows } from '../../fixtures'
import { FlowEditorPage } from './FlowEditorPage'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/FlowEditorPage',
  component: FlowEditorPage,
  args: { gameSystemKey: 'my-draft', flow: 'heist' },
  parameters: {
    api: { [`GET ${base}/narrative`]: json(narrativeFlows) },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof FlowEditorPage>

export default meta
type Story = StoryObj<typeof meta>

/** A default flow with two phases, one played in order and one looping with the player's pick. */
export const WithPhases: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('group', { name: 'Phase 2' })).toBeVisible()
    await expect(canvas.getByLabelText('Default flow')).toBeChecked()
  },
}

/** A flow with a single phase: it cannot be deleted. */
export const SinglePhase: Story = { args: { flow: 'side-job' } }

/** A flow of a shape the editor cannot read is not opened. */
export const UnreadableShape: Story = { args: { flow: 'odd-flow' } }

export const NotFound: Story = { args: { flow: 'nope' } }
