import type { Meta, StoryObj } from '@storybook/react-vite'
import { useState } from 'react'
import { expect, userEvent, within } from 'storybook/test'

import {
  stepList,
  stepListBackward,
  stepListBranching,
  stepListPickCheck,
  stepListWithOtherKinds,
  stepPickers,
} from '../../fixtures'
import { StepListEditor } from './StepListEditor'
import { stepsToDrafts } from './stepDraft'
import { mapStepError } from './stepError'
import { chooseOption } from '@/test/chooseOption'

/** The editor holding its own drafts, as a Scene Type or flow editor will. */
function Editable({ stored, errors = [] }: { stored: unknown[]; errors?: string[] }) {
  const [steps, setSteps] = useState(() => stepsToDrafts(stored))
  // One answer from the server: the editor follows its positions through moves and deletes.
  const [mapped] = useState(() => errors.map((text) => mapStepError('sceneTypes[0].play', text)))
  return <StepListEditor steps={steps} context={stepPickers} errors={mapped} onChange={setSteps} />
}

const meta = {
  title: 'Studio/StepListEditor',
  component: Editable,
  args: { stored: stepList },
} satisfies Meta<typeof Editable>

export default meta
type Story = StoryObj<typeof meta>

/** A prompt, a roll with bands, a condition on a count and a character step. */
export const EveryEditableKind: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('button', { name: 'Move step 4 up' }))
    await expect(canvas.getByRole('group', { name: 'Step 3' })).toHaveTextContent('who')
  },
}

/** A `pick` step with a mode it does not know and a step with an unreadable shape stay as they are; they can be moved and deleted. */
export const OtherKindsAreReadOnly: Story = { args: { stored: stepListWithOtherKinds } }

/** An oracle with answers, a table with entry branches and a choice with options and a skip. */
export const BranchingKinds: Story = {
  args: { stored: stepListBranching },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('button', { name: 'Add option' }))
    await expect(canvas.getAllByLabelText('Option key')).toHaveLength(3)
  },
}

/** A pick of NPCs with a count and found / none outcomes, and a check with a difficulty and an outcome per band (sheet effects allowed). */
export const PickAndCheck: Story = {
  args: { stored: stepListPickCheck },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await chooseOption(userEvent.setup(), 'Check', 'luck', canvas)
    await expect(canvas.getByText('This check declares no difficulty.')).toBeInTheDocument()
  },
}

/** A step and a band whose next goes to the same or an earlier step. */
export const BackwardNext: Story = { args: { stored: stepListBackward } }

/** The publisher refused the draft: each message sits on its card and field. */
export const ServerError: Story = {
  args: {
    errors: [
      'sceneTypes[0].play[0].title: must not be blank',
      'sceneTypes[0].play[1].bands[0].upTo: must be an integer',
      'sceneTypes[0].play: at most 50 steps',
    ],
  },
}
