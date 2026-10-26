import type { Meta, StoryObj } from '@storybook/react-vite'
import { useState } from 'react'
import { expect, userEvent, within } from 'storybook/test'

import { EffectsEditor } from './EffectsEditor'
import { effectsToDrafts } from './effectDraft'
import { effectPickers, every } from './effectFixtures'
import { mapStepError } from './stepError'

/** The editor holding its own drafts, as a step card will. */
function Editable({
  stored,
  allowSheet,
  errors = [],
}: {
  stored: unknown[]
  allowSheet: boolean
  errors?: string[]
}) {
  const [effects, setEffects] = useState(() => effectsToDrafts(stored))
  // One answer from the server, as in the step list stories.
  const [mapped] = useState(() => errors.map((text) => mapStepError('steps', text)))
  return (
    <EffectsEditor
      label="Effects"
      effects={effects}
      context={effectPickers}
      allowSheet={allowSheet}
      errors={mapped}
      onChange={setEffects}
    />
  )
}

const meta = {
  title: 'Studio/EffectsEditor',
  component: Editable,
  args: { stored: every.slice(0, 10), allowSheet: true },
} satisfies Meta<typeof Editable>

export default meta
type Story = StoryObj<typeof meta>

/** Ten effects: tracker (three value forms), Scene Type, title, NPC, thread, fact and sheet kinds. The list is full. */
export const EveryKind: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(canvas.getByRole('button', { name: 'Add effect' })).toBeDisabled()
    await userEvent.click(canvas.getByRole('button', { name: 'Delete effect 1' }))
    await expect(canvas.getByRole('button', { name: 'Add effect' })).toBeEnabled()
  },
}

/** The sheet kind is offered where a Character is known (a character or prompt step, a choice option but its skip, a check outcome), with a dice value among its forms. */
export const SheetAllowed: Story = {
  args: { stored: every.slice(11), allowSheet: true },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(canvas.getByRole('option', { name: 'sheet' })).toBeInTheDocument()
  },
}

/** Elsewhere (a Scene Type setup, the skip option of a choice, a roll) the kind is not offered, and a sheet effect already stored there stays, flagged. */
export const SheetNotAllowed: Story = {
  args: { stored: every.slice(11, 12), allowSheet: false },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(canvas.queryByRole('option', { name: 'sheet' })).not.toBeInTheDocument()
    await expect(canvas.getByText(/only allowed in a character step/)).toBeInTheDocument()
  },
}

/** An effect of a kind this editor does not know stays as it is; it can be moved and deleted. */
export const UnknownKindIsReadOnly: Story = {
  args: { stored: [{ kind: 'teleport', to: 'moon' }, every[5]], allowSheet: false },
}

/** The publisher refused the draft: each message sits on its effect and field. */
export const ServerError: Story = {
  args: {
    stored: every.slice(0, 2),
    allowSheet: false,
    errors: [
      'steps[0].effects[1].value: must be an integer',
      'steps[0].effects[0].tracker: unknown tracker "gone"',
      'steps[0].effects: at most 10 effects',
    ],
  },
}
