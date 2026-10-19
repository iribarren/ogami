import type { Meta, StoryObj } from '@storybook/react-vite'

import { onTheme } from '@/shared/theme/themeStories'
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

export const Parchment: Story = onTheme('parchment', Default)
export const Neon: Story = onTheme('neon', Default)
export const Noir: Story = onTheme('noir', Default)
export const Eldritch: Story = onTheme('eldritch', Default)
export const Starship: Story = onTheme('starship', Default)
