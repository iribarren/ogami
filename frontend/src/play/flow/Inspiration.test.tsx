import { QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { createQueryClient } from '@/app/queryClient'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import { recordedEntry, stormyWeather } from '../journal/fixtures'
import {
  atStep,
  atTheScenePick,
  describing,
  guided,
  inspirationStep,
  phaseDescription,
  promptStep,
} from './fixtures'
import { StepCard } from './StepCard'
import type { FlowRun } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`

/** The Play screen along the Flow, with the journal's table roll answered by `roll`. */
function renderGuided(flowRun: FlowRun, roll: FakeHandler = () => Response.json(stormyWeather)) {
  const rolled: string[] = []
  const handlers: Record<string, FakeHandler> = {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(guided(flowRun)),
    [`GET ${path}/journal`]: () => Response.json([]),
  }
  for (const key of ['weather', 'action']) {
    handlers[`POST ${path}/journal/oracle-tables/${key}`] = (request) => {
      rolled.push(key)
      return roll(request)
    }
  }
  return { ...renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, handlers), rolled }
}

async function card() {
  return within(await screen.findByRole('region', { name: 'Current step: What do you notice?' }))
}

describe('inspiration tables of a prompt step', () => {
  it('offers one roll button per table of the campaign and skips a key it does not know', async () => {
    renderGuided(atStep(inspirationStep))

    const step = await card()
    const group = within(step.getByRole('group', { name: 'Inspiration' }))
    expect(group.getAllByRole('button').map((button) => button.textContent)).toEqual([
      'Weather',
      'Action',
    ])
    expect(group.getByRole('button', { name: 'Roll on Weather' })).toBeInTheDocument()
    // The player still writes the answer.
    expect(step.getByRole('textbox')).toBeInTheDocument()
  })

  it('shows the result of a roll in the card under the buttons and rolls through the journal', async () => {
    const user = userEvent.setup()
    const { rolled } = renderGuided(atStep(inspirationStep))

    await user.click(await (await card()).findByRole('button', { name: 'Roll on Weather' }))

    const results = within(await screen.findByRole('list', { name: 'Inspiration results' }))
    // The nested table's text follows the first.
    expect(results.getByText('Weather:')).toBeInTheDocument()
    expect(results.getByText('A storm › Hail')).toBeInTheDocument()
    expect(rolled).toEqual(['weather'])
  })

  it('keeps the latest roll of each table', async () => {
    const user = userEvent.setup()
    const answers = ['A storm', 'Clear skies']
    const entry = (text: string) =>
      recordedEntry({
        kind: 'oracle-table',
        oracleKey: 'weather',
        oracleName: 'Weather',
        steps: [
          {
            tableKey: 'weather',
            tableName: 'Weather',
            dice: '1d6',
            total: 3,
            text,
            nestedTableKey: null,
            sceneType: null,
          },
        ],
      })
    renderGuided(atStep(inspirationStep), () => Response.json(entry(answers.shift() ?? '')))

    const step = await card()
    await user.click(step.getByRole('button', { name: 'Roll on Weather' }))
    await step.findByText('A storm')
    await user.click(step.getByRole('button', { name: 'Roll on Weather' }))

    expect(await step.findByText('Clear skies')).toBeInTheDocument()
    expect(step.queryByText('A storm')).not.toBeInTheDocument()
  })

  it('shows why a roll failed', async () => {
    const user = userEvent.setup()
    renderGuided(atStep(inspirationStep), () =>
      Response.json({ error: 'The table is empty.' }, { status: 422 }),
    )

    await user.click(await (await card()).findByRole('button', { name: 'Roll on Weather' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The table is empty.')
  })

  it('shows nothing for a prompt without inspiration tables', async () => {
    renderGuided(atStep(promptStep))

    const step = within(await screen.findByRole('region', { name: 'Current step: Who walks in?' }))
    expect(step.queryByRole('group', { name: 'Inspiration' })).not.toBeInTheDocument()
  })
})

describe('the card reused between two prompt steps', () => {
  afterEach(cleanup)

  it('shows no result of the previous step for a table both offer', async () => {
    const user = userEvent.setup()
    const { api } = createFakeApiClient({
      [`POST ${path}/journal/oracle-tables/weather`]: () => Response.json(stormyWeather),
    })
    const next = { ...inspirationStep, key: 'notice-again', title: 'What else do you notice?' }
    const card = (step: typeof inspirationStep) => (
      <QueryClientProvider client={createQueryClient()}>
        <ApiClientProvider client={api}>
          <StepCard campaign={lostMineCampaign} step={step} pending={false} onCommand={vi.fn()} />
        </ApiClientProvider>
      </QueryClientProvider>
    )
    const { rerender } = render(card(inspirationStep))

    await user.click(screen.getByRole('button', { name: 'Roll on Weather' }))
    expect(await screen.findByText('A storm › Hail')).toBeInTheDocument()

    rerender(card(next))

    expect(
      screen.getByRole('region', { name: 'Current step: What else do you notice?' }),
    ).toBeInTheDocument()
    expect(screen.queryByText('A storm › Hail')).not.toBeInTheDocument()
  })
})

describe('the description of a phase', () => {
  afterEach(cleanup)

  it('shows under the progress in the guided panel', async () => {
    renderGuided(describing(atStep(promptStep)))

    const guide = within(await screen.findByRole('region', { name: 'Guided play' }))
    expect(guide.getByLabelText('Phase description')).toHaveTextContent(phaseDescription)
  })

  it('shows nothing when the phase has none', async () => {
    renderGuided(atStep(promptStep))

    await screen.findByRole('region', { name: 'Guided play' })
    expect(screen.queryByLabelText('Phase description')).not.toBeInTheDocument()
  })

  it('shows in the end-phase confirmation, and not when absent', async () => {
    const user = userEvent.setup()
    renderGuided({ ...describing(atTheScenePick()), canMoveOn: true })

    await user.click(await screen.findByRole('button', { name: 'End the start phase' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'End the start phase?' }))
    expect(dialog.getByText(phaseDescription)).toBeInTheDocument()
  })

  it('leaves the end-phase confirmation as it was without a description', async () => {
    const user = userEvent.setup()
    renderGuided({ ...atTheScenePick(), canMoveOn: true })

    await user.click(await screen.findByRole('button', { name: 'End the start phase' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'End the start phase?' }))
    expect(dialog.queryByText(phaseDescription)).not.toBeInTheDocument()
    expect(dialog.queryByLabelText('Phase description')).not.toBeInTheDocument()
  })
})
