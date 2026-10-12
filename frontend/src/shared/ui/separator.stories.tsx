import type { Meta, StoryObj } from '@storybook/react-vite'

import { Separator } from './separator'

const meta = {
  title: 'Shared/UI/Separator',
  component: Separator,
} satisfies Meta<typeof Separator>

export default meta
type Story = StoryObj<typeof meta>

export const Horizontal: Story = {
  render: (args) => (
    <div className="w-72 space-y-3 text-sm">
      <p>Journal</p>
      <Separator {...args} />
      <p>Sidebar</p>
    </div>
  ),
}

export const Vertical: Story = {
  render: (args) => (
    <div className="flex h-5 items-center gap-3 text-sm">
      <span>Dice</span>
      <Separator {...args} orientation="vertical" />
      <span>Oracles</span>
    </div>
  ),
}
