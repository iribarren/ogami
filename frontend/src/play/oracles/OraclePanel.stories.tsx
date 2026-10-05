import type { Meta, StoryObj } from '@storybook/react-vite'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { expect, userEvent, within } from 'storybook/test'

import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient } from '@/test/fakeApi'

import { OraclePanel } from './OraclePanel'
import { sampleLikelihoodOracle, sampleOracleTables } from './sampleOracles'
import type { LikelihoodAnswer, OracleTableResult } from './useOracles'

const exceptionalYes: LikelihoodAnswer = {
  answer: 'exceptional_yes',
  roll: 7,
  sides: 100,
  effectiveTarget: 50,
  likelihood: 'even',
  likelihoodLabel: 'Even odds',
  chaosFactor: 5,
}

const stormyWeather: OracleTableResult = {
  table: 'weather',
  steps: [
    {
      tableKey: 'weather',
      tableName: 'Weather',
      dice: '1d6',
      total: 6,
      text: 'A storm',
      nestedTableKey: 'storm-kind',
    },
    {
      tableKey: 'storm-kind',
      tableName: 'Storm kind',
      dice: '1d6',
      total: 2,
      text: 'Thunderstorm',
      nestedTableKey: null,
    },
  ],
}

/**
 * Answers every question with `exceptionalYes` and every table with `stormyWeather`,
 * or with a 422 when the chaos factor is 9 or the table is "npc-mood".
 */
const { api } = createFakeApiClient({
  'POST /api/likelihood-answers': async (request) => {
    const { chaosFactor } = (await request.json()) as { chaosFactor?: number }
    if (chaosFactor === 9) {
      return Response.json({ error: 'The chaos factor must be between 1 and 8.' }, { status: 422 })
    }
    return Response.json(exceptionalYes)
  },
  'POST /api/oracle-table-results': async (request) => {
    const { table } = (await request.json()) as { table: string }
    if (table === 'npc-mood') {
      return Response.json({ error: 'Unknown oracle table "npc-mood".' }, { status: 422 })
    }
    return Response.json(stormyWeather)
  },
})

const meta = {
  title: 'Play/OraclePanel',
  component: OraclePanel,
  args: { likelihoodOracle: sampleLikelihoodOracle, tables: sampleOracleTables },
  decorators: [
    (Story) => (
      <QueryClientProvider client={new QueryClient()}>
        <ApiClientProvider client={api}>
          <div className="max-w-md">
            <Story />
          </div>
        </ApiClientProvider>
      </QueryClientProvider>
    ),
  ],
} satisfies Meta<typeof OraclePanel>

export default meta
type Story = StoryObj<typeof meta>

export const Empty: Story = {}

export const Answered: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('button', { name: 'Ask' }))
    await expect(await canvas.findByText('Exceptional yes')).toBeInTheDocument()
  },
}

export const TableRolled: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('button', { name: 'Roll on table' }))
    await expect(await canvas.findByText('Thunderstorm')).toBeInTheDocument()
  },
}

export const InvalidChaosFactor: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.clear(canvas.getByLabelText('Chaos factor'))
    await userEvent.type(canvas.getByLabelText('Chaos factor'), '9')
    await userEvent.click(canvas.getByRole('button', { name: 'Ask' }))
    await expect(await canvas.findByRole('alert')).toBeInTheDocument()
  },
}

export const WithoutChaosOrTables: Story = {
  args: {
    likelihoodOracle: { sides: 6, levels: [{ key: 'even', label: 'Even odds', target: 3 }] },
    tables: [],
  },
}
