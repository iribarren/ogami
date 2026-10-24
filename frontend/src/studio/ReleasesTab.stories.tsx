import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { releaseHistory, releaseTwo } from './fixtures'
import { ReleasesTab } from './ReleasesTab'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/noir-detective'

const meta = {
  title: 'Studio/ReleasesTab',
  component: ReleasesTab,
  args: {
    gameSystemKey: 'noir-detective',
    onReload: () => undefined,
    onRestored: () => undefined,
  },
  parameters: {
    api: {
      [`GET ${base}/releases`]: json(releaseHistory),
      [`GET ${base}/releases/2`]: json(releaseTwo),
    },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof ReleasesTab>

export default meta
type Story = StoryObj<typeof meta>

export const History: Story = {}

export const NeverPublished: Story = {
  parameters: { api: { [`GET ${base}/releases`]: json([]) } },
}

export const LoadFailed: Story = {
  parameters: {
    api: { [`GET ${base}/releases`]: json({ error: 'No such GameSystem.' }, 404) },
  },
}

export const ReleaseView: Story = {
  play: async ({ canvasElement }) => {
    await userEvent.click(
      await within(canvasElement).findByRole('button', { name: 'View version 2' }),
    )
    await expect(
      await within(canvasElement).findByRole('button', { name: 'Restore into the draft' }),
    ).toBeInTheDocument()
  },
}

export const ReleaseViewWithChanges: Story = {
  parameters: {
    api: {
      [`GET ${base}/releases`]: json(releaseHistory),
      [`GET ${base}/releases/2`]: json({
        ...releaseTwo,
        changes: {
          previousVersion: 1,
          previousSchemaVersion: 2,
          sections: {
            oracleTables: {
              added: [{ key: 'omens', name: 'Omens' }],
              removed: [{ key: 'fog', name: null }],
              changed: [
                {
                  key: 'weather',
                  name: 'Forecast',
                  previousName: 'Weather',
                  fields: ['bands', 'dice'],
                },
              ],
              reordered: true,
            },
            trackers: { added: [], removed: [], changed: [], reordered: false },
          },
        },
      }),
    },
  },
  play: async ({ canvasElement }) => {
    await userEvent.click(
      await within(canvasElement).findByRole('button', { name: 'View version 2' }),
    )
    await expect(
      await within(canvasElement).findByRole('group', { name: 'Changes since version 1' }),
    ).toBeInTheDocument()
  },
}

export const RestoreRefusedAsConcurrent: Story = {
  parameters: {
    api: {
      [`GET ${base}/releases`]: json(releaseHistory),
      [`GET ${base}/releases/2`]: json(releaseTwo),
      [`POST ${base}/restore`]: json({ error: 'The GameSystem was changed; reload.' }, 409),
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'View version 2' }))
    await userEvent.click(await canvas.findByRole('button', { name: 'Restore into the draft' }))
    const dialog = within(document.body)
    await userEvent.click(await dialog.findByRole('button', { name: 'Restore version 2' }))
    await expect(await dialog.findByRole('alert')).toBeInTheDocument()
  },
}
