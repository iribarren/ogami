import type { Meta, StoryObj } from '@storybook/react-vite'

import { gateNote, keepThreeRoll, lockedDoorAnswer, plainAnswer, stormyWeather } from './fixtures'
import { JournalEntryView } from './JournalEntryView'

const meta = {
  title: 'Play/JournalEntryView',
  component: JournalEntryView,
  args: { entry: gateNote },
  decorators: [
    (Story) => (
      <div className="max-w-xl">
        <Story />
      </div>
    ),
  ],
} satisfies Meta<typeof JournalEntryView>

export default meta
type Story = StoryObj<typeof meta>

export const Note: Story = {}

export const Roll: Story = { args: { entry: keepThreeRoll } }

export const OracleTable: Story = { args: { entry: stormyWeather } }

export const LikelihoodAnswer: Story = { args: { entry: lockedDoorAnswer } }

export const LikelihoodAnswerWithoutQuestionOrChaos: Story = { args: { entry: plainAnswer } }
