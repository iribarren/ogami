import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import { finishedSessionInputs, midSessionInputs, sunkenGateSession } from './sunkenGate'
import { Wizard } from './Wizard'

// Flow presentation prototype (design spike): never imported by the app.
const meta = {
  title: 'Play/Flow prototypes/Wizard',
  component: Wizard,
  args: { session: sunkenGateSession() },
} satisfies Meta<typeof Wizard>

export default meta
type Story = StoryObj<typeof meta>

/** The whole scene from its first step; the oracle answers "No" (the gate is not guarded). */
export const FullSession: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(canvas.getByText('Step 1 of about 5–6')).toBeInTheDocument()
    await userEvent.type(
      canvas.getByRole('textbox', { name: /describe where your character stands/i }),
      'Wet stone.',
    )
    await userEvent.click(canvas.getByRole('button', { name: 'Record' }))
    await expect(canvas.getByRole('heading', { name: 'Ask the oracle' })).toBeInTheDocument()
  },
}

/** The same scene where the oracle answers "Yes": the guard branch. */
export const FullSessionGuarded: Story = {
  args: { session: sunkenGateSession({ gateGuarded: true }) },
}

/** Three steps answered, the swim roll to make. */
export const MidSession: Story = { args: { initialInputs: midSessionInputs } }

/** The scene finished: its journal summary. */
export const Finished: Story = { args: { initialInputs: finishedSessionInputs } }
