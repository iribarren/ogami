import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { sheet, sheetUngrouped, sheetV1 } from '../fixtures'
import { SheetTab } from './SheetTab'
import { useSampleValues } from './useSheetSamples'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

/** The tab with the sample values the GameSystem page keeps for it. */
function WithSamples({ gameSystemKey }: { gameSystemKey: string }) {
  return <SheetTab gameSystemKey={gameSystemKey} sampleValues={useSampleValues()} />
}

const meta = {
  title: 'Studio/SheetTab',
  component: WithSamples,
  args: { gameSystemKey: 'my-draft' },
  parameters: { api: { [`GET ${base}/sheet`]: json(sheet) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof WithSamples>

export default meta
type Story = StoryObj<typeof meta>

/** Declared groups in order, an empty one included; the first field of a group cannot move up. */
export const Default: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('button', { name: 'Move up pow' })).toBeDisabled()
  },
}

/** Without declared groups the list keeps its order, with a heading where the group changes. */
export const WithoutGroups: Story = {
  parameters: { api: { [`GET ${base}/sheet`]: json(sheetUngrouped) } },
}

export const AddField: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Add field' }))
    await expect(
      await within(document.body).findByRole('dialog', { name: 'New field' }),
    ).toBeVisible()
  },
}

export const Empty: Story = {
  parameters: { api: { [`GET ${base}/sheet`]: json({ ...sheetUngrouped, fields: [] }) } },
}

/** A schema version 1 draft has no sheet fields. */
export const SchemaVersion1: Story = {
  parameters: { api: { [`GET ${base}/sheet`]: json(sheetV1) } },
}

export const LoadFailed: Story = {
  parameters: {
    api: { [`GET ${base}/sheet`]: () => new Response('Oops', { status: 500 }) },
  },
}
