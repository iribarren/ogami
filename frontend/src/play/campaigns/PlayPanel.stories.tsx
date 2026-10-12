import type { Meta, StoryObj } from '@storybook/react-vite'

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
