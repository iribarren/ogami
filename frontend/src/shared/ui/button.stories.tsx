import type { Meta, StoryObj } from '@storybook/react-vite'

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

export const Outline: Story = { args: { variant: 'outline' } }

export const Destructive: Story = { args: { variant: 'destructive', children: 'Abandon campaign' } }
