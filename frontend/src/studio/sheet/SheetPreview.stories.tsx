import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import { sheet } from '../fixtures'
import { SheetPreview } from './SheetPreview'

const meta = {
  title: 'Studio/SheetPreview',
  component: SheetPreview,
  args: { sheet, layoutDraft: null },
} satisfies Meta<typeof SheetPreview>

export default meta
type Story = StoryObj<typeof meta>

/** Sample values are typed here and never saved; the derived field follows them. */
export const Default: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    const power = await canvas.findByLabelText('Power')
    await userEvent.clear(power)
    await userEvent.type(power, '7')
    await expect(canvas.getByLabelText('Bonus')).toHaveTextContent('8')
  },
}

/** A sheet without fields has nothing to show yet. */
export const NoFields: Story = {
  args: { sheet: { ...sheet, fields: [] } },
}
