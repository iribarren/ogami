import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { sheet, sheetWithEconomy } from '../fixtures'
import { GearTab } from './GearTab'

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/GearTab',
  component: GearTab,
  args: { gameSystemKey: 'my-draft' },
  parameters: { api: { [`GET ${base}/sheet`]: () => Response.json(sheetWithEconomy) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof GearTab>

export default meta
type Story = StoryObj<typeof meta>

/** The catalog: three entries, costs in the currency unit, one without a category. */
export const Catalog: Story = {
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByText('Used pistol')).toBeVisible()
    await expect(within(canvasElement).getByText('Medkit')).toBeVisible()
  },
}

/** A draft from before gear: an empty catalog, and a hint that it has no currency either. */
export const Empty: Story = {
  parameters: { api: { [`GET ${base}/sheet`]: () => Response.json(sheet) } },
  play: async ({ canvasElement }) => {
    await expect(
      await within(canvasElement).findByText('This draft has no gear yet.'),
    ).toBeVisible()
  },
}

/** The dialog that adds an entry. */
export const AddGear: Story = {
  play: async ({ canvasElement }) => {
    await userEvent.click(await within(canvasElement).findByRole('button', { name: 'Add gear' }))
    const dialog = within(await within(document.body).findByRole('dialog'))
    await expect(dialog.getByLabelText('Cost')).toBeVisible()
  },
}

/** A shop offers the entry: the API refuses the deletion and names where. */
export const DeleteRefused: Story = {
  parameters: {
    api: {
      [`GET ${base}/sheet`]: () => Response.json(sheetWithEconomy),
      [`DELETE ${base}/gear/rope`]: () =>
        Response.json(
          { error: 'The gear "rope" is offered at flows[0].phases[0].setup[1].offer.' },
          { status: 422 },
        ),
    },
  },
  play: async ({ canvasElement }) => {
    await userEvent.click(await within(canvasElement).findByRole('button', { name: 'Delete rope' }))
    const dialog = within(await within(document.body).findByRole('dialog'))
    await userEvent.click(dialog.getByRole('button', { name: 'Delete the gear' }))
    await expect(await dialog.findByRole('alert')).toHaveTextContent('is offered at flows[0]')
  },
}
