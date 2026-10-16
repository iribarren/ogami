import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { oracles, oraclesV1Likelihood, oraclesWithChaos } from '../fixtures'
import { LikelihoodOracleEditorPage } from './LikelihoodOracleEditorPage'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/LikelihoodOracleEditorPage',
  component: LikelihoodOracleEditorPage,
  args: { gameSystemKey: 'my-draft', oracle: 'fate' },
  parameters: {
    api: {
      [`GET ${base}/oracles`]: json(oraclesWithChaos),
    },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof LikelihoodOracleEditorPage>

export default meta
type Story = StoryObj<typeof meta>

/** An oracle with chaos, exceptional bands and a random event table; its tracker is read-only. */
export const WithChaos: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByLabelText('Chaos max')).toHaveValue('9')
    await expect(canvas.getByText(/not edited here/)).toBeVisible()
  },
}

export const WithoutChaos: Story = {
  parameters: { api: { [`GET ${base}/oracles`]: json(oracles) } },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByLabelText('Level 2 target')).toHaveValue('65')
    await expect(canvas.getByLabelText('Has a chaos factor')).not.toBeChecked()
  },
}

/** A schema version 1 oracle has no random event table and no chaos tracker. */
export const SchemaVersion1: Story = {
  parameters: { api: { [`GET ${base}/oracles`]: json(oraclesV1Likelihood) } },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByLabelText('Level 1 target')).toBeVisible()
    await expect(canvas.queryByLabelText('Random event table')).not.toBeInTheDocument()
  },
}

export const NotFound: Story = {
  args: { oracle: 'nope' },
}
