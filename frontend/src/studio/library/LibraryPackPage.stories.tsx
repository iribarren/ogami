import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { catalog, othersCatalog } from './fixtures'
import { LibraryPackPage } from './LibraryPackPage'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const meta = {
  title: 'Studio/Library/LibraryPackPage',
  component: LibraryPackPage,
  args: { pack: 'fantasy-basics' },
  parameters: { api: { 'GET /api/studio/library/packs/fantasy-basics': json(catalog) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof LibraryPackPage>

export default meta
type Story = StoryObj<typeof meta>

export const MyPack: Story = {
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByText('Uses: names, social')).toBeInTheDocument()
  },
}

export const SomeoneElsesPack: Story = {
  args: { pack: 'wild-tales' },
  parameters: { api: { 'GET /api/studio/library/packs/wild-tales': json(othersCatalog) } },
}

export const NotFound: Story = {
  args: { pack: 'nobody' },
  parameters: {
    api: { 'GET /api/studio/library/packs/nobody': json({ error: 'No such pack.' }, 404) },
  },
}
