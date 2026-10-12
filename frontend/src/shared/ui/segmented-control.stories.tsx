import type { Meta, StoryObj } from '@storybook/react-vite'

import { SegmentedControl, SegmentedControlItem } from './segmented-control'

const meta = {
  title: 'Shared/UI/SegmentedControl',
  component: SegmentedControl,
  args: { 'aria-label': 'View', defaultValue: 'journal' },
  render: (args) => (
    <SegmentedControl {...args}>
      <SegmentedControlItem value="journal">Journal</SegmentedControlItem>
      <SegmentedControlItem value="focus">Focus</SegmentedControlItem>
    </SegmentedControl>
  ),
} satisfies Meta<typeof SegmentedControl>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const FocusSelected: Story = { args: { defaultValue: 'focus' } }
