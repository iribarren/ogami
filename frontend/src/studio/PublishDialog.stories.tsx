import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { draft, published3, validDraft } from './fixtures'
import { PublishDialog } from './PublishDialog'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const meta = {
  title: 'Studio/PublishDialog',
  component: PublishDialog,
  args: { gameSystem: draft, onPublished: () => undefined },
  parameters: {
    api: { 'GET /api/studio/game-systems/my-draft/validation': json(validDraft) },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
  // Every story opens the dialog, as a person would.
  play: async ({ canvasElement }) => {
    await userEvent.click(await within(canvasElement).findByRole('button', { name: 'Publish' }))
  },
} satisfies Meta<typeof PublishDialog>

export default meta
type Story = StoryObj<typeof meta>

const dialog = () => within(document.body)

export const FirstPublish: Story = {
  play: async (context) => {
    await meta.play(context)
    await expect(await dialog().findByText('This creates version 1.')).toBeInTheDocument()
  },
}

export const NextVersionWithWarnings: Story = {
  args: { gameSystem: published3 },
  parameters: {
    api: {
      'GET /api/studio/game-systems/noir-detective/validation': json({
        valid: true,
        errors: [],
        warnings: ['oracles.tables[0]: has no entries'],
      }),
    },
  },
  play: async (context) => {
    await meta.play(context)
    await expect(await dialog().findByText('This creates version 4.')).toBeInTheDocument()
  },
}

export const InvalidDraft: Story = {
  parameters: {
    api: {
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
  play: async (context) => {
    await meta.play(context)
    await expect(await dialog().findByRole('alert')).toBeInTheDocument()
  },
}

export const RefusedAsUnchanged: Story = {
  parameters: {
    api: {
      'GET /api/studio/game-systems/my-draft/validation': json(validDraft),
      'POST /api/studio/game-systems/my-draft/releases': json(
        { error: 'No changes: "my-draft" is the same as its release v1.' },
        409,
      ),
    },
  },
  play: async (context) => {
    await meta.play(context)
    await userEvent.click(await dialog().findByRole('button', { name: 'Publish version 1' }))
    await expect(await dialog().findByRole('alert')).toBeInTheDocument()
  },
}
