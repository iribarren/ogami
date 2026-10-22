import type { Meta, StoryObj } from '@storybook/react-vite'
import { useState } from 'react'
import { expect, userEvent, within } from 'storybook/test'

import { formulaDiagnostics } from './formulaDiagnostics'
import { FormulaInput } from './FormulaInput'
import { sheet } from '../fixtures'
import type { SheetFieldView } from '@/shared/sheet/types'
import { chooseOption } from '@/test/chooseOption'

const view = (key: string, label: string, type: SheetFieldView['type']): SheetFieldView => ({
  key,
  label,
  hint: null,
  group: null,
  type,
  min: null,
  max: null,
  default: null,
  multiline: null,
  maxLength: null,
  itemLabel: null,
  maxItems: null,
  formula: null,
  span: 1,
})

const fields = [view('pow', 'Power', 'number'), view('hp', 'Hit points', 'track')]
const references = [
  { reference: 'pow', label: 'Power' },
  { reference: 'hp', label: 'Hit points' },
  { reference: 'hp.max', label: 'Hit points max' },
  { reference: 'tracker:heat', label: 'Tracker Heat' },
]

/** The input over a small sheet, diagnosing what is typed as the Studio does. */
function Typed({ initial }: { initial: string }) {
  const [value, setValue] = useState(initial)
  const diagnostics = formulaDiagnostics({
    key: 'bonus',
    formula: value,
    fields,
    drafts: { pow: '7', hp: { current: '5', max: '12' } },
    trackerKeys: sheet.trackers.map((tracker) => tracker.key),
    trackers: [{ key: 'heat', value: 3 }],
  })
  return (
    <FormulaInput
      label="Formula"
      value={value}
      onChange={setValue}
      diagnostics={diagnostics}
      references={references}
    />
  )
}

const meta = {
  title: 'Studio/FormulaInput',
  component: Typed,
  args: { initial: '{pow} + 1' },
} satisfies Meta<typeof Typed>

export default meta
type Story = StoryObj<typeof meta>

/** A valid formula shows its value over the sample values; the menu inserts a reference. */
export const Valid: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(canvas.getByText('Value with the sample values: 8')).toBeVisible()
    await chooseOption(userEvent.setup(), 'Insert reference', 'tracker:heat', canvas)
    await expect(canvas.getByLabelText('Formula')).toHaveValue('{pow} + 1{tracker:heat}')
  },
}

/** A syntax error is marked at its position in the text. */
export const SyntaxError: Story = {
  args: { initial: '  1 + * 2' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('*', { selector: 'mark' })).toBeVisible()
  },
}

/** A reference the field may not read is refused, as the publisher does. */
export const UnknownReference: Story = {
  args: { initial: '{pow} + {ghost}' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('{ghost}: unknown field "ghost"')).toBeVisible()
  },
}
