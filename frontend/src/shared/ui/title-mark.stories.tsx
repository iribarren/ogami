import type { Meta, StoryObj } from '@storybook/react-vite'

import { onTheme } from '@/shared/theme/themeStories'

import { OrnamentDivider } from './ornament-divider'
import { TitleMark } from './title-mark'

const meta = {
  title: 'Shared/UI/TitleMark',
  component: TitleMark,
  args: { name: 'The lost mine' },
  // The mark sits before a title and the divider under it, as on the campaign page.
  decorators: [
    (Story) => (
      <div className="w-96">
        <div className="flex items-center gap-4">
          <Story />
          <p className="font-heading text-page-title">The lost mine</p>
        </div>
        <OrnamentDivider />
      </div>
    ),
  ],
} satisfies Meta<typeof TitleMark>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Parchment: Story = onTheme('parchment', Default)
export const Neon: Story = onTheme('neon', Default)
export const Noir: Story = onTheme('noir', Default)
export const Eldritch: Story = onTheme('eldritch', Default)
export const Starship: Story = onTheme('starship', Default)

/** A name with no letter or digit shows the seal's fallback glyph. */
export const NoInitial: Story = onTheme('parchment', { args: { name: '🐉' } })
