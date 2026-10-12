import type { Meta, StoryObj } from '@storybook/react-vite'

import { Textarea } from './textarea'

const meta = {
  title: 'Shared/UI/Textarea',
  component: Textarea,
  args: { placeholder: 'Write a journal entry', 'aria-label': 'Journal entry' },
  decorators: [
    (Story) => (
      <div className="w-96">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof Textarea>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Invalid: Story = { args: { 'aria-invalid': true, defaultValue: 'Too short' } }

export const Disabled: Story = { args: { disabled: true } }
