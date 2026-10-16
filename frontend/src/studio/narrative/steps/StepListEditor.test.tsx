import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useEffect, useState } from 'react'

import { stepList, stepListBackward, stepListWithOtherKinds, stepPickers } from '../../fixtures'
import { StepListEditor } from './StepListEditor'
import { stepsToDrafts, stepsToItems } from './stepDraft'
import { mapStepError, type StepError } from './stepError'

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
    expect(card(2).getByText('1 effect: tracker')).toBeInTheDocument()

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

    expect(card(2).getByText('oracle')).toBeInTheDocument()
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
      'sceneTypes[2].play[1].effects[0]: unknown tracker',
      'sceneTypes[2].play: at most 50 steps',
    ].map((text) => mapStepError('sceneTypes[2].play', text))
    render(<Harness stored={stepList} errors={errors} />)

    expect(card(1).getByText('must not be blank')).toBeInTheDocument()
    expect(
      within(card(2).getByRole('group', { name: 'Band 1' })).getByText('must be an integer'),
    ).toBeInTheDocument()
    expect(card(2).getByRole('alert')).toHaveTextContent('effects: unknown tracker')
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
})
