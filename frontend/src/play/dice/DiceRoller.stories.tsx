import type { Meta, StoryObj } from '@storybook/react-vite'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { expect, userEvent, within } from 'storybook/test'

import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient } from '@/test/fakeApi'

import { DiceRoller } from './DiceRoller'
import type { Roll } from './useRollDice'

const fourD6KeepThree: Roll = {
  expression: '4d6kh3+2',
  total: 17,
  groups: [
    {
      notation: '4d6kh3',
      sides: 6,
      dice: [
        { value: 6, kept: true },
        { value: 1, kept: false },
        { value: 5, kept: true },
        { value: 4, kept: true },
      ],
      subtotal: 15,
    },
  ],
}

/** Answers every roll with `fourD6KeepThree`, or with a 422 for expressions containing "x". */
const { api } = createFakeApiClient({
  'POST /api/rolls': async (request) => {
    const { expression } = (await request.json()) as { expression: string }
    if (expression.includes('x')) {
      return Response.json({ error: 'Unexpected character "x" at position 2.' }, { status: 422 })
    }
    return Response.json(fourD6KeepThree)
  },
})

const meta = {
  title: 'Play/DiceRoller',
  component: DiceRoller,
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
} satisfies Meta<typeof DiceRoller>

export default meta
type Story = StoryObj<typeof meta>

export const Empty: Story = {}

export const Rolled: Story = {
  args: { initialExpression: '4d6kh3+2' },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('button', { name: 'Roll' }))
    await expect(await canvas.findByText('17')).toBeInTheDocument()
  },
}

export const InvalidExpression: Story = {
  args: { initialExpression: '2x6' },
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.click(canvas.getByRole('button', { name: 'Roll' }))
    await expect(await canvas.findByRole('alert')).toBeInTheDocument()
  },
}

export const WithoutPresets: Story = { args: { presets: [] } }
