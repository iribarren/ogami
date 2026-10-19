import type { Meta, StoryObj } from '@storybook/react-vite'

import { onTheme } from '@/shared/theme/themeStories'

import { DieShape } from './DieShape'

const meta = {
  title: 'Play/DieShape',
  component: DieShape,
  args: { sides: 20 },
  render: (args) => (
    <div className="flex items-center gap-4">
      {([4, 6, 8, 10, 12, 20] as const).map((sides) => (
        <DieShape key={sides} {...args} sides={sides} className="size-8" />
      ))}
      <DieShape sides={20} inner className="size-8" />
    </div>
  ),
} satisfies Meta<typeof DieShape>

export default meta
type Story = StoryObj<typeof meta>

/** Every die outline, then the d20 with its inner triangle. */
export const Gallery: Story = {}

export const GalleryParchment: Story = onTheme('parchment', Gallery)
export const GalleryNeon: Story = onTheme('neon', Gallery)
export const GalleryNoir: Story = onTheme('noir', Gallery)
export const GalleryEldritch: Story = onTheme('eldritch', Gallery)
export const GalleryStarship: Story = onTheme('starship', Gallery)
