import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { draft } from './fixtures'
import { MetadataForm } from './MetadataForm'

const handlers: Record<string, FakeHandler> = {
  'PUT /api/studio/game-systems/my-draft/metadata': () => Response.json(draft),
}

const meta = {
  title: 'Studio/MetadataForm',
  component: MetadataForm,
  args: { gameSystem: draft, onReload: () => undefined },
  decorators: [
    (Story) => (
      <StoryProviders handlers={handlers}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof MetadataForm>

export default meta
type Story = StoryObj<typeof meta>

/** A draft with credits and no badge. */
export const Credits: Story = {}

/** The Dark Pack badge: its logo shows beside the picker. */
export const WithDarkPackBadge: Story = {
  args: {
    gameSystem: {
      ...draft,
      credits: { notice: 'Unofficial fan content.', license: null, url: null, badge: 'dark-pack' },
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(canvas.getByRole('img', { name: 'Dark Pack logo' })).toBeVisible()
    await userEvent.click(canvas.getByRole('button', { name: 'Save' }))
  },
}
