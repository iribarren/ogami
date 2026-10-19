import type { Meta, StoryObj } from '@storybook/react-vite'

import { onTheme } from '@/shared/theme/themeStories'
import { Input } from './input'
import { Label } from './label'

const meta = {
  title: 'Shared/UI/Label',
  component: Label,
} satisfies Meta<typeof Label>

export default meta
type Story = StoryObj<typeof meta>

export const WithInput: Story = {
  render: () => (
    <div className="grid w-72 gap-2">
      <Label htmlFor="story-name">Campaign name</Label>
      <Input id="story-name" placeholder="The sunken library" />
    </div>
  ),
}

export const Parchment: Story = onTheme('parchment', WithInput)
export const Neon: Story = onTheme('neon', WithInput)
export const Noir: Story = onTheme('noir', WithInput)
export const Eldritch: Story = onTheme('eldritch', WithInput)
export const Starship: Story = onTheme('starship', WithInput)
