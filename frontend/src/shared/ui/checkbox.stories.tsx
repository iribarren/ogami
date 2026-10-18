import type { Meta, StoryObj } from '@storybook/react-vite'

import { Checkbox } from './checkbox'
import { Label } from './label'

const meta = {
  title: 'Shared/UI/Checkbox',
  component: Checkbox,
} satisfies Meta<typeof Checkbox>

export default meta
type Story = StoryObj<typeof meta>

export const WithLabel: Story = {
  render: (args) => (
    <div className="flex items-center gap-2">
      <Checkbox id="story-terms" {...args} />
      <Label htmlFor="story-terms">Game manager</Label>
    </div>
  ),
}

export const Checked: Story = { args: { defaultChecked: true } }

export const Disabled: Story = { args: { defaultChecked: true, disabled: true } }
