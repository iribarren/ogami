import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { importsMixed, importsNone } from './fixtures'
import { LibraryTab } from './LibraryTab'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const imports = '/api/studio/game-systems/my-draft/library-imports'

const meta = {
  title: 'Studio/Library/LibraryTab',
  component: LibraryTab,
  args: { gameSystemKey: 'my-draft', schemaVersion: 2 },
  parameters: { api: { [`GET ${imports}`]: json(importsMixed) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof LibraryTab>

export default meta
type Story = StoryObj<typeof meta>

export const MixedStatuses: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByText('Edited locally')).toBeInTheDocument()
    await expect(canvas.getByText('Removed pack')).toBeInTheDocument()
    await expect(canvas.getByRole('button', { name: 'Update fame' })).toBeDisabled()
  },
}

export const UpdateOverLocalEdits: Story = {
  play: async ({ canvasElement }) => {
    await userEvent.click(
      await within(canvasElement).findByRole('button', { name: 'Update tavern' }),
    )
    await expect(
      await within(document.body).findByText(/replaces your local edits/),
    ).toBeInTheDocument()
  },
}

export const StaleVersion: Story = {
  parameters: {
    api: {
      [`GET ${imports}`]: json(importsMixed),
      [`POST ${imports}/tags/social/update`]: json(
        { error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.' },
        409,
      ),
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Update social' }))
    await expect(
      await canvas.findByRole('button', { name: 'Reload the latest version' }),
    ).toBeInTheDocument()
  },
}

export const Empty: Story = {
  parameters: { api: { [`GET ${imports}`]: json(importsNone) } },
  play: async ({ canvasElement }) => {
    await expect(
      await within(canvasElement).findByText(/has imported nothing from the library yet/),
    ).toBeInTheDocument()
  },
}

export const SchemaVersion1: Story = {
  args: { schemaVersion: 1 },
  play: async ({ canvasElement }) => {
    await expect(
      await within(canvasElement).findByText(/Schema version 1 drafts import nothing/),
    ).toBeInTheDocument()
  },
}
