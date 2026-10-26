import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { checks, sheet } from '../fixtures'
import { useSampleValues } from '../sheet/useSheetSamples'
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

/** The tab with the sample values the GameSystem page keeps for it. */
function WithSamples({ gameSystemKey }: { gameSystemKey: string }) {
  return <ChecksTab gameSystemKey={gameSystemKey} sampleValues={useSampleValues()} />
}

/** A test roll of `skill` as the API answers it. */
const rolled = {
  pool: null,
  push: null,
  rolledSuccesses: null,
  dice: {
    expression: '1d10',
    total: 6,
    groups: [{ notation: '1d10', sides: 10, dice: [{ value: 6, kept: true }], subtotal: 6 }],
  },
  modifier: 7,
  situational: 0,
  difficulty: 13,
  total: 13,
  bands: [
    { key: 'fail', label: 'Fail', bound: 12 },
    { key: 'win', label: 'Win', bound: null },
  ],
  band: 'win',
}

const meta = {
  title: 'Studio/ChecksTab',
  component: WithSamples,
  args: { gameSystemKey: 'my-draft' },
  parameters: {
    api: { [`GET ${base}/checks`]: json(checks), [`GET ${base}/sheet`]: json(sheet) },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof WithSamples>

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
  parameters: {
    api: {
      [`GET ${base}/checks`]: json({ ...checks, checks: [] }),
      [`GET ${base}/sheet`]: json(sheet),
    },
  },
}

/** The bands editor over a bound of every kind; the last band catches the rest. */
export const BandsOfEveryKind: Story = {
  parameters: {
    api: {
      [`GET ${base}/checks`]: json({ ...checks, checks: [everyBound] }),
      [`GET ${base}/sheet`]: json(sheet),
    },
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

/** The sandbox beside a saved check: bounds follow the samples as they are typed. */
export const Sandbox: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit skill' }))
    const sandbox = within(await canvas.findByRole('region', { name: 'Test roll sandbox' }))
    const bounds = within(sandbox.getByRole('list', { name: 'Band bounds' }))
    await expect(bounds.getAllByRole('listitem')[0]).toHaveTextContent('Failup to 12')
    const difficulty = sandbox.getByLabelText('Test difficulty')
    await userEvent.clear(difficulty)
    await userEvent.type(difficulty, '20')
    await expect(bounds.getAllByRole('listitem')[0]).toHaveTextContent('Failup to 19')
  },
}

/** Bounds that do not increase for the samples are flagged; a sample that is not a number is refused. */
export const SandboxWarning: Story = {
  parameters: {
    api: {
      [`GET ${base}/checks`]: json({
        ...checks,
        checks: [
          {
            key: 'tight',
            label: 'Tight',
            dice: '1d6',
            bands: [
              { key: 'low', label: 'Low', upTo: { tracker: 'heat' } },
              { key: 'mid', label: 'Mid', upTo: 3 },
              { key: 'high', label: 'High' },
            ],
          },
        ],
      }),
      [`GET ${base}/sheet`]: json(sheet),
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit tight' }))
    const sandbox = within(await canvas.findByRole('region', { name: 'Test roll sandbox' }))
    await userEvent.type(sandbox.getByLabelText('Heat (Tracker)'), '5')
    await expect(await sandbox.findByRole('status')).toHaveTextContent('do not increase')
    await userEvent.type(sandbox.getByLabelText('Power'), 'x')
    await expect(sandbox.getByText('Must be a whole number.')).toBeVisible()
    await expect(sandbox.getByRole('button', { name: 'Roll the check' })).toBeDisabled()
  },
}

/** A roll's result: the dice, the modifiers, the total and the band reached marked. */
export const SandboxResult: Story = {
  parameters: {
    api: {
      [`GET ${base}/checks`]: json(checks),
      [`GET ${base}/sheet`]: json(sheet),
      [`POST ${base}/checks/skill/test-rolls`]: json(rolled),
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit skill' }))
    const sandbox = within(await canvas.findByRole('region', { name: 'Test roll sandbox' }))
    await userEvent.click(sandbox.getByRole('button', { name: 'Roll the check' }))
    await expect(await sandbox.findByText('Total 13')).toBeVisible()
    await expect(sandbox.getByText('Reached')).toBeVisible()
  },
}

/** A pool check with a push, and the roll it gives: dice told by text, a Push button for the failed ones. */
const pool = {
  key: 'sneak',
  label: 'Sneak',
  pool: { count: { formula: '{pow} + 1' }, sides: 6, successAt: 5 },
  push: { label: 'Take a Condition' },
  bands: [
    { key: 'fail', label: 'Fail', upTo: 0 },
    { key: 'win', label: 'Win' },
  ],
}

const poolBands = [
  { key: 'fail', label: 'Fail', bound: 0 },
  { key: 'win', label: 'Win', bound: null },
]

const poolRoll = {
  dice: null,
  pool: {
    size: 3,
    sides: 6,
    successAt: 5,
    count: 3,
    countFormula: '{pow} + 1',
    dice: [
      { face: 2, success: false, rerolled: false },
      { face: 6, success: true, rerolled: false },
      { face: 1, success: false, rerolled: false },
    ],
    successes: 1,
  },
  push: { label: 'Take a Condition' },
  rolledSuccesses: null,
  modifier: 0,
  situational: 0,
  difficulty: null,
  total: 1,
  bands: poolBands,
  band: 'win',
}

/** The pool editor of a stored pool check, and its sandbox rolling and pushing it. */
export const PoolCheck: Story = {
  parameters: {
    api: {
      [`GET ${base}/checks`]: json({ ...checks, checks: [pool] }),
      [`GET ${base}/sheet`]: json(sheet),
      [`POST ${base}/checks/sneak/test-rolls`]: json(poolRoll),
      [`POST ${base}/checks/sneak/test-rolls/push`]: json({
        ...poolRoll,
        pool: {
          ...poolRoll.pool,
          dice: [
            { face: 5, success: true, rerolled: true },
            { face: 6, success: true, rerolled: false },
            { face: 3, success: false, rerolled: true },
          ],
          successes: 2,
        },
        rolledSuccesses: 1,
        total: 2,
      }),
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit sneak' }))
    await expect(canvas.getByLabelText('Dice pool')).toBeChecked()
    await expect(canvas.getByLabelText('Pool count')).toHaveValue('{pow} + 1')
    const sandbox = within(await canvas.findByRole('region', { name: 'Test roll sandbox' }))
    await userEvent.click(sandbox.getByRole('button', { name: 'Roll the check' }))
    await expect(await sandbox.findByText('1 success')).toBeVisible()
    await userEvent.click(sandbox.getByRole('button', { name: 'Push the roll' }))
    await expect(await sandbox.findByText('Rolled Win, still Win')).toBeVisible()
    await expect(sandbox.getByText('The cost is not applied in a test roll.')).toBeVisible()
  },
}

/** The push cost of a pool check: its label and effects, marking a conditions field of the sheet. */
export const PushCost: Story = {
  parameters: {
    api: {
      [`GET ${base}/checks`]: json({
        ...checks,
        checks: [
          {
            ...pool,
            push: {
              label: 'Take a Condition',
              effects: [
                { kind: 'sheet', field: 'physical', op: 'mark' },
                { kind: 'tracker', tracker: 'heat', op: 'add', value: 1 },
              ],
            },
          },
        ],
        conditions: [
          {
            key: 'physical',
            label: 'Physical',
            options: [
              { key: 'hurt', label: 'Hurt' },
              { key: 'exhausted', label: 'Exhausted' },
            ],
          },
        ],
      }),
      [`GET ${base}/sheet`]: json(sheet),
    },
  },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(await canvas.findByRole('button', { name: 'Edit sneak' }))
    await expect(canvas.getByLabelText('Can be pushed')).toBeChecked()
    await expect(canvas.getByLabelText('Cost label')).toHaveValue('Take a Condition')
    const mark = within(canvas.getByRole('group', { name: 'Effect 1' }))
    await expect(mark.getByRole('combobox', { name: 'Option' })).toHaveTextContent(
      'The player picks',
    )
    await expect(
      within(canvas.getByRole('group', { name: 'Effect 2' })).getByText('tracker'),
    ).toBeVisible()
  },
}
