import { vi } from 'vitest'
import { readCheck, writeCheck, type StoredCheck } from './checkDraft'

// The effects editor only keys its refusals `<path>.<n>.<word>` today; this makes it say
// something else, as a future refusal might.
vi.mock('../narrative/steps/effectDraft', async (importOriginal) => {
  const original = await importOriginal<typeof import('../narrative/steps/effectDraft')>()
  return {
    ...original,
    effectsToStored: (...args: Parameters<typeof original.effectsToStored>) => {
      const [, , errors] = args
      errors['push.effects.0.value'] = 'Use a whole number.'
      errors['push.effects.somewhere'] = 'Something else is wrong.'
      return original.effectsToStored(...args)
    },
  }
})

const pooled: StoredCheck = {
  key: 'sneak',
  label: 'Sneak',
  pool: { count: 4, sides: 6, successAt: 5 },
  push: { label: 'Pay', effects: [{ kind: 'endPhase' }] },
  bands: [{ key: 'win', label: 'Win' }],
}

describe('a push whose effects are refused', () => {
  it('keeps every refusal and does not send, even one that names no effect field', () => {
    const draft = readCheck(pooled)
    if (draft === null) {
      throw new Error('Expected a readable check.')
    }

    const result = writeCheck(draft)

    expect(result).not.toHaveProperty('check')
    expect(result).toHaveProperty('errors.pushEffects', [
      { index: null, band: null, effect: 0, field: 'value', message: 'Use a whole number.' },
      {
        index: null,
        band: null,
        effect: null,
        field: 'effects',
        message: 'Something else is wrong.',
      },
    ])
  })
})
