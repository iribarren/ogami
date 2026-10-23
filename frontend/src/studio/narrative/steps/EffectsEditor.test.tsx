import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useEffect, useState } from 'react'

import { EffectsEditor } from './EffectsEditor'
import { effectsToDrafts, effectsToStored } from './effectDraft'
import { effectPickers, every } from './effectFixtures'
import { mapStepError, type StepError } from './stepError'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { replaceText, typeText } from '@/test/typeText'

/** The editor as a step card holds it: the drafts in state, the written effects exposed for checks. */
const latest: { written: unknown[] } = { written: [] }

function Harness({
  stored = [],
  allowSheet = false,
  errors = [],
}: {
  stored?: unknown[]
  allowSheet?: boolean
  errors?: StepError[]
}) {
  const [effects, setEffects] = useState(() => effectsToDrafts(stored))
  useEffect(() => {
    latest.written = effectsToStored(effects, 'effects', {})
  })
  return (
    <EffectsEditor
      label="Effects"
      effects={effects}
      context={effectPickers}
      allowSheet={allowSheet}
      errors={errors}
      onChange={setEffects}
    />
  )
}

const card = (number: number) =>
  within(screen.getByRole('group', { name: `Effect ${String(number)}` }))

async function add(user: ReturnType<typeof userEvent.setup>, kind: string) {
  await chooseOption(user, 'New effect kind', kind)
  await user.click(screen.getByRole('button', { name: 'Add effect' }))
}

describe('the effects editor', () => {
  it('gives an untouched list back exactly as it was stored, every kind and unknown field included', () => {
    render(<Harness stored={every.slice(0, 10)} allowSheet />)

    expect(latest.written).toEqual(every.slice(0, 10))
    expect(screen.getAllByRole('group', { name: /^Effect \d+$/ })).toHaveLength(10)
  })

  it.each([
    [
      'tracker',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await chooseOption(user, 'Tracker', 'heat', card(1))
        await chooseOption(user, 'Operation', 'set', card(1))
        await chooseOption(user, 'Value type', 'tracker', card(1))
        await chooseOption(user, 'Value', 'alarm', card(1))
      },
      { kind: 'tracker', tracker: 'heat', op: 'set', value: { tracker: 'alarm' } },
    ],
    [
      'tracker',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await chooseOption(user, 'Tracker', 'alarm', card(1))
        await replaceText(user, card(1).getByLabelText('Value'), '-3')
      },
      { kind: 'tracker', tracker: 'alarm', op: 'add', value: -3 },
    ],
    [
      'tracker',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await chooseOption(user, 'Value type', 'formula', card(1))
        await typeText(user, card(1).getByLabelText('Value'), 'heat + 1')
      },
      { kind: 'tracker', tracker: '', op: 'add', value: { formula: 'heat + 1' } },
    ],
    [
      'nextScene',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await chooseOption(user, 'Scene Type', 'chase', card(1))
      },
      { kind: 'nextScene', sceneType: 'chase' },
    ],
    [
      'switchSceneType',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await chooseOption(user, 'Scene Type', 'hideout', card(1))
      },
      { kind: 'switchSceneType', sceneType: 'hideout' },
    ],
    ['endPhase', () => undefined, { kind: 'endPhase' }],
    ['closeThread', () => undefined, { kind: 'closeThread' }],
    [
      'sceneTitle',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await typeText(user, card(1).getByLabelText('Title'), 'Dawn')
      },
      { kind: 'sceneTitle', title: 'Dawn' },
    ],
    [
      'createNpc',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await typeText(user, card(1).getByLabelText('Name'), 'Vera')
        await typeText(user, card(1).getByLabelText('Agenda'), 'Wants out')
        await user.click(card(1).getByRole('checkbox', { name: 'Gang' }))
      },
      { kind: 'createNpc', name: 'Vera', agenda: 'Wants out', tags: ['gang'] },
    ],
    [
      'createThread',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await typeText(user, card(1).getByLabelText('Name'), 'The debt')
      },
      { kind: 'createThread', name: 'The debt' },
    ],
    [
      'fillFact',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await chooseOption(user, 'Fact slot', 'client', card(1))
        await typeText(user, card(1).getByLabelText('Text'), 'A fence')
      },
      { kind: 'fillFact', slot: 'client', text: 'A fence' },
    ],
    [
      'sheet',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await chooseOption(user, 'Sheet field', 'hands', card(1))
        await chooseOption(user, 'Operation', 'subtract', card(1))
        await chooseOption(user, 'Value type', 'dice', card(1))
        await typeText(user, card(1).getByLabelText('Value'), '1d4')
      },
      { kind: 'sheet', field: 'hands', op: 'subtract', value: { dice: '1d4' } },
    ],
  ])('adds a %s effect and saves what was picked', async (kind, fill, expected) => {
    const user = userEvent.setup()
    render(<Harness allowSheet />)

    await add(user, kind)
    await fill(user)

    expect(latest.written).toEqual([expected])
  })

  it('offers a text only for a text fact slot', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    await add(user, 'fillFact')
    expect(card(1).queryByLabelText('Text')).not.toBeInTheDocument()
    await chooseOption(user, 'Fact slot', 'target', card(1))

    expect(card(1).queryByLabelText('Text')).not.toBeInTheDocument()
    expect(latest.written).toEqual([{ kind: 'fillFact', slot: 'target' }])
  })

  it('offers the sheet kind only where it is allowed, and keeps one stored elsewhere with a note', async () => {
    const user = userEvent.setup()
    const sheet = { kind: 'sheet', field: 'hands', op: 'add', value: 1 }
    const { unmount } = render(<Harness stored={[sheet]} />)
    const kinds = await listOptions(user, screen.getByLabelText('New effect kind'))

    expect(kinds.map((option) => option.value)).not.toContain('sheet')
    expect(card(1).getByText(/only allowed in a character step/)).toBeInTheDocument()
    expect(latest.written).toEqual([sheet])
    unmount()

    render(<Harness stored={[sheet]} allowSheet />)
    expect(screen.queryByText(/only allowed in a character step/)).not.toBeInTheDocument()
    expect(
      (await listOptions(user, screen.getByLabelText('New effect kind'))).map(
        (option) => option.value,
      ),
    ).toContain('sheet')
  })

  it('moves and deletes effects', async () => {
    const user = userEvent.setup()
    render(<Harness stored={every.slice(3, 6)} />)

    await user.click(screen.getByRole('button', { name: 'Move effect 3 up' }))
    expect(latest.written).toEqual([every[3], every[5], every[4]])
    expect(screen.getByRole('button', { name: 'Move effect 1 up' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Delete effect 1' }))

    expect(latest.written).toEqual([every[5], every[4]])
  })

  it('stops at ten effects and says why, until one is deleted', async () => {
    const user = userEvent.setup()
    render(<Harness stored={every.slice(0, 9)} />)

    await add(user, 'endPhase')
    expect(screen.getByRole('button', { name: 'Add effect' })).toBeDisabled()
    expect(screen.getByText('At most 10 effects in a list.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Delete effect 1' }))

    expect(screen.getByRole('button', { name: 'Add effect' })).toBeEnabled()
  })

  it('keeps an effect of an unknown kind or shape read-only, and still moves and deletes it', async () => {
    const user = userEvent.setup()
    const unknown = { kind: 'teleport', to: 'moon' }
    const odd = { kind: 'tracker', tracker: 'heat', op: 'mul', value: 2 }
    render(<Harness stored={[unknown, odd, 'nope']} />)

    expect(card(1).getByText('teleport')).toBeInTheDocument()
    expect(card(2).getByText('tracker')).toBeInTheDocument()
    expect(card(3).getByText('unknown')).toBeInTheDocument()
    expect(card(1).getAllByText(/cannot edit/)).toHaveLength(1)
    expect(card(1).queryByRole('combobox')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Move effect 1 down' }))
    await user.click(screen.getByRole('button', { name: 'Delete effect 3' }))

    expect(latest.written).toEqual([odd, unknown])
  })

  it('shows a server error on its effect and field, and the list-level ones on the list', () => {
    const errors = [
      'steps[0].effects[1].value: must be an integer',
      'steps[0].effects[1].mystery: odd',
      'steps[0].effects[0]: unknown kind',
      'steps[0].effects: at most 10 effects',
      'steps[0].effects[7].value: gone',
    ].map((text) => mapStepError('steps', text))
    render(
      <Harness
        stored={[
          { kind: 'endPhase' },
          { kind: 'tracker', tracker: 'heat', op: 'add', value: { formula: 'x' } },
        ]}
        errors={errors}
      />,
    )

    expect(card(2).getByText('must be an integer')).toBeInTheDocument()
    expect(card(2).getByRole('alert')).toHaveTextContent('mystery: odd')
    expect(card(1).getByRole('alert')).toHaveTextContent('unknown kind')
    expect(screen.getByText('at most 10 effects')).toBeInTheDocument()
    expect(screen.getByText('gone')).toBeInTheDocument()
  })
})
