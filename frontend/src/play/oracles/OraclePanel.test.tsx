import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'
import { renderWithApi } from '@/test/renderWithApi'

import { OraclePanel } from './OraclePanel'
import type {
  LikelihoodAnswer,
  LikelihoodOracleDefinition,
  OracleTableDefinition,
  OracleTableResult,
} from './useOracles'

const oracle: LikelihoodOracleDefinition = {
  sides: 100,
  levels: [
    { key: 'unlikely', label: 'Unlikely', target: 35 },
    { key: 'even', label: 'Even odds', target: 50 },
    { key: 'likely', label: 'Likely', target: 65 },
  ],
  chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 5 },
  exceptionalPercent: 20,
}

const oracleWithoutChaos: LikelihoodOracleDefinition = {
  sides: 20,
  levels: [{ key: 'even', label: 'Even odds', target: 10 }],
}

const tables: OracleTableDefinition[] = [
  {
    key: 'weather',
    name: 'Weather',
    dice: '1d6',
    entries: [
      { min: 1, max: 5, text: 'Clear skies' },
      { min: 6, max: 6, text: 'A storm', table: 'storm-kind' },
    ],
  },
  {
    key: 'storm-kind',
    name: 'Storm kind',
    entries: [{ text: 'Thunderstorm', weight: 3 }, { text: 'Hail' }],
  },
]

const yesOnLikely: LikelihoodAnswer = {
  answer: 'yes',
  roll: 42,
  sides: 100,
  effectiveTarget: 75,
  likelihood: 'likely',
  likelihoodLabel: 'Likely',
  chaosFactor: 7,
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
      dice: '1d4',
      total: 2,
      text: 'Thunderstorm',
      nestedTableKey: null,
    },
  ],
}

/** Answers with `body`, recording each JSON request body sent. */
function answersWith(body: unknown, sent: unknown[] = []): FakeHandler {
  return async (request) => {
    sent.push(await request.json())
    return Response.json(body)
  }
}

function answer() {
  return screen.getByRole('region', { name: 'Likelihood answer' })
}

function tableResult() {
  return screen.getByRole('region', { name: 'Oracle table result' })
}

describe('OraclePanel', () => {
  describe('likelihood oracle', () => {
    it('asks with the chosen likelihood and chaos factor and shows the answer', async () => {
      const sent: unknown[] = []
      const answered: LikelihoodAnswer[] = []
      renderWithApi(
        <OraclePanel
          likelihoodOracle={oracle}
          tables={tables}
          onAnswered={(result) => answered.push(result)}
        />,
        { 'POST /api/likelihood-answers': answersWith(yesOnLikely, sent) },
      )
      const user = userEvent.setup()

      await user.selectOptions(screen.getByLabelText('Likelihood'), 'Likely')
      await user.clear(screen.getByLabelText('Chaos factor'))
      await user.type(screen.getByLabelText('Chaos factor'), '7')
      await user.click(screen.getByRole('button', { name: 'Ask' }))

      expect(await within(answer()).findByText('Yes')).toBeInTheDocument()
      expect(sent).toEqual([{ oracle, likelihood: 'likely', chaosFactor: 7 }])
      expect(answered).toEqual([yesOnLikely])
      expect(answer()).toHaveAttribute('aria-live', 'polite')
      expect(answer()).toHaveTextContent('42 on d100')
      expect(answer()).toHaveTextContent('Target75')
      expect(answer()).toHaveTextContent('LikelihoodLikely')
      expect(answer()).toHaveTextContent('Chaos factor7')
    })

    it('starts at the middle likelihood and the neutral chaos factor within the chaos range', () => {
      renderWithApi(<OraclePanel likelihoodOracle={oracle} tables={tables} />)

      expect(screen.getByLabelText('Likelihood')).toHaveDisplayValue('Even odds')
      const chaosFactor = screen.getByLabelText('Chaos factor')
      expect(chaosFactor).toHaveValue(5)
      expect(chaosFactor).toHaveAttribute('min', '1')
      expect(chaosFactor).toHaveAttribute('max', '9')
    })

    it('asks without a chaos factor when the oracle has no chaos', async () => {
      const sent: unknown[] = []
      renderWithApi(<OraclePanel likelihoodOracle={oracleWithoutChaos} tables={[]} />, {
        'POST /api/likelihood-answers': answersWith(
          {
            answer: 'no',
            roll: 15,
            sides: 20,
            effectiveTarget: 10,
            likelihood: 'even',
            likelihoodLabel: 'Even odds',
            chaosFactor: null,
          } satisfies LikelihoodAnswer,
          sent,
        ),
      })
      const user = userEvent.setup()

      expect(screen.queryByLabelText('Chaos factor')).not.toBeInTheDocument()
      await user.click(screen.getByRole('button', { name: 'Ask' }))

      expect(await within(answer()).findByText('No')).toBeInTheDocument()
      expect(sent).toEqual([{ oracle: oracleWithoutChaos, likelihood: 'even' }])
      expect(answer()).toHaveTextContent('15 on d20')
      expect(answer()).not.toHaveTextContent('Chaos factor')
    })

    it.each([
      ['exceptional_yes', 'Exceptional yes'],
      ['yes', 'Yes'],
      ['no', 'No'],
      ['exceptional_no', 'Exceptional no'],
    ] as const)('labels the %s answer "%s"', async (value, label) => {
      renderWithApi(<OraclePanel likelihoodOracle={oracle} tables={tables} />, {
        'POST /api/likelihood-answers': answersWith({ ...yesOnLikely, answer: value }),
      })
      const user = userEvent.setup()

      await user.click(screen.getByRole('button', { name: 'Ask' }))

      expect(await within(answer()).findByText(label, { exact: true })).toBeInTheDocument()
    })

    it('shows the API message when the question is invalid', async () => {
      renderWithApi(<OraclePanel likelihoodOracle={oracle} tables={tables} />, {
        'POST /api/likelihood-answers': () =>
          Response.json({ error: 'The chaos factor must be between 1 and 9.' }, { status: 422 }),
      })
      const user = userEvent.setup()

      await user.click(screen.getByRole('button', { name: 'Ask' }))

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'The chaos factor must be between 1 and 9.',
      )
    })

    it('shows a generic message when the API fails unexpectedly', async () => {
      renderWithApi(<OraclePanel likelihoodOracle={oracle} tables={tables} />, {
        'POST /api/likelihood-answers': () => new Response('Oops', { status: 500 }),
      })
      const user = userEvent.setup()

      await user.click(screen.getByRole('button', { name: 'Ask' }))

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Asking the oracle failed with HTTP 500.',
      )
    })
  })

  describe('oracle tables', () => {
    it('rolls on the chosen table and shows every step in order, nested ones marked', async () => {
      const sent: unknown[] = []
      const resolved: OracleTableResult[] = []
      renderWithApi(
        <OraclePanel
          likelihoodOracle={oracle}
          tables={tables}
          onResolved={(result) => resolved.push(result)}
        />,
        { 'POST /api/oracle-table-results': answersWith(stormyWeather, sent) },
      )
      const user = userEvent.setup()

      await user.selectOptions(screen.getByLabelText('Oracle table'), 'Weather')
      await user.click(screen.getByRole('button', { name: 'Roll on table' }))

      const steps = await within(tableResult()).findAllByRole('listitem')
      expect(sent).toEqual([{ tables, table: 'weather' }])
      expect(resolved).toEqual([stormyWeather])
      expect(tableResult()).toHaveAttribute('aria-live', 'polite')
      expect(steps).toHaveLength(2)
      expect(steps[0]).toHaveTextContent(/Weather.*1d6.*6.*A storm/)
      expect(steps[0]).not.toHaveTextContent('Nested')
      expect(steps[1]).toHaveTextContent(/Nested roll.*Storm kind.*1d4.*2.*Thunderstorm/)
    })

    it('shows the API message when the table set is invalid', async () => {
      renderWithApi(<OraclePanel likelihoodOracle={oracle} tables={tables} />, {
        'POST /api/oracle-table-results': () =>
          Response.json({ error: 'No entry covers the roll 7.' }, { status: 422 }),
      })
      const user = userEvent.setup()

      await user.click(screen.getByRole('button', { name: 'Roll on table' }))

      expect(await screen.findByRole('alert')).toHaveTextContent('No entry covers the roll 7.')
    })

    it('hides the tables when there are none', () => {
      renderWithApi(<OraclePanel likelihoodOracle={oracle} tables={[]} />)

      expect(screen.queryByLabelText('Oracle table')).not.toBeInTheDocument()
    })
  })

  it('is on the Play home page', async () => {
    renderAppAt('/play', { 'GET /api/auth/me': signedInAs('SOLO_PLAYER') })

    expect(await screen.findByRole('heading', { level: 2, name: 'Oracles' })).toBeInTheDocument()
    expect(screen.getByLabelText('Likelihood')).toBeInTheDocument()
    expect(screen.getByLabelText('Oracle table')).toBeInTheDocument()
  })
})
