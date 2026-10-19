import type { Meta, StoryObj } from '@storybook/react-vite'

import { onTheme } from '@/shared/theme/themeStories'
import { Button } from './button'

const meta = {
  title: 'Shared/UI/Button',
  component: Button,
  args: { children: 'Roll the dice' },
  argTypes: {
    variant: {
      control: 'select',
      options: ['default', 'secondary', 'outline', 'ghost', 'destructive', 'link'],
    },
    size: { control: 'select', options: ['xs', 'sm', 'default', 'lg', 'icon'] },
  },
} satisfies Meta<typeof Button>

export default meta
type Story = StoryObj<typeof meta>

export const Primary: Story = {}

export const Secondary: Story = { args: { variant: 'secondary' } }

export const Outline: Story = { args: { variant: 'outline' } }

export const Ghost: Story = { args: { variant: 'ghost' } }

export const Link: Story = { args: { variant: 'link' } }

export const Destructive: Story = { args: { variant: 'destructive', children: 'Abandon campaign' } }

export const Disabled: Story = { args: { disabled: true } }

export const Sizes: Story = {
  render: (args) => (
    <div className="flex items-center gap-2">
      <Button {...args} size="xs">
        Extra small
      </Button>
      <Button {...args} size="sm">
        Small
      </Button>
      <Button {...args}>Default</Button>
      <Button {...args} size="lg">
        Large
      </Button>
    </div>
  ),
}

export const Parchment: Story = onTheme('parchment', Primary)
export const Neon: Story = onTheme('neon', Primary)
export const Noir: Story = onTheme('noir', Primary)
export const Eldritch: Story = onTheme('eldritch', Primary)
export const Starship: Story = onTheme('starship', Primary)
