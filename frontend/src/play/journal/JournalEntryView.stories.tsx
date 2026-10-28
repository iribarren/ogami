import type { Meta, StoryObj } from '@storybook/react-vite'

import { onTheme } from '@/shared/theme/themeStories'
import {
  bestialFailureEntry,
  flowOutcomeEntry,
  gateNote,
  keepThreeRoll,
  lockedDoorAnswer,
  luckCheckEntry,
  messyCriticalEntry,
  pickedNothing,
  pickedTwo,
  plainAnswer,
  pushedBestialEntry,
  shopEntry,
  skillCheckEntry,
  stormyWeather,
} from './fixtures'
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

export const PickFound: Story = {
  args: { entry: pickedTwo, tags: [{ key: 'leader', label: 'Leader' }] },
}

export const PickNoMatch: Story = { args: { entry: pickedNothing } }

export const CheckAgainstDifficulty: Story = { args: { entry: skillCheckEntry } }

export const CheckWithoutModifiersOrDifficulty: Story = { args: { entry: luckCheckEntry } }

/** Hunger dice in a second colour, and a special result as text (ADR 0044). */
export const HungerBestialFailure: Story = { args: { entry: bestialFailureEntry } }

export const HungerMessyCritical: Story = { args: { entry: messyCriticalEntry } }

export const HungerPushedToACritical: Story = { args: { entry: pushedBestialEntry } }

export const FlowOutcome: Story = { args: { entry: flowOutcomeEntry } }

export const RollParchment: Story = onTheme('parchment', Roll)
export const RollNeon: Story = onTheme('neon', Roll)
export const RollNoir: Story = onTheme('noir', Roll)
export const RollEldritch: Story = onTheme('eldritch', Roll)
export const RollStarship: Story = onTheme('starship', Roll)

export const NoteParchment: Story = onTheme('parchment', Note)
export const NoteNeon: Story = onTheme('neon', Note)
export const NoteNoir: Story = onTheme('noir', Note)
export const NoteEldritch: Story = onTheme('eldritch', Note)
export const NoteStarship: Story = onTheme('starship', Note)

export const Shop: Story = { args: { entry: shopEntry } }
