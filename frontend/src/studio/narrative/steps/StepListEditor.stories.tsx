import type { Meta, StoryObj } from '@storybook/react-vite'
import { useState } from 'react'
import { expect, userEvent, within } from 'storybook/test'

import {
  stepList,
  stepListBackward,
  stepListBranching,
  stepListCheckSet,
  stepListPickCheck,
  stepListSheetEffects,
  stepListSpecials,
  stepListTableBySceneType,
  stepListWithOtherKinds,
  stepPickers,
  stepPickersHunger,
} from '../../fixtures'
import { StepListEditor } from './StepListEditor'
import type { StepContext } from './stepContext'
import { stepsToDrafts } from './stepDraft'
import { mapStepError } from './stepError'
import { chooseOption } from '@/test/chooseOption'

/** The editor holding its own drafts, as a Scene Type or flow editor will. */
function Editable({
  stored,
  errors = [],
  context = stepPickers,
}: {
  stored: unknown[]
  errors?: string[]
  context?: StepContext
}) {
  const [steps, setSteps] = useState(() => stepsToDrafts(stored))
  // One answer from the server: the editor follows its positions through moves and deletes.
  const [mapped] = useState(() => errors.map((text) => mapStepError('sceneTypes[0].play', text)))
  return <StepListEditor steps={steps} context={context} errors={mapped} onChange={setSteps} />
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

/** A choice option and a prompt change the sheet; the option the skip names does not offer the effect. */
export const SheetEffectsOnChoicesAndPrompts: Story = {
  args: { stored: stepListSheetEffects },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    const kinds = (name: string) =>
      within(canvas.getByRole('group', { name })).getByLabelText('New effect kind')
    await userEvent.click(kinds('Option 2 outcome effects'))
    await expect(canvas.queryByRole('option', { name: 'sheet' })).not.toBeInTheDocument()
    await userEvent.keyboard('{Escape}')
    await expect(canvas.getByText(/only allowed in a character step/)).toBeInTheDocument()
  },
}

/** A check step offering two checks with the same bands; the player picks one when rolling. */
export const CheckSet: Story = {
  args: { stored: stepListCheckSet },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(canvas.getByRole('checkbox', { name: 'Finesse check' })).toBeChecked()
    await expect(canvas.queryByRole('checkbox', { name: 'Luck' })).not.toBeInTheDocument()
  },
}

/** A table step with its own table for two Scene Types; it takes no new branch. */
export const TableBySceneType: Story = {
  args: { stored: stepListTableBySceneType },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(canvas.getByRole('button', { name: 'Add branch' })).toBeDisabled()
  },
}

/** A check step on a Hunger check: the special results its pool can produce get an outcome that replaces the band's. */
export const SpecialResults: Story = {
  args: { stored: stepListSpecials, context: stepPickersHunger as StepContext },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('button', { name: 'Add outcome for Critical' }))
    await expect(canvas.getByRole('group', { name: 'Outcome Critical' })).toBeVisible()
    await expect(canvas.getByRole('group', { name: 'Outcome Bestial failure' })).toBeVisible()
  },
}
