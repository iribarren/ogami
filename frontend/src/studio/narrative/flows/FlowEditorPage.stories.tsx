import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

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
    const outline = await canvas.findByRole('navigation', { name: 'Flow outline' })
    await expect(within(outline).getByRole('button', { name: '2. The job Loop' })).toBeVisible()
    await expect(canvas.getByLabelText('Default flow')).toBeChecked()
  },
}

const withHook = {
  ...narrativeFlows,
  flows: [
    {
      key: 'heist',
      usedBy: [],
      definition: {
        ...narrativeFlows.flows[0]?.definition,
        phases: [
          {
            key: 'setup',
            name: 'Setup',
            mode: 'loop',
            selection: { rule: 'oracle', table: 'places' },
            worldTurn: [
              { key: 'tick', kind: 'prompt', title: 'The street stirs' },
              { key: 'tock', kind: 'prompt', title: 'A door slams' },
            ],
          },
        ],
      },
    },
    ...narrativeFlows.flows.slice(1),
  ],
}

/** A phase that picks its scenes from an oracle table: open its selection from the outline. */
export const OracleSelection: Story = {
  parameters: { api: { [`GET ${base}/narrative`]: json(withHook) } },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: /^Selection:/ }))
    await expect(await canvas.findByLabelText('Oracle table')).toHaveValue('places')
  },
}

/** A step selected in the outline: its hook's list shows, the step marked and scrolled into view. */
export const SelectedStep: Story = {
  args: { node: 'phases.0.hooks.worldTurn.1' },
  parameters: { api: { [`GET ${base}/narrative`]: json(withHook) } },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('group', { name: 'Step 2' })).toHaveAttribute(
      'aria-current',
      'true',
    )
    await expect(canvas.getByDisplayValue('A door slams')).toBeVisible()
  },
}

/** A flow with a single phase: it cannot be deleted. */
export const SinglePhase: Story = { args: { flow: 'side-job' } }

/** A flow of a shape the editor cannot read is not opened. */
export const UnreadableShape: Story = { args: { flow: 'odd-flow' } }

export const NotFound: Story = { args: { flow: 'nope' } }
