import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, fn, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import {
  catalog,
  myPack,
  otherPack,
  previewClean,
  previewConflict,
  previewError,
  previewWithOverride,
} from './fixtures'
import { ImportDialog } from './ImportDialog'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const imports = '/api/studio/game-systems/my-draft/library-imports'

const meta = {
  title: 'Studio/Library/ImportDialog',
  component: ImportDialog,
  args: {
    gameSystemKey: 'my-draft',
    version: 4,
    open: true,
    onOpenChange: fn(),
    onReload: () => Promise.resolve(true),
  },
  parameters: {
    api: {
      'GET /api/studio/library/packs': json([myPack, otherPack]),
      'GET /api/studio/library/packs/fantasy-basics': json(catalog),
      [`POST ${imports}/preview`]: json(previewClean),
    },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof ImportDialog>

export default meta
type Story = StoryObj<typeof meta>

const dialog = async () => within(await within(document.body).findByRole('dialog'))

async function previewTavern() {
  const body = await dialog()
  await userEvent.click(await body.findByRole('button', { name: 'Choose Fantasy basics' }))
  await userEvent.click(await body.findByRole('checkbox', { name: /Tavern/ }))
  await userEvent.click(body.getByRole('button', { name: 'Preview' }))
  return body
}

export const ChoosePack: Story = {
  play: async () => {
    await expect(
      await (await dialog()).findByRole('button', { name: 'Choose Wild tales' }),
    ).toBeInTheDocument()
  },
}

export const Catalog: Story = {
  play: async () => {
    const body = await dialog()
    await userEvent.click(await body.findByRole('button', { name: 'Choose Fantasy basics' }))
    await expect(await body.findByText(/Also brings names, social/)).toBeInTheDocument()
  },
}

export const PreviewClean: Story = {
  play: async () => {
    const body = await previewTavern()
    await expect(await body.findByRole('region', { name: 'Preview' })).toBeInTheDocument()
    await expect(body.getByRole('button', { name: 'Import' })).toBeEnabled()
  },
}

export const PreviewWithOverride: Story = {
  parameters: {
    api: {
      'GET /api/studio/library/packs': json([myPack, otherPack]),
      'GET /api/studio/library/packs/fantasy-basics': json(catalog),
      [`POST ${imports}/preview`]: json(previewWithOverride),
    },
  },
  play: async () => {
    await expect(
      await (await previewTavern()).findByText('Kept from your draft'),
    ).toBeInTheDocument()
  },
}

export const PreviewWithConflict: Story = {
  parameters: {
    api: {
      'GET /api/studio/library/packs': json([myPack, otherPack]),
      'GET /api/studio/library/packs/fantasy-basics': json(catalog),
      [`POST ${imports}/preview`]: json(previewConflict),
    },
  },
  play: async () => {
    const body = await previewTavern()
    await expect(await body.findByText('Already in your draft')).toBeInTheDocument()
    await expect(body.getByRole('button', { name: 'Import' })).toBeDisabled()
  },
}

export const PreviewWithError: Story = {
  parameters: {
    api: {
      'GET /api/studio/library/packs': json([myPack, otherPack]),
      'GET /api/studio/library/packs/fantasy-basics': json(catalog),
      [`POST ${imports}/preview`]: json(previewError),
    },
  },
  play: async () => {
    const body = await previewTavern()
    await expect(await body.findByRole('alert')).toHaveTextContent('sceneTypes[0].closing[0]')
    await expect(body.getByRole('button', { name: 'Import' })).toBeDisabled()
  },
}

export const StaleVersion: Story = {
  parameters: {
    api: {
      'GET /api/studio/library/packs': json([myPack, otherPack]),
      'GET /api/studio/library/packs/fantasy-basics': json(catalog),
      [`POST ${imports}/preview`]: json(previewClean),
      [`POST ${imports}`]: json(
        { error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.' },
        409,
      ),
    },
  },
  play: async () => {
    const body = await previewTavern()
    await userEvent.click(await body.findByRole('button', { name: 'Import' }))
    await expect(
      await body.findByRole('button', { name: 'Reload the latest version' }),
    ).toBeInTheDocument()
  },
}
