import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { sheet, sheetWithEconomy } from '../fixtures'
import { SheetCurrencyPanel } from './SheetCurrencyPanel'

const base = '/api/studio/game-systems/my-draft'

const meta = {
  title: 'Studio/SheetCurrencyPanel',
  component: SheetCurrencyPanel,
  args: { gameSystemKey: 'my-draft', sheet },
  parameters: { api: { [`GET ${base}/sheet`]: () => Response.json(sheet) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof SheetCurrencyPanel>

export default meta
type Story = StoryObj<typeof meta>

/** No currency: Characters have no money until a number field is picked. */
export const WithoutCurrency: Story = {}

/** `eddies` is the currency, shown in `eb`. */
export const WithCurrency: Story = {
  args: { sheet: sheetWithEconomy },
  play: async ({ canvasElement }) => {
    await expect(
      await within(canvasElement).findByText('Characters spend eddies in eb.'),
    ).toBeVisible()
  },
}
