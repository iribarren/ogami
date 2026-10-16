import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useEffect, useState } from 'react'

import {
  stepList,
  stepListBackward,
  stepListBranching,
  stepListWithOtherKinds,
  stepPickers,
} from '../../fixtures'
import { StepListEditor } from './StepListEditor'
import { stepsToDrafts, stepsToItems } from './stepDraft'
import { clientStepErrors, mapStepError, type StepError } from './stepError'

/** The editor as a screen holds it: the drafts in state, the written steps exposed for checks. */
const latest: { written: unknown } = { written: null }

function Harness({ stored, errors }: { stored: unknown[]; errors?: StepError[] }) {
  const [steps, setSteps] = useState(() => stepsToDrafts(stored))
  useEffect(() => {
    latest.written = stepsToItems(steps)
  })
  return <StepListEditor steps={steps} context={stepPickers} errors={errors} onChange={setSteps} />
}

/** The step at `index` as it would be saved. */
const saved = (index: number) => (latest.written as { steps: unknown[] }).steps[index]

/** The editor with a Save button as a screen has one: client errors are shown, nothing is sent. */
const sent: { steps: unknown } = { steps: null }

function Saver({ stored }: { stored: unknown[] }) {
  const [steps, setSteps] = useState(() => stepsToDrafts(stored))
  const [errors, setErrors] = useState<StepError[]>([])
  return (
    <>
      <StepListEditor steps={steps} context={stepPickers} errors={errors} onChange={setSteps} />
      <button
        type="button"
        onClick={() => {
          const result = stepsToItems(steps)
          sent.steps = 'steps' in result ? result.steps : null
          setErrors('errors' in result ? clientStepErrors(result.errors) : [])
        }}
      >
        Save
      </button>
    </>
  )
}

const card = (number: number) =>
  within(screen.getByRole('group', { name: `Step ${String(number)}` }))

describe('the step list editor', () => {
  it('gives an untouched list back exactly as it was stored', () => {
    render(<Harness stored={stepList} />)

    expect(latest.written).toEqual({ steps: stepList })
    expect(screen.getAllByRole('group', { name: /^Step \d$/ })).toHaveLength(4)
  })

  it('adds a step of the chosen kind, moves it and deletes it', async () => {
    const user = userEvent.setup()
    render(<Harness stored={[stepList[3]]} />)

    await user.selectOptions(screen.getByLabelText('New step kind'), 'roll')
    await user.click(screen.getByRole('button', { name: 'Add step' }))
    expect(card(2).getByLabelText('Key')).toHaveValue('roll-1')
    await user.click(screen.getByRole('button', { name: 'Move step 2 up' }))
    expect(card(1).getByLabelText('Key')).toHaveValue('roll-1')
    expect(screen.getByRole('button', { name: 'Move step 1 up' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Delete step 1' }))

    expect(screen.getAllByRole('group', { name: /^Step \d$/ })).toHaveLength(1)
    expect(card(1).getByLabelText('Key')).toHaveValue('who')
  })

  it('flags a next that goes backward after a move, before saving, and offers later steps only', async () => {
    const user = userEvent.setup()
    render(
      <Harness
        stored={[
          { key: 'a', kind: 'character', title: 'A', next: 'b' },
          { key: 'b', kind: 'character', title: 'B' },
        ]}
      />,
    )
    expect(card(1).queryByText(/next only goes forward/)).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Move step 1 down' }))

    expect(card(2).getByText(/next only goes forward/)).toBeInTheDocument()
    expect(card(2).getByRole('combobox', { name: 'Next' })).toHaveValue('b')
    const options = within(card(1).getByRole('combobox', { name: 'Next' })).getAllByRole('option')
    expect(options.map((option) => option.getAttribute('value'))).toEqual(['', 'a', 'end'])
  })

  it('shows a backward next on a step and on a band in the fixture', () => {
    render(<Harness stored={stepListBackward} />)

    expect(card(2).getByText(/next only goes forward/)).toBeInTheDocument()
    expect(card(3).getByText(/next only goes forward/)).toBeInTheDocument()
  })

  it('picks next, and switches between mandatory and suggested, except on a condition', async () => {
    const user = userEvent.setup()
    render(<Harness stored={stepList} />)

    await user.selectOptions(card(1).getByRole('combobox', { name: 'Next' }), 'who')
    await user.click(card(1).getByRole('radio', { name: 'Suggested' }))

    expect(saved(0)).toMatchObject({ next: 'who' })
    expect(saved(0)).not.toHaveProperty('mandatory')
    expect(card(3).queryByRole('radio', { name: 'Mandatory' })).not.toBeInTheDocument()
  })

  it('shows the last band as the rest, and edits limits as a number, a tracker or a formula', async () => {
    const user = userEvent.setup()
    render(<Harness stored={stepList} />)
    const band = (number: number) =>
      within(card(2).getByRole('group', { name: `Band ${String(number)}` }))

    expect(band(3).getByText('Otherwise: the rest.')).toBeInTheDocument()
    expect(band(3).queryByLabelText('Up to')).not.toBeInTheDocument()
    expect(band(1).getByLabelText('Up to')).toHaveValue('2')
    expect(band(2).getByRole('combobox', { name: 'Up to' })).toHaveValue('alarm')

    await user.selectOptions(band(1).getByLabelText('Up to type'), 'formula')
    await user.type(band(1).getByLabelText('Up to'), 'heat + 1')
    await user.selectOptions(band(2).getByRole('combobox', { name: 'Up to' }), 'heat')
    await user.click(card(2).getByRole('button', { name: 'Add band' }))
    await user.type(band(3).getByLabelText('Up to'), '9')

    expect(saved(1)).toMatchObject({
      bands: [
        { upTo: { formula: 'heat + 1' } },
        { upTo: { tracker: 'heat' }, next: 'end' },
        { upTo: 9 },
        {},
      ],
    })
  })

  it('flags limits that do not increase, without blocking anything', async () => {
    const user = userEvent.setup()
    render(<Harness stored={stepList} />)
    const first = within(card(2).getByRole('group', { name: 'Band 1' }))

    await user.clear(first.getByLabelText('Up to'))
    await user.type(first.getByLabelText('Up to'), '5')
    await user.click(card(2).getByRole('button', { name: 'Add band' }))
    await user.type(
      within(card(2).getByRole('group', { name: 'Band 3' })).getByLabelText('Up to'),
      '3',
    )

    expect(card(2).getByText('Must be above the previous limit.')).toBeInTheDocument()
    expect(latest.written).toHaveProperty('steps')
  })

  it('keeps a step of another kind read-only, and still moves and deletes it', async () => {
    const user = userEvent.setup()
    render(<Harness stored={stepListWithOtherKinds} />)

    expect(card(2).getByText('pick')).toBeInTheDocument()
    expect(card(2).queryByLabelText('Key')).not.toBeInTheDocument()
    expect(card(3).getByText(/cannot edit yet/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Move step 2 up' }))
    expect(card(1).getByText('ask')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Delete step 3' }))

    expect(latest.written).toEqual({
      steps: [stepListWithOtherKinds[1], stepListWithOtherKinds[0]],
    })
  })

  it('shows a server error on its card and field, and anything else on the list', () => {
    const errors = [
      'sceneTypes[2].play[0].title: must not be blank',
      'sceneTypes[2].play[1].bands[0].upTo: must be an integer',
      'sceneTypes[2].play[1].bands[0].effects[0].tracker: unknown tracker',
      'sceneTypes[2].play[1].effects[3]: no such effect',
      'sceneTypes[2].play: at most 50 steps',
    ].map((text) => mapStepError('sceneTypes[2].play', text))
    render(<Harness stored={stepList} errors={errors} />)

    expect(card(1).getByText('must not be blank')).toBeInTheDocument()
    expect(
      within(card(2).getByRole('group', { name: 'Band 1' })).getByText('must be an integer'),
    ).toBeInTheDocument()
    const effect = within(
      within(card(2).getByRole('group', { name: 'Band 1' })).getByRole('group', {
        name: 'Effect 1',
      }),
    )
    expect(effect.getByText('unknown tracker')).toBeInTheDocument()
    expect(card(2).getByText('no such effect')).toBeInTheDocument()
    expect(screen.getByText('sceneTypes[2].play: at most 50 steps')).toBeInTheDocument()
  })

  it('keeps unknown fields of a step, a band and a count when something else is edited', async () => {
    const user = userEvent.setup()
    const stored = [
      {
        key: 'c',
        kind: 'condition',
        title: 'C',
        future: 1,
        count: { of: 'npc', scope: 'scene' },
        bands: [{ upTo: 1, colour: 'red' }, {}],
      },
    ]
    render(<Harness stored={stored} />)

    await user.type(card(1).getByLabelText('Title'), '!')

    expect(latest.written).toEqual({ steps: [{ ...stored[0], title: 'C!' }] })
  })

  it('saves what was picked for a condition count, inspiration tables and roll dice', async () => {
    const user = userEvent.setup()
    render(<Harness stored={stepList} />)

    await user.click(card(1).getByRole('checkbox', { name: 'Moods' }))
    await user.click(card(1).getByRole('checkbox', { name: 'Places' }))
    await user.clear(card(2).getByLabelText('Dice'))
    await user.type(card(2).getByLabelText('Dice'), '2d6')
    await user.selectOptions(card(3).getByRole('combobox', { name: 'Count of' }), 'thread')
    await user.click(card(3).getByRole('checkbox', { name: 'Gang' }))

    expect(saved(0)).toMatchObject({ inspiration: ['moods'] })
    expect(saved(1)).toMatchObject({ dice: '2d6' })
    expect(saved(2)).toMatchObject({ count: { of: 'thread' } })
    expect(saved(2)).not.toHaveProperty('count.tags')

    await user.selectOptions(card(3).getByRole('combobox', { name: 'Reads' }), 'tracker')
    await user.selectOptions(card(3).getByRole('combobox', { name: 'Tracker' }), 'alarm')

    expect(saved(2)).toMatchObject({ tracker: 'alarm' })
    expect(saved(2)).not.toHaveProperty('count')
  })

  describe('after bands move or go', () => {
    const band = (number: number) =>
      within(card(2).getByRole('group', { name: `Band ${String(number)}` }))

    it('sends the bands in the new order', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepList} />)

      await user.click(card(2).getByRole('button', { name: 'Move band 1 down' }))

      expect(saved(1)).toMatchObject({
        bands: [{ upTo: { tracker: 'alarm' }, next: 'end' }, { upTo: 2 }, {}],
      })
    })

    it('keeps a limit with its band when the band becomes the last one, and flags it', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepList} />)

      await user.click(card(2).getByRole('button', { name: 'Move band 2 down' }))

      expect(band(3).getByRole('combobox', { name: 'Up to' })).toHaveValue('alarm')
      expect(band(3).getByText('The last band catches the rest: remove its limit.')).toBeVisible()
      expect(latest.written).toEqual({
        errors: { '1.bands.1.upTo': 'Set a limit: only the last band catches the rest.' },
      })

      await user.type(band(2).getByLabelText('Up to'), '9')
      expect(saved(1)).toMatchObject({
        bands: [{ upTo: 2 }, { upTo: 9 }, { upTo: { tracker: 'alarm' }, next: 'end' }],
      })

      await user.click(band(3).getByRole('button', { name: 'Remove the limit of band 3' }))
      expect(saved(1)).toMatchObject({ bands: [{ upTo: 2 }, { upTo: 9 }, { next: 'end' }] })
      expect(band(3).getByText('Otherwise: the rest.')).toBeInTheDocument()
    })

    it('keeps the limit of the band that stays when the last one is deleted', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepList} />)

      await user.click(card(2).getByRole('button', { name: 'Delete band 3' }))
      await user.click(card(2).getByRole('button', { name: 'Delete band 1' }))

      expect(band(1).getByRole('combobox', { name: 'Up to' })).toHaveValue('alarm')
      expect(band(1).getByText('The last band catches the rest: remove its limit.')).toBeVisible()
      expect(saved(1)).toMatchObject({ bands: [{ upTo: { tracker: 'alarm' }, next: 'end' }] })
    })
  })

  describe('a server error keyed by position', () => {
    const errors = [
      'sceneTypes[2].play[1].title: must not be blank',
      'sceneTypes[2].play[2].bands[0].upTo: must be an integer',
    ].map((text) => mapStepError('sceneTypes[2].play', text))

    it('follows its step when steps move, and goes with it when the step is deleted', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepList} errors={errors} />)
      expect(card(2).getByText('must not be blank')).toBeInTheDocument()

      await user.click(screen.getByRole('button', { name: 'Move step 2 down' }))
      expect(card(3).getByText('must not be blank')).toBeInTheDocument()
      expect(card(2).queryByText('must not be blank')).not.toBeInTheDocument()

      await user.click(screen.getByRole('button', { name: 'Delete step 1' }))
      expect(card(2).getByText('must not be blank')).toBeInTheDocument()

      await user.click(screen.getByRole('button', { name: 'Delete step 2' }))
      expect(screen.queryByText('must not be blank')).not.toBeInTheDocument()
    })

    it('is not shown on a step added after the answer, and band messages go when bands move', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepList} errors={errors} />)
      const first = within(card(3).getByRole('group', { name: 'Band 1' }))
      expect(first.getByText('must be an integer')).toBeInTheDocument()

      await user.click(screen.getByRole('button', { name: 'Add step' }))
      await user.click(card(3).getByRole('button', { name: 'Move band 1 down' }))

      expect(screen.queryByText('must be an integer')).not.toBeInTheDocument()
      expect(card(5).queryByText('must not be blank')).not.toBeInTheDocument()
    })
  })

  it('offers each next once and warns about repeated and reserved keys, with no duplicate React keys', () => {
    const logged = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    render(
      <Harness
        stored={[
          { key: 'x', kind: 'character', title: 'X' },
          { key: 'a', kind: 'character', title: 'A' },
          { key: 'a', kind: 'character', title: 'A again' },
          { key: 'end', kind: 'character', title: 'End' },
          {
            key: 'c',
            kind: 'condition',
            title: 'C',
            count: { of: 'npc' },
            bands: [{ upTo: 1 }, {}],
          },
        ]}
        errors={[
          { index: null, band: null, effect: null, field: null, message: 'Twice' },
          { index: null, band: null, effect: null, field: null, message: 'Twice' },
          { index: 4, band: null, effect: null, field: 'mystery', message: 'Same' },
          { index: 4, band: null, effect: null, field: 'mystery', message: 'Same' },
        ]}
      />,
    )

    const options = within(card(1).getByRole('combobox', { name: 'Next' })).getAllByRole('option')
    expect(options.map((option) => option.getAttribute('value'))).toEqual(['', 'a', 'c', 'end'])
    expect(card(3).getByText('Another step has this key.')).toBeInTheDocument()
    expect(card(4).getByText('end is reserved for the end of the list.')).toBeInTheDocument()
    expect(screen.getAllByText('Twice')).toHaveLength(2)
    expect(logged).not.toHaveBeenCalled()
    logged.mockRestore()
  })

  it('shows an effect error for a band that does not exist on the card, never nowhere', () => {
    const errors = [
      'sceneTypes[2].play[1].bands[7].effects[0].tracker: unknown tracker',
      'sceneTypes[2].play[1].bands[7].upTo: must be an integer',
      'sceneTypes[2].play[0].bands[0].effects[0]: no bands here',
    ].map((text) => mapStepError('sceneTypes[2].play', text))
    render(<Harness stored={stepList} errors={errors} />)

    expect(card(2).getByText('tracker: unknown tracker')).toBeInTheDocument()
    expect(card(2).getByText('upTo: must be an integer')).toBeInTheDocument()
    expect(card(1).getByText('no bands here')).toBeInTheDocument()
  })

  it('shows a value that is not a whole number next to it and sends nothing', async () => {
    const user = userEvent.setup()
    render(<Saver stored={stepList} />)
    const effects = within(card(1).getByRole('group', { name: 'Effects' }))
    const band = within(card(2).getByRole('group', { name: 'Band 1 effects' }))

    await user.click(effects.getByRole('button', { name: 'Add effect' }))
    await user.clear(effects.getByLabelText('Value'))
    await user.type(effects.getByLabelText('Value'), '1.5')
    await user.clear(band.getByLabelText('Value'))
    await user.type(band.getByLabelText('Value'), 'abc')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(sent.steps).toBeNull()
    expect(effects.getByText('Use a whole number.')).toBeInTheDocument()
    expect(band.getByText('Use a whole number.')).toBeInTheDocument()

    await user.clear(effects.getByLabelText('Value'))
    await user.type(effects.getByLabelText('Value'), '2')
    await user.clear(band.getByLabelText('Value'))
    await user.type(band.getByLabelText('Value'), '3')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(screen.queryByText('Use a whole number.')).not.toBeInTheDocument()
    expect((sent.steps as unknown[])[0]).toMatchObject({
      effects: [{ kind: 'tracker', value: 2 }],
    })
  })

  describe('with effects', () => {
    const effectsOf = (step: number) => within(card(step).getByRole('group', { name: 'Effects' }))

    it('edits the effects of a step and of a band, and saves them', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepList} />)
      const band = within(card(2).getByRole('group', { name: 'Band 1' }))

      await user.selectOptions(effectsOf(1).getByLabelText('New effect kind'), 'sceneTitle')
      await user.click(effectsOf(1).getByRole('button', { name: 'Add effect' }))
      await user.type(effectsOf(1).getByLabelText('Title'), 'Dawn')
      await user.clear(band.getByLabelText('Value'))
      await user.type(band.getByLabelText('Value'), '3')

      expect(saved(0)).toMatchObject({ effects: [{ kind: 'sceneTitle', title: 'Dawn' }] })
      expect(saved(1)).toMatchObject({
        bands: [{ effects: [{ kind: 'tracker', tracker: 'heat', op: 'add', value: 3 }] }, {}, {}],
      })
    })

    it('allows sheet effects in a character step only', () => {
      render(<Harness stored={stepList} />)
      const kinds = (step: number) =>
        within(effectsOf(step).getByLabelText('New effect kind'))
          .getAllByRole('option')
          .map((option) => option.getAttribute('value'))

      expect(kinds(4)).toContain('sheet')
      expect(kinds(1)).not.toContain('sheet')
      expect(
        within(card(2).getByRole('group', { name: 'Band 1 effects' })).getByLabelText(
          'New effect kind',
        ),
      ).not.toHaveTextContent('sheet')
    })

    it('shows effect errors on their card, and drops them when effects move', async () => {
      const user = userEvent.setup()
      const errors = [
        'sceneTypes[2].play[0].effects[0]: no good',
        'sceneTypes[2].play[0].effects[1]: also no good',
      ].map((text) => mapStepError('sceneTypes[2].play', text))
      render(
        <Harness
          stored={[
            {
              key: 'a',
              kind: 'character',
              title: 'A',
              effects: [{ kind: 'endPhase' }, { kind: 'closeThread' }],
            },
          ]}
          errors={errors}
        />,
      )
      expect(effectsOf(1).getByText('no good')).toBeInTheDocument()
      expect(effectsOf(1).getByText('also no good')).toBeInTheDocument()

      await user.click(screen.getByRole('button', { name: 'Move effect 1 down' }))

      expect(screen.queryByText('no good')).not.toBeInTheDocument()
      expect(screen.queryByText('also no good')).not.toBeInTheDocument()
    })
  })

  describe('the oracle step', () => {
    const answer = (name: string) => within(card(1).getByRole('group', { name }))

    it('picks the oracle and a level of it, and writes the answers that have branches', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListBranching} />)
      expect(latest.written).toEqual({ steps: stepListBranching })

      expect(
        within(card(1).getByLabelText('Likelihood level'))
          .getAllByRole('option')
          .map((option) => option.getAttribute('value')),
      ).toEqual(['', 'unlikely', 'even', 'likely'])
      await user.selectOptions(card(1).getByLabelText('Likelihood level'), 'even')
      await user.selectOptions(answer('No').getByLabelText('Next'), 'which')
      await user.selectOptions(answer('Yes').getByLabelText('Next'), '')
      await user.selectOptions(answer('Yes').getByLabelText('New effect kind'), 'endPhase')
      await user.click(answer('Yes').getByRole('button', { name: 'Add effect' }))

      expect(saved(0)).toEqual({
        ...stepListBranching[0],
        likelihood: 'even',
        branches: {
          yes: { effects: [{ kind: 'endPhase' }] },
          no: { next: 'which' },
          exceptionalNo: { effects: [{ kind: 'sceneTitle', title: 'Luck' }] },
        },
      })
    })

    it('keeps the exceptional answers collapsed until needed, with a hint, and omits empty answers', async () => {
      const user = userEvent.setup()
      render(
        <Harness
          stored={[
            {
              key: 'o',
              kind: 'oracle',
              title: 'O',
              oracle: 'fate',
              branches: { no: { next: 'end' } },
            },
          ]}
        />,
      )

      expect(card(1).getByText(/uses yes \/ no/)).toBeVisible()
      expect(card(1).queryByRole('group', { name: 'Exceptional yes' })).not.toBeInTheDocument()
      await user.click(card(1).getByRole('button', { name: 'Exceptional answers' }))
      await user.selectOptions(answer('Exceptional yes').getByLabelText('Next'), 'end')
      await user.selectOptions(answer('Exceptional yes').getByLabelText('Next'), '')
      await user.selectOptions(answer('No').getByLabelText('Next'), '')

      expect(saved(0)).toEqual({ key: 'o', kind: 'oracle', title: 'O', oracle: 'fate' })
    })

    it('keeps a level the oracle does not have, flagged, and clears it on request', async () => {
      const user = userEvent.setup()
      const stored = [
        { key: 'o', kind: 'oracle', title: 'O', oracle: 'fate', likelihood: 'certain' },
      ]
      render(<Harness stored={stored} />)

      expect(card(1).getByLabelText('Likelihood level')).toHaveValue('certain')
      expect(card(1).getByText('Not a level of this oracle.')).toBeInTheDocument()
      expect(saved(0)).toEqual(stored[0])
      await user.selectOptions(card(1).getByLabelText('Likelihood level'), '')

      expect(saved(0)).not.toHaveProperty('likelihood')
    })
  })

  describe('the table step', () => {
    const branch = (number: number) =>
      within(card(2).getByRole('group', { name: `Branch ${String(number)}` }))

    it('edits the entry branches and otherwise, and writes them in their order', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListBranching} />)

      await user.click(card(2).getByRole('button', { name: 'Add branch' }))
      await user.selectOptions(branch(3).getByLabelText('Entry'), 'patrol')
      expect(branch(3).getByText('Another branch has this entry.')).toBeInTheDocument()
      await user.selectOptions(branch(1).getByLabelText('Next'), 'end')
      await user.click(screen.getByRole('button', { name: 'Move branch 2 up' }))
      await user.click(screen.getByRole('button', { name: 'Delete branch 3' }))
      await user.selectOptions(
        within(card(2).getByRole('group', { name: 'Otherwise' })).getByLabelText('Next'),
        '',
      )

      expect(saved(1)).toEqual({
        key: 'event',
        kind: 'table',
        title: 'What happens?',
        table: 'complications',
        branches: [
          { entry: 'lucky-break', effects: [{ kind: 'sceneTitle', title: 'Luck' }] },
          { entry: 'patrol', next: 'end' },
        ],
      })
    })

    it('keeps an entry the table does not have, flagged, and omits an empty branch list', async () => {
      const user = userEvent.setup()
      const stored = [
        {
          key: 't',
          kind: 'table',
          title: 'T',
          table: 'complications',
          branches: [{ entry: 'ghost' }],
        },
      ]
      render(<Harness stored={stored} />)

      expect(card(1).getByText('Not an entry of this table.')).toBeInTheDocument()
      expect(saved(0)).toEqual(stored[0])
      await user.click(card(1).getByRole('button', { name: 'Delete branch 1' }))

      expect(saved(0)).toEqual({ key: 't', kind: 'table', title: 'T', table: 'complications' })
    })

    it('picks the table, which gives the entries to pick from', async () => {
      const user = userEvent.setup()
      render(<Harness stored={[{ key: 't', kind: 'table', title: 'T', table: 'places' }]} />)

      await user.click(card(1).getByRole('button', { name: 'Add branch' }))

      expect(
        within(card(1).getByLabelText('Entry'))
          .getAllByRole('option')
          .map((option) => option.getAttribute('value')),
      ).toEqual(['', 'market', 'docks'])
      await user.selectOptions(card(1).getByLabelText('Table'), 'moods')
      expect(saved(0)).toMatchObject({ table: 'moods', branches: [{ entry: '' }] })
    })
  })

  describe('the choice step', () => {
    const option = (number: number) =>
      within(card(3).getByRole('group', { name: `Option ${String(number)}` }))

    it('starts with two options and holds the count between two and ten', async () => {
      const user = userEvent.setup()
      render(<Harness stored={[]} />)

      await user.selectOptions(screen.getByLabelText('New step kind'), 'choice')
      await user.click(screen.getByRole('button', { name: 'Add step' }))
      expect(card(1).getByLabelText('Skip')).toHaveValue('')
      expect(
        card(1)
          .getAllByLabelText('Option key')
          .map((input) => (input as HTMLInputElement).value),
      ).toEqual(['option-1', 'option-2'])
      expect(card(1).getByRole('button', { name: 'Delete option 1' })).toBeDisabled()
      expect(card(1).getByText('A choice needs at least 2 options.')).toBeInTheDocument()

      for (let at = 0; at < 8; at += 1) {
        await user.click(card(1).getByRole('button', { name: 'Add option' }))
      }
      expect(card(1).getByRole('button', { name: 'Add option' })).toBeDisabled()
      expect(card(1).getByText('At most 10 options.')).toBeInTheDocument()
      expect(card(1).getAllByLabelText('Option key')).toHaveLength(10)
      expect(card(1).getByRole('button', { name: 'Delete option 1' })).toBeEnabled()
    })

    it('renames the skipped option with its key, flags a skip that names nothing, and saves', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListBranching} />)

      await user.clear(option(1).getByLabelText('Option key'))
      await user.type(option(1).getByLabelText('Option key'), 'west')
      expect(card(3).getByLabelText('Skip')).toHaveValue('west')
      await user.click(card(3).getByRole('button', { name: 'Move option 2 up' }))
      await user.selectOptions(option(2).getByLabelText('Next'), '')
      await user.type(option(1).getByLabelText('Label'), '!')

      expect(saved(2)).toEqual({
        ...stepListBranching[2],
        options: [
          { key: 'right', label: 'Right!' },
          { key: 'west', label: 'Left' },
        ],
        skip: 'west',
      })

      await user.click(card(3).getByRole('button', { name: 'Add option' }))
      await user.click(card(3).getByRole('button', { name: 'Delete option 2' }))
      expect(card(3).getByLabelText('Skip')).toHaveValue('west')
      expect(card(3).getByText('No option has this key.')).toBeInTheDocument()
    })

    it('flags repeated keys and a suggested choice without skip', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListBranching} />)

      await user.type(
        option(2).getByLabelText('Option key'),
        '{Backspace}{Backspace}{Backspace}{Backspace}{Backspace}left',
      )
      expect(option(2).getByText('Another option has this key.')).toBeInTheDocument()
      await user.click(card(3).getByRole('radio', { name: 'Suggested' }))
      await user.selectOptions(card(3).getByLabelText('Skip'), '')

      expect(
        card(3).getByText('A suggested choice must name the option taken when it is skipped.'),
      ).toBeInTheDocument()
      expect(saved(2)).not.toHaveProperty('skip')
    })
  })

  describe('a server error about branches and options', () => {
    const errors = (...paths: string[]) =>
      paths.map((text) => mapStepError('sceneTypes[2].play', text))

    it('shows each message on its answer, entry, option and field', () => {
      render(
        <Harness
          stored={stepListBranching}
          errors={errors(
            'sceneTypes[2].play[0].oracle: unknown oracle',
            'sceneTypes[2].play[0].likelihood: unknown level',
            'sceneTypes[2].play[0].branches.yes.next: backward',
            'sceneTypes[2].play[0].branches.exceptionalNo.effects[0].title: blank title',
            'sceneTypes[2].play[1].table: unknown table',
            'sceneTypes[2].play[1].branches[1].entry: unknown entry',
            'sceneTypes[2].play[1].branches[0].effects[0]: no effect',
            'sceneTypes[2].play[1].otherwise.effects[0]: none here',
            'sceneTypes[2].play[2].options[1].label: blank label',
            'sceneTypes[2].play[2].options[0].next: not later',
            'sceneTypes[2].play[2].skip: unknown option',
          )}
        />,
      )
      const within1 = (card: number, name: string) =>
        within(
          within(screen.getByRole('group', { name: `Step ${String(card)}` })).getByRole('group', {
            name,
          }),
        )

      expect(card(1).getByText('unknown oracle')).toBeInTheDocument()
      expect(card(1).getByText('unknown level')).toBeInTheDocument()
      expect(within1(1, 'Yes').getByText('backward')).toBeInTheDocument()
      expect(within1(1, 'Exceptional no').getByText('blank title')).toBeVisible()
      expect(card(2).getByText('unknown table')).toBeInTheDocument()
      expect(within1(2, 'Branch 2').getByText('unknown entry')).toBeInTheDocument()
      expect(within1(2, 'Branch 1 outcome').getByText('no effect')).toBeInTheDocument()
      expect(within1(2, 'Otherwise').getByText('none here')).toBeInTheDocument()
      expect(within1(3, 'Option 2').getByText('blank label')).toBeInTheDocument()
      expect(within1(3, 'Option 1 outcome').getByText('not later')).toBeInTheDocument()
      expect(card(3).getByText('unknown option')).toBeInTheDocument()
    })

    it('lists a message about a part the step does not have on the card', () => {
      render(
        <Harness
          stored={stepListBranching}
          errors={errors(
            'sceneTypes[2].play[1].branches[9].entry: no such branch',
            'sceneTypes[2].play[2].options[7].label: no such option',
            'sceneTypes[2].play[2].otherwise.next: no otherwise here',
            'sceneTypes[2].play[0].branches.yes.next: fine',
            'sceneTypes[2].play[1].branches: at most 1000',
          )}
        />,
      )

      expect(card(2).getByText('entry: no such branch')).toBeInTheDocument()
      expect(card(3).getByText('label: no such option')).toBeInTheDocument()
      expect(card(3).getByText('next: no otherwise here')).toBeInTheDocument()
      expect(card(2).getByText('branches: at most 1000')).toBeInTheDocument()
    })

    it('follows its branch or option through moves, and goes when that one is deleted', async () => {
      const user = userEvent.setup()
      render(
        <Harness
          stored={stepListBranching}
          errors={errors(
            'sceneTypes[2].play[1].branches[0].entry: first entry',
            'sceneTypes[2].play[1].branches[1].effects[0]: second effect',
            'sceneTypes[2].play[2].options[1].label: right label',
          )}
        />,
      )
      const branch = (number: number) =>
        within(card(2).getByRole('group', { name: `Branch ${String(number)}` }))

      await user.click(screen.getByRole('button', { name: 'Move branch 1 down' }))
      expect(branch(2).getByText('first entry')).toBeInTheDocument()
      expect(branch(1).queryByText('first entry')).not.toBeInTheDocument()

      await user.click(card(2).getByRole('button', { name: 'Add branch' }))
      await user.click(screen.getByRole('button', { name: 'Move branch 3 up' }))
      expect(branch(2).queryByText('first entry')).not.toBeInTheDocument()
      expect(branch(3).getByText('first entry')).toBeInTheDocument()

      await user.click(screen.getByRole('button', { name: 'Delete branch 3' }))
      expect(screen.queryByText('first entry')).not.toBeInTheDocument()
      await user.click(card(3).getByRole('button', { name: 'Add option' }))
      await user.click(screen.getByRole('button', { name: 'Delete option 2' }))
      expect(screen.queryByText('right label')).not.toBeInTheDocument()
    })
  })
})
