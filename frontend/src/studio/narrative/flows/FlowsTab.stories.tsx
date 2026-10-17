import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { flowIssues, narrativeFlows, narrativeV1, validDraft } from '../../fixtures'
import { FlowsTab } from './FlowsTab'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/FlowsTab',
  component: FlowsTab,
  args: { gameSystemKey: 'my-draft' },
  parameters: {
    api: {
      [`GET ${base}/narrative`]: json(narrativeFlows),
      [`GET ${base}/validation`]: json(validDraft),
    },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof FlowsTab>

export default meta
type Story = StoryObj<typeof meta>

/** The default flow carries its badge; a flow the draft uses cannot be deleted; one of an unknown shape is listed read-only. */
export const Default: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByText('Default')).toBeInTheDocument()
    await expect(canvas.getByRole('button', { name: 'Delete side-job' })).toBeDisabled()
    await expect(canvas.getByRole('button', { name: 'Delete odd-flow' })).toBeEnabled()
  },
}

/** The validation's error and warnings about a flow show as a count on its row. */
export const WithIssues: Story = {
  parameters: {
    api: {
      [`GET ${base}/narrative`]: json(narrativeFlows),
      [`GET ${base}/validation`]: json({ ...flowIssues, warnings: ['flows[1].phases[0]: odd'] }),
    },
  },
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByText('2 issues')).toBeInTheDocument()
  },
}

export const Empty: Story = {
  parameters: {
    api: {
      [`GET ${base}/narrative`]: json({ ...narrativeFlows, flows: [] }),
      [`GET ${base}/validation`]: json(validDraft),
    },
  },
}

/** A flow needs a Scene Type to start with: without one the button says why it is off. */
export const NoSceneTypes: Story = {
  parameters: {
    api: {
      [`GET ${base}/narrative`]: json({ ...narrativeFlows, sceneTypes: [] }),
      [`GET ${base}/validation`]: json(validDraft),
    },
  },
  play: async ({ canvasElement }) => {
    await expect(
      await within(canvasElement).findByRole('button', { name: 'New flow' }),
    ).toBeDisabled()
  },
}

/** A schema version 1 draft has no narrative sections. */
export const SchemaVersion1: Story = {
  parameters: { api: { [`GET ${base}/narrative`]: json(narrativeV1) } },
}

export const NewFlowDialog: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'New flow' }))
    await expect(within(document.body).getByLabelText('First Scene Type')).toBeInTheDocument()
  },
}
