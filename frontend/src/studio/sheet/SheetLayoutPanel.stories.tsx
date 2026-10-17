import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { sheet, sheetUngrouped } from '../fixtures'
import { SheetLayoutPanel } from './SheetLayoutPanel'

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/SheetLayoutPanel',
  component: SheetLayoutPanel,
  args: { gameSystemKey: 'my-draft', sheet },
  parameters: { api: { [`GET ${base}/sheet`]: () => Response.json(sheet) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof SheetLayoutPanel>

export default meta
type Story = StoryObj<typeof meta>

/** A declared layout: its columns and groups, to edit or remove. */
export const WithLayout: Story = {}

/** A legacy sheet: fields render in one column until the layout is declared from their groups. */
export const WithoutLayout: Story = {
  args: { sheet: sheetUngrouped },
}

/** Groups that hold fields cannot be deleted; an empty one can. */
export const EditLayout: Story = {
  play: async ({ canvasElement }) => {
    await userEvent.click(await within(canvasElement).findByRole('button', { name: 'Edit layout' }))
    const dialog = within(await within(document.body).findByRole('dialog', { name: 'Edit layout' }))
    await expect(
      within(dialog.getByRole('group', { name: 'Group 1' })).getByRole('button', {
        name: 'Delete group',
      }),
    ).toBeDisabled()
    await expect(
      within(dialog.getByRole('group', { name: 'Group 3' })).getByRole('button', {
        name: 'Delete group',
      }),
    ).toBeEnabled()
  },
}
