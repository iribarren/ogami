import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, fn, userEvent, within } from 'storybook/test'

import { DiceRoller } from './DiceRoller'

const meta = {
  title: 'Play/DiceRoller',
  component: DiceRoller,
  args: { onRoll: fn() },
  decorators: [
    (Story) => (
      <div className="max-w-md">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof DiceRoller>

export default meta
type Story = StoryObj<typeof meta>

export const Empty: Story = {}

export const Rolling: Story = {
  args: { initialExpression: '4d6kh3+2' },
  play: async ({ args, canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('button', { name: 'Roll' }))
    await expect(args.onRoll).toHaveBeenCalledWith('4d6kh3+2')
  },
}

export const Pending: Story = { args: { initialExpression: '2d6', pending: true } }

export const Refused: Story = {
  args: { initialExpression: '2x6', error: new Error('Unexpected character "x" at position 2.') },
}

/** No current scene: there is nowhere to record a roll. */
export const Disabled: Story = { args: { disabled: true } }

export const WithoutPresets: Story = { args: { presets: [] } }
