import type { Meta, StoryObj } from '@storybook/react-vite'

import { NativeSelect } from './native-select'

const meta = {
  title: 'Shared/UI/NativeSelect',
  component: NativeSelect,
  args: { 'aria-label': 'Oracle' },
  decorators: [
    (Story) => (
      <div className="w-72">
        <Story />
      </div>
    ),
  ],
  render: (args) => (
    <NativeSelect {...args}>
      <option>Yes / no</option>
      <option>Action / theme</option>
      <option>Place</option>
    </NativeSelect>
  ),
} satisfies Meta<typeof NativeSelect>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Disabled: Story = { args: { disabled: true } }
