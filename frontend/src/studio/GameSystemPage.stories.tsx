import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { draft, draftWithLibraryChange, legacy, validDraft } from './fixtures'
import { GameSystemPage } from './GameSystemPage'
import { packSystem } from './library/fixtures'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const draftApi = {
  'GET /api/studio/game-systems/my-draft': json(draft),
  'GET /api/studio/game-systems/my-draft/validation': json(validDraft),
  'PUT /api/studio/game-systems/my-draft/metadata': json(draft),
} satisfies Record<string, FakeHandler>

const meta = {
  title: 'Studio/GameSystemPage',
  component: GameSystemPage,
  args: { gameSystemKey: 'my-draft' },
  parameters: { api: draftApi },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof GameSystemPage>

export default meta
type Story = StoryObj<typeof meta>

export const NeverPublishedDraft: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('button', { name: 'Discard draft' })).toBeInTheDocument()
  },
}

export const WithLibraryChange: Story = {
  parameters: {
    api: { ...draftApi, 'GET /api/studio/game-systems/my-draft': json(draftWithLibraryChange) },
  },
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByText('1 library change')).toBeInTheDocument()
  },
}

export const LibraryPack: Story = {
  args: { gameSystemKey: 'fantasy-basics' },
  parameters: {
    api: {
      'GET /api/studio/game-systems/fantasy-basics': json(packSystem),
      'GET /api/studio/game-systems/fantasy-basics/validation': json(validDraft),
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByText('Library pack')).toBeInTheDocument()
    await expect(canvas.queryByRole('link', { name: 'Releases' })).not.toBeInTheDocument()
  },
}

export const OnAnotherSection: Story = {
  args: { tab: 'sections' },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('link', { name: 'Sections' })).toHaveAttribute(
      'aria-current',
      'page',
    )
  },
}

export const PublishedSchemaVersion1: Story = {
  args: { gameSystemKey: 'free-journal' },
  parameters: {
    api: {
      'GET /api/studio/game-systems/free-journal': json(legacy),
      'GET /api/studio/game-systems/free-journal/validation': json(validDraft),
    },
  },
}

export const InvalidDraft: Story = {
  args: { tab: 'validation' },
  parameters: {
    api: {
      ...draftApi,
      'GET /api/studio/game-systems/my-draft/validation': json({
        valid: false,
        errors: [
          { path: 'oracles', message: 'must be an object' },
          { path: 'flows[0].phases[0].name', message: 'must not be blank' },
          { path: 'trackers[1].max', message: 'must be at least min (0), -1 given' },
          { path: 'sheet.fields[2].key', message: 'duplicate field key "pow"' },
        ],
        warnings: [],
      }),
    },
  },
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByRole('alert')).toBeInTheDocument()
  },
}

export const ValidWithWarnings: Story = {
  args: { tab: 'validation' },
  parameters: {
    api: {
      ...draftApi,
      'GET /api/studio/game-systems/my-draft/validation': json({
        valid: true,
        errors: [],
        warnings: ['oracles.tables[0]: has no entries'],
      }),
    },
  },
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByText('Warnings')).toBeInTheDocument()
  },
}

export const SaveRefusedAsConcurrent: Story = {
  parameters: {
    api: {
      ...draftApi,
      'PUT /api/studio/game-systems/my-draft/metadata': json(
        { error: 'The GameSystem was changed by someone else; reload and try again.' },
        409,
      ),
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Save' }))
    await expect(await canvas.findByRole('alert')).toBeInTheDocument()
  },
}

export const NotFound: Story = {
  parameters: {
    api: { 'GET /api/studio/game-systems/my-draft': json({ error: 'No such GameSystem.' }, 404) },
  },
}
