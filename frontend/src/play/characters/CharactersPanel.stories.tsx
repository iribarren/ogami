import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import { StoryProviders } from '@/test/StoryProviders'

import { lostMineWithCharacters, nightJobCampaign, oldCharacter, viCharacter } from './fixtures'
import { CharactersPanel } from './CharactersPanel'

const meta = {
  title: 'Play/CharactersPanel',
  component: CharactersPanel,
  args: { campaign: { ...nightJobCampaign, characters: [viCharacter, oldCharacter] } },
  decorators: [
    (Story) => (
      <StoryProviders
        handlers={{
          [`PUT /api/campaigns/${nightJobCampaign.id}/characters/${viCharacter.id}`]: () =>
            Response.json({ error: 'Field "reflexes" must be 1 to 8, got 9.' }, { status: 422 }),
        }}
      >
        <div className="max-w-sm">
          <Story />
        </div>
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof CharactersPanel>

export default meta
type Story = StoryObj<typeof meta>

/** A sheet with text, numbers, tracks and a list; the second Character predates the fields. */
export const WithSheet: Story = {}

/** A release without sheet fields: name and Tags only. */
export const NoSheet: Story = { args: { campaign: lostMineWithCharacters } }

export const Empty: Story = { args: { campaign: { ...nightJobCampaign, characters: [] } } }

/** The form groups the fields; a fixed track max is read-only. */
export const AddForm: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Add Character' }))
    await expect(canvas.getByRole('group', { name: 'Stats' })).toBeVisible()
    await expect(canvas.getByLabelText('HP max')).toHaveAttribute('readonly')
  },
}

/** A refused edit keeps the form as typed and shows why. */
export const EditRefused: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit Vi' }))
    await userEvent.click(canvas.getByRole('button', { name: 'Save' }))
    await expect(await canvas.findByRole('alert')).toHaveTextContent('must be 1 to 8')
    await expect(canvas.getByLabelText('Name')).toHaveValue('Vi')
  },
}
