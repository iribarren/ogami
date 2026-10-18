import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { draft, neverPublished, published, withChanges, withLibraryChanges } from './fixtures'
import { StudioHomePage } from './StudioHomePage'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const studioApi = {
  'GET /api/studio/game-systems': json([neverPublished, withChanges, published]),
  'POST /api/studio/game-systems': json(draft, 201),
} satisfies Record<string, FakeHandler>

const meta = {
  title: 'Studio/StudioHomePage',
  component: StudioHomePage,
  parameters: { api: studioApi },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof StudioHomePage>

export default meta
type Story = StoryObj<typeof meta>

export const WithGameSystems: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('link', { name: 'My draft' })).toBeInTheDocument()
  },
}

export const WithLibraryChanges: Story = {
  parameters: {
    api: { ...studioApi, 'GET /api/studio/game-systems': json([withLibraryChanges, published]) },
  },
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByText('3 library changes')).toBeInTheDocument()
  },
}

export const NoGameSystems: Story = {
  parameters: { api: { ...studioApi, 'GET /api/studio/game-systems': json([]) } },
}

export const GameSystemsUnavailable: Story = {
  parameters: {
    api: {
      ...studioApi,
      'GET /api/studio/game-systems': () => new Response('Oops', { status: 500 }),
    },
  },
}
