import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { sheet, sheetWithPointBudgets } from '../fixtures'
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

/** Two budgets, each with its label, key, total, fields and the range a field takes at creation. */
export const WithTwoBudgets: Story = {
  args: { sheet: sheetWithPointBudgets },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('listitem', { name: 'Attribute points' })).toBeVisible()
    await expect(canvas.getByRole('listitem', { name: 'Skill points' })).toBeVisible()
  },
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
