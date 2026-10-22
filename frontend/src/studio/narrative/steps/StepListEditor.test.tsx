import { render, screen, within } from '@testing-library/react'
import userEvent, { type UserEvent } from '@testing-library/user-event'
import { useEffect, useState } from 'react'

import {
  stepList,
  stepListBackward,
  stepListBranching,
  stepListPickCheck,
  stepListWithOtherKinds,
  stepPickers,
} from '../../fixtures'
import { StepListEditor } from './StepListEditor'
import { stepKinds, stepsToDrafts, stepsToItems } from './stepDraft'
import { clientStepErrors, mapStepError, type StepError } from './stepError'
import { chooseOption, listOptions } from '@/test/chooseOption'

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

/** Opens the Add step menu and picks a kind, the way a person does. */
async function addStep(user: UserEvent, kind = 'prompt') {
  await user.click(screen.getByRole('button', { name: 'Add step' }))
  await user.click(await screen.findByRole('menuitem', { name: kind }))
}

describe('the step items', () => {
  /** The badges of a step's item: the first button of the card is its disclosure. */
  const badges = (number: number) => {
    const toggle = card(number).getAllByRole('button')[0]
    if (!toggle) {
      throw new Error(`No disclosure on step ${String(number)}.`)
    }
    return within(toggle).queryAllByText(/./, { selector: '[data-slot="badge"]' })
  }
  const badge = (number: number, text: string) =>
    badges(number).find((item) => item.textContent === text)

  it('names the table, oracle or check a step is bound to, and nothing for the others', () => {
    render(<Harness stored={[...stepListBranching, ...stepListPickCheck]} />)

    expect(badge(1, 'oracle')).toBeDefined()
    expect(badge(1, 'Fate')).toBeDefined()
    expect(badge(2, 'Complications')).toBeDefined()
    expect(badge(5, 'Skill check')).toBeDefined()
    // A choice and a pick are bound to nothing: only their kind shows.
    expect(badges(3)).toHaveLength(1)
    expect(badges(4)).toHaveLength(1)
  })

  it('shows the key when a bound name is gone, and nothing while none is picked', async () => {
    const user = userEvent.setup()
    render(<Harness stored={[{ ...stepListBranching[1], table: 'vanished' }]} />)

    expect(badge(1, 'vanished')).toBeDefined()
    await addStep(user, 'table')
    expect(badges(2)).toHaveLength(1)
  })

  it('shows the title with the key and the kind, behind a disclosure that closes the fields', async () => {
    const user = userEvent.setup()
    render(<Harness stored={[stepList[0]]} />)

    const toggle = card(1).getByRole('button', { name: /^Set the scene/ })
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
    expect(toggle).toHaveTextContent('intro')
    expect(toggle).toHaveTextContent('prompt')
    await user.click(toggle)

    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    expect(card(1).queryByLabelText('Title')).not.toBeInTheDocument()
  })

  it('offers every kind in the Add step menu, each with its tile', async () => {
    const user = userEvent.setup()
    render(<Harness stored={[]} />)

    await user.click(screen.getByRole('button', { name: 'Add step' }))

    const items = await screen.findAllByRole('menuitem')
    expect(items.map((item) => item.textContent)).toEqual([...stepKinds])
    for (const item of items) {
      expect(item.querySelector('[aria-hidden="true"] svg')).not.toBeNull()
    }
    // The old kind select is gone.
    expect(screen.queryByRole('combobox', { name: 'New step kind' })).not.toBeInTheDocument()
  })

  it('moves a step with icon buttons that keep their names and show them as tooltips', async () => {
    const user = userEvent.setup()
    render(<Harness stored={[stepList[0], stepList[3]]} />)

    const down = screen.getByRole('button', { name: 'Move step 1 down' })
    expect(down.querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
    await user.hover(down)
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Move step 1 down')
  })
})

describe('the step list editor', () => {
  it('gives an untouched list back exactly as it was stored', () => {
    render(<Harness stored={stepList} />)

    expect(latest.written).toEqual({ steps: stepList })
    expect(screen.getAllByRole('group', { name: /^Step \d$/ })).toHaveLength(4)
  })

  it('scrolls the current step into view, and only that one', () => {
    const scroll = vi.spyOn(Element.prototype, 'scrollIntoView')
    const steps = stepsToDrafts(stepList)
    const { rerender } = render(
      <StepListEditor steps={steps} context={stepPickers} onChange={() => undefined} />,
    )
    expect(scroll).not.toHaveBeenCalled()

    rerender(
      <StepListEditor steps={steps} context={stepPickers} current={2} onChange={() => undefined} />,
    )

    expect(scroll).toHaveBeenCalledTimes(1)
    expect(scroll.mock.contexts[0]).toBe(screen.getByRole('group', { name: 'Step 3' }))
    scroll.mockRestore()
  })

  it('adds a step of the chosen kind, moves it and deletes it', async () => {
    const user = userEvent.setup()
    render(<Harness stored={[stepList[3]]} />)

    await addStep(user, 'roll')
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
    const options = await listOptions(user, card(1).getByRole('combobox', { name: 'Next' }))
    expect(options.map((option) => option.value)).toEqual(['', 'a', 'end'])
  })

  it('shows a backward next on a step and on a band in the fixture', () => {
    render(<Harness stored={stepListBackward} />)

    expect(card(2).getByText(/next only goes forward/)).toBeInTheDocument()
    expect(card(3).getByText(/next only goes forward/)).toBeInTheDocument()
  })

  it('picks next, and switches between mandatory and suggested, except on a condition', async () => {
    const user = userEvent.setup()
    render(<Harness stored={stepList} />)

    await chooseOption(user, 'Next', 'who', card(1))
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

    await chooseOption(user, 'Up to type', 'formula', band(1))
    await user.type(band(1).getByLabelText('Up to'), 'heat + 1')
    await chooseOption(user, 'Up to', 'heat', band(2))
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

  it('edits the optional label of a band and drops the field when it is emptied', async () => {
    const user = userEvent.setup()
    render(<Harness stored={stepList} />)
    const band = (number: number) =>
      within(card(2).getByRole('group', { name: `Band ${String(number)}` }))

    expect(band(1).getByLabelText('Label')).toHaveValue('')
    expect(saved(1)).not.toMatchObject({ bands: [{ label: expect.anything() as unknown }] })

    await user.type(band(1).getByLabelText('Label'), 'Quiet')
    await user.type(band(3).getByLabelText('Label'), 'Loud')
    expect(saved(1)).toMatchObject({ bands: [{ label: 'Quiet' }, {}, { label: 'Loud' }] })

    await user.clear(band(1).getByLabelText('Label'))
    expect(saved(1)).toMatchObject({ bands: [{}, {}, { label: 'Loud' }] })
    expect((saved(1) as { bands: object[] }).bands[0]).not.toHaveProperty('label')
  })

  it('shows a server error about a band label on that band', () => {
    const errors = [
      mapStepError(
        'sceneTypes[2].play',
        'sceneTypes[2].play[1].bands[0].label: must be at most 80 characters, 81 given',
      ),
    ]
    render(<Harness stored={stepList} errors={errors} />)

    expect(
      within(card(2).getByRole('group', { name: 'Band 1' })).getByText(
        'must be at most 80 characters, 81 given',
      ),
    ).toBeInTheDocument()
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

  it('keeps a branching step whose branches or options are not a list read-only and sends it unchanged', async () => {
    const user = userEvent.setup()
    const unreadable = [
      { key: 't', kind: 'table', title: 'T', table: 'moods', branches: 'none' },
      { key: 'c', kind: 'choice', title: 'C', options: 'none' },
    ]
    render(<Saver stored={unreadable} />)

    expect(card(1).getByText(/cannot edit yet/)).toBeInTheDocument()
    expect(card(1).queryByRole('button', { name: 'Add branch' })).not.toBeInTheDocument()
    expect(card(2).getByText(/cannot edit yet/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(sent.steps).toEqual(unreadable)
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
    await chooseOption(user, 'Count of', 'thread', card(3))
    await user.click(card(3).getByRole('checkbox', { name: 'Gang' }))

    expect(saved(0)).toMatchObject({ inspiration: ['moods'] })
    expect(saved(1)).toMatchObject({ dice: '2d6' })
    expect(saved(2)).toMatchObject({ count: { of: 'thread' } })
    expect(saved(2)).not.toHaveProperty('count.tags')

    await chooseOption(user, 'Reads', 'tracker', card(3))
    await chooseOption(user, 'Tracker', 'alarm', card(3))

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

      await addStep(user)
      await user.click(card(3).getByRole('button', { name: 'Move band 1 down' }))

      expect(screen.queryByText('must be an integer')).not.toBeInTheDocument()
      expect(card(5).queryByText('must not be blank')).not.toBeInTheDocument()
    })
  })

  it('offers each next once and warns about repeated and reserved keys, with no duplicate React keys', async () => {
    const user = userEvent.setup()
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

    const options = await listOptions(user, card(1).getByRole('combobox', { name: 'Next' }))
    expect(options.map((option) => option.value)).toEqual(['', 'a', 'c', 'end'])
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

      await chooseOption(user, 'New effect kind', 'sceneTitle', effectsOf(1))
      await user.click(effectsOf(1).getByRole('button', { name: 'Add effect' }))
      await user.type(effectsOf(1).getByLabelText('Title'), 'Dawn')
      await user.clear(band.getByLabelText('Value'))
      await user.type(band.getByLabelText('Value'), '3')

      expect(saved(0)).toMatchObject({ effects: [{ kind: 'sceneTitle', title: 'Dawn' }] })
      expect(saved(1)).toMatchObject({
        bands: [{ effects: [{ kind: 'tracker', tracker: 'heat', op: 'add', value: 3 }] }, {}, {}],
      })
    })

    it('allows sheet effects in a character step only', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepList} />)
      const kinds = async (step: number) =>
        (await listOptions(user, effectsOf(step).getByLabelText('New effect kind'))).map(
          (option) => option.value,
        )

      expect(await kinds(4)).toContain('sheet')
      expect(await kinds(1)).not.toContain('sheet')
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
        (await listOptions(user, card(1).getByLabelText('Likelihood level'))).map(
          (option) => option.value,
        ),
      ).toEqual(['', 'unlikely', 'even', 'likely'])
      await chooseOption(user, 'Likelihood level', 'even', card(1))
      await chooseOption(user, 'Next', 'which', answer('No'))
      await chooseOption(user, 'Next', '', answer('Yes'))
      await chooseOption(user, 'New effect kind', 'endPhase', answer('Yes'))
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
      await chooseOption(user, 'Next', 'end', answer('Exceptional yes'))
      await chooseOption(user, 'Next', '', answer('Exceptional yes'))
      await chooseOption(user, 'Next', '', answer('No'))

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
      await chooseOption(user, 'Likelihood level', '', card(1))

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
      await chooseOption(user, 'Entry', 'patrol', branch(3))
      expect(branch(3).getByText('Another branch has this entry.')).toBeInTheDocument()
      await chooseOption(user, 'Next', 'end', branch(1))
      await user.click(screen.getByRole('button', { name: 'Move branch 2 up' }))
      await user.click(screen.getByRole('button', { name: 'Delete branch 3' }))
      await chooseOption(
        user,
        'Next',
        'The following step',
        within(card(2).getByRole('group', { name: 'Otherwise' })),
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
        (await listOptions(user, card(1).getByLabelText('Entry'))).map((option) => option.value),
      ).toEqual(['', 'market', 'docks'])
      await chooseOption(user, 'Table', 'moods', card(1))
      expect(saved(0)).toMatchObject({ table: 'moods', branches: [{ entry: '' }] })
    })
  })

  describe('the choice step', () => {
    const option = (number: number) =>
      within(card(3).getByRole('group', { name: `Option ${String(number)}` }))

    it('starts with two options and holds the count between two and ten', async () => {
      const user = userEvent.setup()
      render(<Harness stored={[]} />)

      await addStep(user, 'choice')
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
      await chooseOption(user, 'Next', '', option(2))
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
      await chooseOption(user, 'Skip', '', card(3))

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

    it('lists a message about a field the row does not have, and shows every message of a field it has', () => {
      render(
        <Harness
          stored={stepListBranching}
          errors={errors(
            'sceneTypes[2].play[1].branches[0].key: not a branch field',
            'sceneTypes[2].play[1].branches[0].entry: first on entry',
            'sceneTypes[2].play[1].branches[0].entry: second on entry',
            'sceneTypes[2].play[2].options[0].entry: not an option field',
            'sceneTypes[2].play[2].options[0].key: first on key',
            'sceneTypes[2].play[2].options[0].key: second on key',
          )}
        />,
      )
      const within1 = (card: number, name: string) =>
        within(
          within(screen.getByRole('group', { name: `Step ${String(card)}` })).getByRole('group', {
            name,
          }),
        )

      expect(within1(2, 'Branch 1').getByText('first on entry')).toBeInTheDocument()
      expect(within1(2, 'Branch 1').getByText('entry: second on entry')).toBeInTheDocument()
      expect(within1(2, 'Branch 1').getByText('key: not a branch field')).toBeInTheDocument()
      expect(within1(3, 'Option 1').getByText('first on key')).toBeInTheDocument()
      expect(within1(3, 'Option 1').getByText('key: second on key')).toBeInTheDocument()
      expect(within1(3, 'Option 1').getByText('entry: not an option field')).toBeInTheDocument()
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

  describe('with pick and check steps', () => {
    const inside = (cardNumber: number, name: string) =>
      within(card(cardNumber).getByRole('group', { name }))
    const errors = (...paths: string[]) =>
      paths.map((text) => mapStepError('sceneTypes[2].play', text))

    it('gives the fixture steps back as stored', () => {
      render(<Harness stored={stepListPickCheck} />)

      expect(latest.written).toEqual({ steps: stepListPickCheck })
    })

    it('edits what a pick is of, its mode, tags and count, and omits the default count', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListPickCheck} />)

      await chooseOption(user, 'Pick of', 'thread', card(1))
      await chooseOption(user, 'Mode', 'choose', card(1))
      await user.click(card(1).getByRole('checkbox', { name: 'Gang' }))
      await user.clear(card(1).getByLabelText('Count max'))
      await user.type(card(1).getByLabelText('Count max'), '2')

      expect(saved(0)).toMatchObject({
        of: 'thread',
        mode: 'choose',
        count: { min: 1, max: 2 },
      })
      expect(saved(0)).not.toHaveProperty('tags')

      await user.clear(card(1).getByLabelText('Count max'))
      await user.type(card(1).getByLabelText('Count max'), '1')

      expect(saved(0)).not.toHaveProperty('count')
    })

    it('guides a count whose max is below its min, and edits the found and none outcomes without sheet effects', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListPickCheck} />)

      await user.clear(card(1).getByLabelText('Count min'))
      await user.type(card(1).getByLabelText('Count min'), '5')
      await chooseOption(user, 'Next', 'end', inside(1, 'None'))

      expect(card(1).getByText('Max must be at least min.')).toBeInTheDocument()
      expect(saved(0)).toMatchObject({
        count: { min: 5, max: 3 },
        branches: { none: { next: 'end' } },
      })
      const kinds = (
        await listOptions(user, inside(1, 'Found').getByLabelText('New effect kind'))
      ).map((option) => option.value)
      expect(kinds).not.toContain('sheet')
    })

    it('shows a check count that is not whole next to it and sends nothing', async () => {
      const user = userEvent.setup()
      render(<Saver stored={stepListPickCheck} />)

      await user.clear(card(1).getByLabelText('Count min'))
      await user.type(card(1).getByLabelText('Count min'), 'x')
      await user.click(screen.getByRole('button', { name: 'Save' }))

      expect(card(1).getByText('Use a whole number.')).toBeInTheDocument()
      expect(sent.steps).toBeNull()
    })

    it('lists the bands of the chosen check in its order and re-renders them when the check changes', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListPickCheck} />)
      const triggers = () =>
        within(card(2).getByRole('group', { name: 'Outcomes' }))
          .getAllByRole('button', { name: /^(fail|partial|success|lose|win)$/ })
          .map((button) => button.textContent)

      expect(triggers()).toEqual(['fail', 'partial', 'success'])

      await chooseOption(user, 'Check', 'luck', card(2))

      expect(triggers()).toEqual(['lose', 'win'])
      expect(card(2).getByText('This check declares no difficulty.')).toBeInTheDocument()
      await user.clear(card(2).getByLabelText('Difficulty'))
      expect(card(2).queryByLabelText('Difficulty')).not.toBeInTheDocument()
      expect(card(2).getAllByText('Not a band of this check.')).toHaveLength(2)
    })

    it('shows the difficulty only for a check that declares one, with its range, and guides a value outside it', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListPickCheck} />)

      expect(card(2).getByText(/Default 13, from 9 to 29/)).toBeInTheDocument()
      await user.clear(card(2).getByLabelText('Difficulty'))
      await user.type(card(2).getByLabelText('Difficulty'), '40')
      expect(card(2).getByText('Outside the range of this check: 9 to 29.')).toBeInTheDocument()
      expect(saved(1)).toMatchObject({ difficulty: 40 })

      await user.clear(card(2).getByLabelText('Difficulty'))
      expect(saved(1)).not.toHaveProperty('difficulty')

      await chooseOption(user, 'Difficulty type', 'tracker', card(2))
      await chooseOption(user, 'Difficulty', 'heat', card(2))
      expect(saved(1)).toMatchObject({ difficulty: { tracker: 'heat' } })
    })

    it('writes an outcome per band in band order and offers sheet effects in check outcomes only', async () => {
      const user = userEvent.setup()
      render(<Harness stored={stepListPickCheck} />)

      await user.click(
        within(card(2).getByRole('group', { name: 'Outcomes' })).getByText('partial'),
      )
      await chooseOption(user, 'Next', 'end', inside(2, 'Outcome partial'))

      expect(saved(1)).toMatchObject({
        outcomes: [
          { band: 'fail', next: 'end' },
          { band: 'partial', next: 'end' },
          { band: 'success' },
        ],
      })
      const kinds = (
        await listOptions(user, inside(2, 'Outcome partial').getByLabelText('New effect kind'))
      ).map((option) => option.value)
      expect(kinds).toContain('sheet')
    })

    it('keeps an outcome of a band the check lacks, flagged, until it is removed', async () => {
      const user = userEvent.setup()
      const stored = [
        {
          ...stepListPickCheck[1],
          outcomes: [
            { band: 'ghost', next: 'end' },
            { band: 'fail' },
            { band: 'fail', next: 'end' },
          ],
        },
      ]
      render(<Harness stored={stored} />)

      expect(card(1).getByText('Not a band of this check.')).toBeInTheDocument()
      expect(card(1).getByText('Another outcome has this band.')).toBeInTheDocument()
      expect(saved(0)).toMatchObject({ outcomes: [{ band: 'ghost' }, { band: 'fail' }] })

      await user.click(card(1).getByRole('button', { name: 'Remove outcome 1' }))

      expect(card(1).queryByText('Not a band of this check.')).not.toBeInTheDocument()
      expect(saved(0)).toMatchObject({ outcomes: [{ band: 'fail', next: 'end' }] })
    })

    it('flags a check the game system does not have, and keeps the step editable', async () => {
      const user = userEvent.setup()
      render(<Harness stored={[{ ...stepListPickCheck[1], check: 'gone' }]} />)

      expect(card(1).getByText('Not a check of this game system.')).toBeInTheDocument()
      await user.type(card(1).getByLabelText('Title'), '!')
      expect(saved(0)).toMatchObject({ check: 'gone', title: 'Pick the lock!' })
    })

    it('keeps a pick or check of an unreadable shape read-only and sends it unchanged', async () => {
      const user = userEvent.setup()
      const unreadable = [
        { key: 'p', kind: 'pick', title: 'P', of: 'npc', mode: 'random', count: { min: 2 } },
        { key: 'k', kind: 'check', title: 'K', check: 'skill', outcomes: 'none' },
      ]
      render(<Saver stored={unreadable} />)

      expect(card(1).getByText(/cannot edit yet/)).toBeInTheDocument()
      expect(card(2).getByText(/cannot edit yet/)).toBeInTheDocument()
      await user.click(screen.getByRole('button', { name: 'Save' }))

      expect(sent.steps).toEqual(unreadable)
    })

    it('shows each server message on its field, outcome row or the card', () => {
      render(
        <Harness
          stored={stepListPickCheck}
          errors={errors(
            'sceneTypes[2].play[0].of: bad of',
            'sceneTypes[2].play[0].count.max: bad max',
            'sceneTypes[2].play[0].branches.found.next: bad found next',
            'sceneTypes[2].play[0].branches.none.effects[0].title: bad none title',
            'sceneTypes[2].play[0].tags[0]: bad tag',
            'sceneTypes[2].play[1].check: bad check',
            'sceneTypes[2].play[1].difficulty: bad difficulty',
            'sceneTypes[2].play[1].outcomes[0].next: bad fail next',
            'sceneTypes[2].play[1].outcomes[1].effects[0].field: bad stress',
            'sceneTypes[2].play[1].outcomes[1].band: bad band',
            'sceneTypes[2].play[1].outcomes[7].next: no such outcome',
            'sceneTypes[2].play[1].outcomes: at most 10',
          )}
        />,
      )

      expect(card(1).getByText('bad of')).toBeInTheDocument()
      expect(card(1).getByText('bad max')).toBeInTheDocument()
      expect(inside(1, 'Found').getByText('bad found next')).toBeInTheDocument()
      expect(inside(1, 'None').getByText('bad none title')).toBeInTheDocument()
      expect(card(1).getByText('tags: bad tag')).toBeInTheDocument()
      expect(card(2).getByText('bad check')).toBeInTheDocument()
      expect(card(2).getByText('bad difficulty')).toBeInTheDocument()
      expect(inside(2, 'Outcome fail').getByText('bad fail next')).toBeInTheDocument()
      expect(inside(2, 'Outcome success').getByText('bad stress')).toBeVisible()
      expect(inside(2, 'Outcome success').getByText('band: bad band')).toBeVisible()
      expect(card(2).getByText('next: no such outcome')).toBeInTheDocument()
      expect(card(2).getByText('outcomes: at most 10')).toBeInTheDocument()
    })

    it('lists a difficulty message on the card when the difficulty input is hidden', () => {
      const hidden = [
        { key: 'a', kind: 'check', title: 'A', check: 'nothing' },
        { key: 'b', kind: 'check', title: 'B', check: '' },
        { key: 'c', kind: 'check', title: 'C', check: 'luck' },
      ]
      render(
        <Harness
          stored={hidden}
          errors={errors(
            'sceneTypes[2].play[0].difficulty: bad difficulty a',
            'sceneTypes[2].play[1].difficulty: bad difficulty b',
            'sceneTypes[2].play[2].difficulty: bad difficulty c',
          )}
        />,
      )

      expect(card(1).queryByLabelText('Difficulty')).not.toBeInTheDocument()
      expect(card(1).getByText('difficulty: bad difficulty a')).toBeInTheDocument()
      expect(card(2).getByText('difficulty: bad difficulty b')).toBeInTheDocument()
      expect(card(3).getByText('difficulty: bad difficulty c')).toBeInTheDocument()
    })

    it('lists a message about a field the kind of step does not have on the card', () => {
      render(
        <Harness
          stored={stepListPickCheck}
          errors={errors(
            'sceneTypes[2].play[1].count.min: bad min',
            'sceneTypes[2].play[1].of: bad of',
            'sceneTypes[2].play[0].check: bad check',
          )}
        />,
      )

      expect(card(2).getByText('count.min: bad min')).toBeInTheDocument()
      expect(card(2).getByText('of: bad of')).toBeInTheDocument()
      expect(card(1).getByText('check: bad check')).toBeInTheDocument()
    })

    it('follows an outcome through the removal of another, and goes when it is removed', async () => {
      const user = userEvent.setup()
      const stored = [
        {
          ...stepListPickCheck[1],
          outcomes: [
            { band: 'ghost', next: 'end' },
            { band: 'fail' },
            { band: 'success', next: 'end' },
          ],
        },
      ]
      render(
        <Harness
          stored={stored}
          errors={errors(
            'sceneTypes[2].play[0].outcomes[0].next: ghost problem',
            'sceneTypes[2].play[0].outcomes[2].next: success problem',
          )}
        />,
      )
      expect(inside(1, 'Outcome success').getByText('success problem')).toBeInTheDocument()

      await user.click(card(1).getByRole('button', { name: 'Remove outcome 1' }))

      expect(screen.queryByText('ghost problem')).not.toBeInTheDocument()
      expect(inside(1, 'Outcome success').getByText('success problem')).toBeInTheDocument()
    })

    it('flags a next that goes backward in a pick branch and a check outcome', () => {
      const back = 'Pick a later step: next only goes forward.'
      render(
        <Harness
          stored={[
            { key: 'a', kind: 'character', title: 'A' },
            { ...stepListPickCheck[0], branches: { found: { next: 'a' } } },
            { ...stepListPickCheck[1], outcomes: [{ band: 'fail', next: 'a' }] },
          ]}
        />,
      )

      expect(inside(2, 'Found').getByText(back)).toBeInTheDocument()
      expect(inside(3, 'Outcome fail').getByText(back)).toBeInTheDocument()
    })

    it('adds a pick and a check step', async () => {
      const user = userEvent.setup()
      render(<Harness stored={[]} />)

      for (const kind of ['pick', 'check']) {
        await addStep(user, kind)
      }

      expect(saved(0)).toMatchObject({ key: 'pick-1', kind: 'pick', of: 'npc', mode: 'random' })
      expect(saved(1)).toMatchObject({ key: 'check-1', kind: 'check', check: '' })
    })
  })
})
