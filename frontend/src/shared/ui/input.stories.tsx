import type { Meta, StoryObj } from '@storybook/react-vite'

import { onTheme } from '@/shared/theme/themeStories'
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

export const Parchment: Story = onTheme('parchment', Filled)
export const Neon: Story = onTheme('neon', Filled)
export const Noir: Story = onTheme('noir', Filled)
export const Eldritch: Story = onTheme('eldritch', Filled)
export const Starship: Story = onTheme('starship', Filled)
