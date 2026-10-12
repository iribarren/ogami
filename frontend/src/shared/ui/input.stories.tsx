import type { Meta, StoryObj } from '@storybook/react-vite'

import { Input } from './input'

const meta = {
  title: 'Shared/UI/Input',
  component: Input,
  args: { placeholder: 'Character name', 'aria-label': 'Character name' },
  decorators: [
    (Story) => (
      <div className="w-72">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof Input>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Filled: Story = { args: { defaultValue: 'Ada Vermilion' } }

export const Invalid: Story = { args: { 'aria-invalid': true, defaultValue: 'Ada' } }

export const Disabled: Story = { args: { disabled: true } }
