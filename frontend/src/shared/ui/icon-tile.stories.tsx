import type { Meta, StoryObj } from '@storybook/react-vite'
import { Feather, Target } from 'lucide-react'

import { DieShape } from './die-shape'
import { onTheme } from '@/shared/theme/themeStories'

import { IconTile } from './icon-tile'

const meta = {
  title: 'Shared/UI/IconTile',
  component: IconTile,
  args: { children: <Feather /> },
  render: (args) => (
    <div className="flex items-end gap-3 text-card-foreground">
      <IconTile {...args} size="xs" />
      <IconTile {...args} size="rail" />
      <IconTile {...args} size="sm" />
      <IconTile {...args} size="md" />
      <IconTile {...args} size="lg" />
      <IconTile size="lg">
        <Target />
      </IconTile>
      <IconTile size="lg">
        <DieShape sides={20} inner className="text-current" />
      </IconTile>
    </div>
  ),
} satisfies Meta<typeof IconTile>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Parchment: Story = onTheme('parchment', {})
export const Neon: Story = onTheme('neon', {})
export const Noir: Story = onTheme('noir', {})
export const Eldritch: Story = onTheme('eldritch', {})
export const Starship: Story = onTheme('starship', {})
