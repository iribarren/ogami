import type { Meta, StoryObj } from '@storybook/react-vite'
import { ScrollText } from 'lucide-react'

import { onTheme } from '@/shared/theme/themeStories'

import { PlayPanel } from './PlayPanel'

const meta = {
  title: 'Play/PlayPanel',
  component: PlayPanel,
  args: {
    id: 'story-facts',
    title: 'Facts',
    count: 3,
    children: <p className="text-sm">The harbour master owes the party a favour.</p>,
  },
  decorators: [
    (Story) => (
      <div className="w-(--play-sidebar-width) max-w-full">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof PlayPanel>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const WithoutCount: Story = { args: { id: 'story-dice', title: 'Dice', count: undefined } }

export const WithIcon: Story = { args: { icon: <ScrollText /> } }

export const WithIconParchment: Story = onTheme('parchment', WithIcon)
export const WithIconNeon: Story = onTheme('neon', WithIcon)
export const WithIconNoir: Story = onTheme('noir', WithIcon)
export const WithIconEldritch: Story = onTheme('eldritch', WithIcon)
export const WithIconStarship: Story = onTheme('starship', WithIcon)

/** The frame opt-in of the Dice and Oracles panels: neon corner, starship brackets. */
export const Framed: Story = { args: { id: 'story-dice', title: 'Dice', frame: true } }

export const FramedParchment: Story = onTheme('parchment', Framed)
export const FramedNeon: Story = onTheme('neon', Framed)
export const FramedNoir: Story = onTheme('noir', Framed)
export const FramedEldritch: Story = onTheme('eldritch', Framed)
export const FramedStarship: Story = onTheme('starship', Framed)
