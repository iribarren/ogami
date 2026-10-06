import type { Meta, StoryObj } from '@storybook/react-vite'

import { JournalWithPrompts, type JournalAction } from './JournalWithPrompts'
import { finishedSessionInputs, midSessionInputs, sunkenGateSession } from './sunkenGate'

// Flow presentation prototype (design spike): never imported by the app.
const meta = {
  title: 'Play/Flow prototypes/Journal with prompts',
  component: JournalWithPrompts,
  args: { session: sunkenGateSession() },
} satisfies Meta<typeof JournalWithPrompts>

export default meta
type Story = StoryObj<typeof meta>

const answers = (inputs: typeof midSessionInputs): JournalAction[] =>
  inputs.map((input) => ({ kind: 'answer', input }))

/** The whole scene from its first step; the oracle answers "No" (the gate is not guarded). */
export const FullSession: Story = {}

/** The same scene where the oracle answers "Yes": the guard branch. */
export const FullSessionGuarded: Story = {
  args: { session: sunkenGateSession({ gateGuarded: true }) },
}

/** Three steps answered and a free note between them, the swim roll to make. */
export const MidSession: Story = {
  args: {
    initialActions: [
      ...answers(midSessionInputs.slice(0, 2)),
      { kind: 'note', text: 'A gull screams overhead. Nobody answers it.' },
      ...answers(midSessionInputs.slice(2)),
    ],
  },
}

/** The scene finished: the journal stream with every entry. */
export const Finished: Story = { args: { initialActions: answers(finishedSessionInputs) } }
