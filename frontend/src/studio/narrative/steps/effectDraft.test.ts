import {
  effectGuides,
  effectsToDrafts,
  effectsToStored,
  MAX_EFFECTS,
  newEffect,
  sheetWithField,
  sheetWithOp,
  type EffectDraft,
  type SheetEffect,
} from './effectDraft'
import { every } from './effectFixtures'

function round(stored: unknown[], allowPick = true) {
  const errors = {}
  const effects = effectsToStored(effectsToDrafts(stored), 'effects', errors, allowPick)
  return { effects, errors }
}

describe('an effect draft', () => {
  it('gives every kind back as it was stored, unknown fields included', () => {
    expect(round(every)).toEqual({ effects: every, errors: {} })
    expect(effectsToDrafts(every).map((effect) => effect.kind)).toEqual(
      every.map((effect) => effect.kind),
    )
  })

  it('keeps unknown fields while a known one is edited', () => {
    const [draft] = effectsToDrafts([{ kind: 'sceneTitle', title: 'Dawn', mood: 'calm' }])
    if (draft?.kind !== 'sceneTitle') {
      throw new Error('Expected a sceneTitle effect.')
    }

    expect(effectsToStored([{ ...draft, title: 'Dusk' }], 'effects', {})).toEqual([
      { kind: 'sceneTitle', title: 'Dusk', mood: 'calm' },
    ])
  })

  it('keeps an empty optional the stored effect carries, and omits it in a new one', () => {
    const stored = [
      { kind: 'createNpc', name: '', agenda: '', tags: [] },
      { kind: 'createThread', name: 'The debt', tags: [] },
      { kind: 'fillFact', slot: 'client', text: '' },
    ]

    expect(round(stored).effects).toEqual(stored)

    const [npc] = effectsToDrafts(stored)
    if (npc?.kind !== 'createNpc') {
      throw new Error('Expected a createNpc effect.')
    }
    expect(effectsToStored([{ ...npc, tags: ['gang'] }], 'effects', {})).toEqual([
      { kind: 'createNpc', name: '', agenda: '', tags: ['gang'] },
    ])
    expect(effectsToStored([newEffect('createNpc')], 'effects', {})).toEqual([
      { kind: 'createNpc' },
    ])
  })

  it('omits empty optionals like the canonical form', () => {
    const blank: EffectDraft[] = [
      { kind: 'createNpc', name: '', agenda: '', tags: [], extra: {} },
      { kind: 'createThread', name: '', tags: [], extra: {} },
      { kind: 'fillFact', slot: 'client', text: '', extra: {} },
    ]

    expect(effectsToStored(blank, 'effects', {})).toEqual([
      { kind: 'createNpc' },
      { kind: 'createThread' },
      { kind: 'fillFact', slot: 'client' },
    ])
  })

  it.each([
    ['an unknown kind', { kind: 'teleport', to: 'moon' }],
    ['not an object', 'endPhase'],
    [
      'a tracker op the publisher does not know',
      { kind: 'tracker', tracker: 'heat', op: 'mul', value: 2 },
    ],
    ['a value of no known form', { kind: 'tracker', tracker: 'heat', op: 'add', value: 1.5 }],
    [
      'a dice value on a tracker',
      { kind: 'tracker', tracker: 'heat', op: 'add', value: { dice: '1d6' } },
    ],
    [
      'a tracker value on the sheet',
      { kind: 'sheet', field: 'hands', op: 'add', value: { tracker: 'heat' } },
    ],
    ['a field of the wrong type', { kind: 'createNpc', name: 4 }],
    ['tags that are not keys', { kind: 'createThread', tags: 'gang' }],
    ['a missing required field', { kind: 'nextScene' }],
  ])('keeps %s exactly as stored, read-only', (_, stored) => {
    const drafts = effectsToDrafts([stored])

    expect(drafts).toEqual([{ kind: 'stored', stored }])
    expect(effectsToStored(drafts, 'effects', {})).toEqual([stored])
  })

  it('says which values are not whole numbers and what to do about them', () => {
    const errors: Record<string, string> = {}
    const effects: EffectDraft[] = [
      newEffect('endPhase'),
      {
        kind: 'tracker',
        tracker: 'heat',
        op: 'add',
        value: { form: 'integer', text: '1x' },
        extra: {},
      },
    ]

    effectsToStored(effects, 'steps.0.effects', errors)

    expect(errors).toEqual({ 'steps.0.effects.1.value': 'Use a whole number.' })
  })

  it.each([
    ['tracker', { kind: 'tracker', tracker: '', op: 'add', value: { form: 'integer', text: '1' } }],
    ['nextScene', { kind: 'nextScene', sceneType: '' }],
    ['createNpc', { kind: 'createNpc', name: '', agenda: '', tags: [] }],
    ['sheet', { kind: 'sheet', field: '', op: 'add', value: { form: 'integer', text: '1' } }],
  ] as const)('starts a new %s effect empty, to be picked', (kind, expected) => {
    expect(newEffect(kind)).toMatchObject(expected)
  })

  it('guides: a sheet effect only where the context allows it, and the limit', () => {
    const effects = effectsToDrafts([
      { kind: 'endPhase' },
      { kind: 'sheet', field: 'hands', op: 'add', value: 1 },
    ])

    expect(effectGuides(effects, false)).toEqual([
      undefined,
      'A sheet effect is only allowed in a character step, a prompt step (not in a Scene Type setup), a choice option (not the skip option) and check outcomes.',
    ])
    expect(effectGuides(effects, true)).toEqual([undefined, undefined])
    expect(MAX_EFFECTS).toBe(10)
  })
})

describe('a sheet effect on a conditions field', () => {
  const sheet = (effect: unknown): SheetEffect => {
    const [draft] = effectsToDrafts([effect])
    if (draft?.kind !== 'sheet') {
      throw new Error('Expected a sheet effect.')
    }
    return draft
  }

  it.each<[string, { kind: string; field: string; op: string; value?: string; note?: string }]>([
    ['a mark of an option', { kind: 'sheet', field: 'physical', op: 'mark', value: 'hurt' }],
    ['a mark with no option', { kind: 'sheet', field: 'physical', op: 'mark' }],
    ['a clear of an option', { kind: 'sheet', field: 'physical', op: 'clear', value: 'hurt' }],
    ['a clear of all', { kind: 'sheet', field: 'physical', op: 'clear', note: 'x' }],
  ])('gives %s back as it was stored', (_name, stored) => {
    expect(round([stored])).toEqual({ effects: [stored], errors: {} })
    expect(sheet(stored)).toMatchObject({ op: stored.op, option: stored.value ?? '' })
  })

  it('reads an explicit null option as none, and keeps an option that is not a key as stored', () => {
    expect(sheet({ kind: 'sheet', field: 'physical', op: 'mark', value: null })).toMatchObject({
      option: '',
    })
    expect(effectsToDrafts([{ kind: 'sheet', field: 'physical', op: 'mark', value: 3 }])).toEqual([
      { kind: 'stored', stored: { kind: 'sheet', field: 'physical', op: 'mark', value: 3 } },
    ])
  })

  it('switches between the ops of the two families, starting the value of the one it goes to', () => {
    const number = sheet({ kind: 'sheet', field: 'agility', op: 'subtract', value: 2 })

    const marked = sheetWithOp(number, 'mark')
    expect(marked).toMatchObject({ field: 'agility', op: 'mark', option: '' })
    expect(marked).not.toHaveProperty('value')
    expect(sheetWithOp(sheetWithOp(marked, 'clear'), 'clear')).toMatchObject({ op: 'clear' })
    expect(sheetWithOp(marked, 'add')).toMatchObject({
      op: 'add',
      value: { form: 'integer', text: '1' },
    })
    expect(sheetWithOp(number, 'set')).toMatchObject({
      op: 'set',
      value: { form: 'integer', text: '2' },
    })
  })

  it('follows the field: a conditions field takes mark or clear and forgets the old option', () => {
    const number = sheet({ kind: 'sheet', field: 'agility', op: 'add', value: 1 })
    const onConditions = sheetWithField(number, 'physical', true)
    expect(onConditions).toMatchObject({ field: 'physical', op: 'mark', option: '' })

    const picked = { ...onConditions, op: 'clear' as const, option: 'hurt' }
    expect(sheetWithField(picked, 'mental', true)).toMatchObject({
      field: 'mental',
      op: 'clear',
      option: '',
    })
    expect(sheetWithField(picked, 'agility', false)).toMatchObject({
      field: 'agility',
      op: 'add',
      value: { form: 'integer', text: '1' },
    })
    expect(sheetWithField(number, 'stress', false)).toMatchObject({
      field: 'stress',
      op: 'add',
      value: { form: 'integer', text: '1' },
    })
  })

  it('writes an option only when there is one', () => {
    const mark = sheet({ kind: 'sheet', field: 'physical', op: 'mark' })

    expect(effectsToStored([mark], 'effects', {}, true)).toEqual([
      { kind: 'sheet', field: 'physical', op: 'mark' },
    ])
    const named = sheet({ kind: 'sheet', field: 'physical', op: 'mark', value: 'hurt' })
    expect(effectsToStored([named], 'effects', {})).toEqual([
      { kind: 'sheet', field: 'physical', op: 'mark', value: 'hurt' },
    ])
  })

  it('refuses a mark with no option unless the player may pick, keyed like a publisher path', () => {
    const mark = sheet({ kind: 'sheet', field: 'physical', op: 'mark' })

    const errors: Record<string, string> = {}
    effectsToStored([mark], 'steps.0.effects', errors)
    expect(errors).toEqual({ 'steps.0.effects.0.value': 'Pick the option to mark.' })

    const allowed: Record<string, string> = {}
    effectsToStored([mark], 'steps.0.effects', allowed, true)
    expect(allowed).toEqual({})
  })

  it('never refuses a clear of all, or a mark that names its option', () => {
    expect(round([{ kind: 'sheet', field: 'physical', op: 'clear' }], false).errors).toEqual({})
    expect(
      round([{ kind: 'sheet', field: 'physical', op: 'mark', value: 'hurt' }], false).errors,
    ).toEqual({})
  })
})
