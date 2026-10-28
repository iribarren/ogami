import type { Meta, StoryObj } from '@storybook/react-vite'
import { fn } from 'storybook/test'

import { onTheme } from '@/shared/theme/themeStories'
import { StoryProviders } from '@/test/StoryProviders'

import { lostMineCampaign } from '../campaigns/fixtures'
import { guidedWithShop, atStep, promptStep, shopStep } from './fixtures'
import { StepCard } from './StepCard'

const meta = {
  title: 'Play/StepCard',
  component: StepCard,
  args: {
    campaign: lostMineCampaign,
    step: promptStep,
    pending: false,
    onCommand: fn(),
    frame: true,
  },
  decorators: [
    (Story) => (
      <StoryProviders handlers={{}}>
        <div className="max-w-xl">
          <Story />
        </div>
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof StepCard>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

/** A shop step in a campaign with a currency, a catalog and a Character who holds gear. */
export const Shop: Story = {
  args: { campaign: guidedWithShop(atStep(shopStep)), step: shopStep },
}

export const Unframed: Story = { args: { frame: false } }

export const Parchment: Story = onTheme('parchment', Default)
export const Neon: Story = onTheme('neon', Default)
export const Noir: Story = onTheme('noir', Default)
export const Eldritch: Story = onTheme('eldritch', Default)
export const Starship: Story = onTheme('starship', Default)
