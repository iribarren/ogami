import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import { sheet } from '../fixtures'
import { SheetPreview } from './SheetPreview'
import { useSampleValues, useSheetSamples } from './useSheetSamples'
import type { GameSystemSheet } from './useGameSystemSheet'

/** The preview with the sample values the Sheet tab keeps for it. */
function WithSamples({ sheet }: { sheet: GameSystemSheet }) {
  return <SheetPreview sheet={sheet} samples={useSheetSamples(useSampleValues(), sheet, null)} />
}

const meta = {
  title: 'Studio/SheetPreview',
  component: WithSamples,
  args: { sheet },
} satisfies Meta<typeof WithSamples>

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
