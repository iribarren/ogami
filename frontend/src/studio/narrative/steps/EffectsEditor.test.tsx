import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useEffect, useState } from 'react'

import { EffectsEditor } from './EffectsEditor'
import { effectKinds, effectsToDrafts, effectsToStored, type EffectKind } from './effectDraft'
import { effectPickers, every } from './effectFixtures'
import type { StepContext } from './stepContext'
import { mapStepError, type StepError } from './stepError'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { replaceText, typeText } from '@/test/typeText'

/** The editor as a step card holds it: the drafts in state, the written effects exposed for checks. */
const latest: { written: unknown[] } = { written: [] }

function Harness({
  stored = [],
  allowSheet = false,
  errors = [],
  context = effectPickers,
  kinds,
  allowPick,
}: {
  stored?: unknown[]
  allowSheet?: boolean
  errors?: StepError[]
  context?: StepContext
  kinds?: readonly EffectKind[]
  allowPick?: boolean
}) {
  const [effects, setEffects] = useState(() => effectsToDrafts(stored))
  useEffect(() => {
    latest.written = effectsToStored(effects, 'effects', {})
  })
  return (
    <EffectsEditor
      label="Effects"
      effects={effects}
      context={context}
      allowSheet={allowSheet}
      kinds={kinds}
      allowPick={allowPick}
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
      'createPlace',
      async (user: ReturnType<typeof userEvent.setup>) => {
        await typeText(user, card(1).getByLabelText('Name'), 'The keep')
        await user.click(card(1).getByRole('checkbox', { name: 'Gang' }))
      },
      { kind: 'createPlace', name: 'The keep', tags: ['gang'] },
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

  it('closes the picked Thread unless a thread fact slot is picked, and lists only thread slots', async () => {
    const user = userEvent.setup()
    const context = {
      ...effectPickers,
      factSlots: [
        ...effectPickers.factSlots,
        { key: 'rumour', name: 'Rumour', type: 'thread' },
        { key: 'lair', name: 'Lair', type: 'place' },
      ],
    }
    render(<Harness context={context} />)

    await add(user, 'closeThread')
    expect(latest.written).toEqual([{ kind: 'closeThread' }])
    const options = await listOptions(user, card(1).getByLabelText('Thread fact slot'))
    expect(options.map((option) => option.value)).toEqual(['', 'rumour'])
    await chooseOption(user, 'Thread fact slot', 'rumour', card(1))

    expect(latest.written).toEqual([{ kind: 'closeThread', fact: 'rumour' }])
  })

  it('shows a closeThread fact that is no thread slot and lets the server refuse it', () => {
    render(<Harness stored={[{ kind: 'closeThread', fact: 'target' }]} />)

    expect(card(1).getByLabelText('Thread fact slot')).toHaveTextContent('target')
    expect(latest.written).toEqual([{ kind: 'closeThread', fact: 'target' }])
  })

  it('keeps a closeThread with a null fact as stored', () => {
    render(<Harness stored={[{ kind: 'closeThread', fact: null }]} />)

    expect(latest.written).toEqual([{ kind: 'closeThread', fact: null }])
  })

  // jsdom measures no height: the test checks the options offered and the panel's height cap.
  it('offers every effect kind, in a panel tall enough to show the eleventh without scrolling', async () => {
    const user = userEvent.setup()
    render(<Harness allowSheet />)

    await user.click(screen.getByLabelText('New effect kind'))
    const list = await screen.findByRole('listbox')

    expect(
      within(list)
        .getAllByRole('option')
        .map((option) => option.getAttribute('data-option-value')),
    ).toEqual([...effectKinds])
    // Eleven rows of about 33px and the padding fit 24rem; the default 20rem hid the last row.
    expect(document.querySelector('[data-slot="select-content"]')).toHaveClass(
      'max-h-[min(24rem,var(--radix-select-content-available-height))]',
    )
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

describe('the effects editor with conditions fields', () => {
  const conditions: StepContext = {
    ...effectPickers,
    sheetFields: [
      ...effectPickers.sheetFields,
      {
        key: 'physical',
        name: 'Physical',
        options: [
          { key: 'hurt', name: 'Hurt' },
          { key: 'exhausted', name: 'Exhausted' },
        ],
      },
    ],
  }

  const ops = (user: ReturnType<typeof userEvent.setup>) =>
    listOptions(user, card(1).getByRole('combobox', { name: 'Operation' }))

  it('offers mark and clear on a conditions field, never add, subtract or set', async () => {
    const user = userEvent.setup()
    render(<Harness allowSheet context={conditions} />)
    await add(user, 'sheet')

    expect((await ops(user)).map((option) => option.value)).toEqual(['add', 'subtract', 'set'])
    await chooseOption(user, 'Sheet field', 'physical', card(1))

    expect((await ops(user)).map((option) => option.value)).toEqual(['mark', 'clear'])
    expect(latest.written).toEqual([{ kind: 'sheet', field: 'physical', op: 'mark' }])
    await chooseOption(user, 'Sheet field', 'hands', card(1))
    expect((await ops(user)).map((option) => option.value)).toEqual(['add', 'subtract', 'set'])
    expect(latest.written).toEqual([{ kind: 'sheet', field: 'hands', op: 'add', value: 1 }])
  })

  it('picks the option from the field’s options, and a clear may take them all', async () => {
    const user = userEvent.setup()
    render(<Harness allowSheet context={conditions} />)
    await add(user, 'sheet')
    await chooseOption(user, 'Sheet field', 'physical', card(1))

    const options = await listOptions(user, card(1).getByRole('combobox', { name: 'Option' }))
    expect(options).toEqual([
      { label: 'Pick an option', value: '' },
      { label: 'Hurt', value: 'hurt' },
      { label: 'Exhausted', value: 'exhausted' },
    ])
    await chooseOption(user, 'Option', 'Hurt', card(1))
    expect(latest.written).toEqual([
      { kind: 'sheet', field: 'physical', op: 'mark', value: 'hurt' },
    ])

    await chooseOption(user, 'Operation', 'clear', card(1))
    expect(latest.written).toEqual([
      { kind: 'sheet', field: 'physical', op: 'clear', value: 'hurt' },
    ])
    await chooseOption(user, 'Option', 'All options', card(1))
    expect(latest.written).toEqual([{ kind: 'sheet', field: 'physical', op: 'clear' }])
  })

  it('lets the player pick on a mark only where allowPick is set', async () => {
    const user = userEvent.setup()
    const { unmount } = render(
      <Harness
        allowSheet
        context={conditions}
        stored={[{ kind: 'sheet', field: 'physical', op: 'mark' }]}
      />,
    )
    expect(
      (await listOptions(user, card(1).getByRole('combobox', { name: 'Option' }))).map(
        (option) => option.label,
      ),
    ).not.toContain('The player picks')
    unmount()

    render(
      <Harness
        allowSheet
        allowPick
        context={conditions}
        stored={[{ kind: 'sheet', field: 'physical', op: 'mark' }]}
      />,
    )
    expect(
      (await listOptions(user, card(1).getByRole('combobox', { name: 'Option' }))).map(
        (option) => option.label,
      ),
    ).toContain('The player picks')
    expect(latest.written).toEqual([{ kind: 'sheet', field: 'physical', op: 'mark' }])
  })

  it('keeps a stored option the field no longer has, and a number field’s effect as it was', () => {
    const stored = [
      { kind: 'sheet', field: 'physical', op: 'mark', value: 'gone' },
      { kind: 'sheet', field: 'hands', op: 'add', value: 2 },
    ]
    render(<Harness allowSheet context={conditions} stored={stored} />)

    expect(latest.written).toEqual(stored)
    expect(card(1).getByRole('combobox', { name: 'Option' })).toHaveTextContent('gone')
    expect(card(2).queryByRole('combobox', { name: 'Option' })).not.toBeInTheDocument()
  })

  it('offers only the kinds it is given', async () => {
    const user = userEvent.setup()
    render(<Harness allowSheet kinds={['sheet', 'tracker']} context={conditions} />)

    const kinds = await listOptions(user, screen.getByLabelText('New effect kind'))

    expect(kinds.map((option) => option.value)).toEqual(['tracker', 'sheet'])
  })
})
