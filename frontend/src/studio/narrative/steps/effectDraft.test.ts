import {
  effectGuides,
  effectsToDrafts,
  effectsToStored,
  MAX_EFFECTS,
  newEffect,
  type EffectDraft,
} from './effectDraft'
import { every } from './effectFixtures'

function round(stored: unknown[]) {
  const errors = {}
  const effects = effectsToStored(effectsToDrafts(stored), 'effects', errors)
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
      'A sheet effect is only allowed in a character step and in check outcomes.',
    ])
    expect(effectGuides(effects, true)).toEqual([undefined, undefined])
    expect(MAX_EFFECTS).toBe(10)
  })
})
