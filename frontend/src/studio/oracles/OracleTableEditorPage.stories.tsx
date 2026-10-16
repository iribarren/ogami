import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { oracles, oraclesV1, stormRoll } from '../fixtures'
import { OracleTableEditorPage } from './OracleTableEditorPage'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/OracleTableEditorPage',
  component: OracleTableEditorPage,
  args: { gameSystemKey: 'my-draft', table: 'weather' },
  parameters: {
    api: {
      [`GET ${base}/oracles`]: json(oracles),
      [`POST ${base}/oracle-tables/weather/test-rolls`]: json(stormRoll),
    },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof OracleTableEditorPage>

export default meta
type Story = StoryObj<typeof meta>

const [weather, storm] = oracles.tables
const withGap = {
  ...oracles,
  tables: [
    {
      ...weather,
      definition: {
        ...weather?.definition,
        entries: [{ ...weather?.definition.entries[0], max: 3 }, weather?.definition.entries[1]],
      },
    },
    storm,
  ],
}

/** A ranged table; its entries cover every total of the dice, and one nests another table. */
export const Ranged: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByText('Every total is covered exactly once.')).toBeVisible()
  },
}

/** Entry 1 stops at 3, so a roll of 4 is not covered: the editor says so while it is edited. */
export const RangedWithGap: Story = {
  parameters: { api: { [`GET ${base}/oracles`]: json(withGap) } },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByText('No entry covers 4.')).toBeVisible()
  },
}

export const Weighted: Story = {
  args: { table: 'storm-kind' },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByLabelText('Entry 1 weight')).toHaveValue('2')
  },
}

/** A schema version 1 table has no entry keys. */
export const SchemaVersion1: Story = {
  parameters: { api: { [`GET ${base}/oracles`]: json(oraclesV1) } },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByLabelText('Entry 1 text')).toBeVisible()
    await expect(canvas.queryByLabelText('Entry 1 key')).not.toBeInTheDocument()
  },
}

export const NotFound: Story = {
  args: { table: 'nope' },
}
