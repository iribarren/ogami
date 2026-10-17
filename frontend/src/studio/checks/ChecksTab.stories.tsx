import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { checks } from '../fixtures'
import { ChecksTab } from './ChecksTab'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const base = '/api/studio/game-systems/my-draft'

/** A check with a bound of every kind: a number, a tracker, a formula, and the last band's none. */
const everyBound = {
  key: 'risk',
  label: 'Risk',
  dice: '2d6',
  modifier: '{pow} + {hp.max}',
  difficulty: { label: 'Difficulty', default: 7, min: 2, max: 12 },
  bands: [
    { key: 'disaster', label: 'Disaster', upTo: 4 },
    { key: 'trouble', label: 'Trouble', upTo: { tracker: 'heat' } },
    { key: 'cost', label: 'Success at a cost', upTo: { formula: '{check:difficulty} + 2' } },
    { key: 'clean', label: 'Clean success' },
  ],
}

const meta = {
  title: 'Studio/ChecksTab',
  component: ChecksTab,
  args: { gameSystemKey: 'my-draft' },
  parameters: { api: { [`GET ${base}/checks`]: json(checks) } },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof ChecksTab>

export default meta
type Story = StoryObj<typeof meta>

/** The checks in order, each with its dice; the first cannot move up. */
export const Default: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByRole('button', { name: 'Move up skill' })).toBeDisabled()
  },
}

export const Empty: Story = {
  parameters: { api: { [`GET ${base}/checks`]: json({ ...checks, checks: [] }) } },
}

/** The bands editor over a bound of every kind; the last band catches the rest. */
export const BandsOfEveryKind: Story = {
  parameters: {
    api: { [`GET ${base}/checks`]: json({ ...checks, checks: [everyBound] }) },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit risk' }))
    await expect(canvas.getByLabelText('Band 1 number')).toHaveValue('4')
    await expect(canvas.getByLabelText('Band 2 tracker')).toHaveValue('heat')
    await expect(canvas.getByLabelText('Band 3 formula')).toHaveValue('{check:difficulty} + 2')
    await expect(canvas.queryByLabelText('Band 4 bound')).toBeNull()
  },
}

/** A band formula that reads a field the sheet does not have: advice, saving stays possible. */
export const FormulaError: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit skill' }))
    const formula = canvas.getByLabelText('Band 1 formula')
    await userEvent.clear(formula)
    await userEvent.type(formula, '{{ghost} + 1')
    await expect(await canvas.findByText(/unknown field "ghost"/)).toBeVisible()
    await expect(canvas.getByRole('button', { name: 'Save check' })).toBeEnabled()
  },
}
