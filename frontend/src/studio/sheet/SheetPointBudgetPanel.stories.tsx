import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { sheet, sheetWithPointBudget } from '../fixtures'
import { SheetPointBudgetPanel } from './SheetPointBudgetPanel'

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/SheetPointBudgetPanel',
  component: SheetPointBudgetPanel,
  args: { gameSystemKey: 'my-draft', sheet },
  parameters: { api: { [`GET ${base}/sheet`]: () => Response.json(sheet) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof SheetPointBudgetPanel>

export default meta
type Story = StoryObj<typeof meta>

/** No budget: Characters are created without one until it is added. */
export const WithoutBudget: Story = {}

/** A budget: its label, total, fields and the range a field takes while a Character is created. */
export const WithBudget: Story = {
  args: { sheet: sheetWithPointBudget },
}

/** Only the sheet's number fields can be spent on. */
export const AddBudget: Story = {
  play: async ({ canvasElement }) => {
    await userEvent.click(
      await within(canvasElement).findByRole('button', { name: 'Add point budget' }),
    )
    const dialog = within(await within(document.body).findByRole('dialog'))
    await expect(dialog.getAllByRole('checkbox')).toHaveLength(2)
  },
}
