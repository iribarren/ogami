import { checkFormulaDiagnostics, checkReads, checkReferences } from './checkFormulaDiagnostics'

const catalog = {
  fields: [
    { key: 'pow', label: 'Power', type: 'number' as const, max: null },
    { key: 'hp', label: 'Hit points', type: 'track' as const, max: 12 },
    { key: 'edge', label: 'Edge', type: 'derived' as const, max: null },
  ],
  trackers: [
    { key: 'heat', label: 'Heat', initial: 3 },
    { key: 'doom', label: 'Doom', initial: null },
  ],
}

const run = (
  formula: string,
  scope: 'modifier' | 'band' = 'band',
  difficulty: { default: number | null } | null = { default: 13 },
) => checkFormulaDiagnostics({ formula, scope, catalog, difficulty })

describe('checkFormulaDiagnostics', () => {
  it('reports a syntax error with the kernel position', () => {
    const { error, result } = run('{pow} $ 2')

    expect(error?.message).toBeTruthy()
    expect(error?.position).toBeGreaterThan(0)
    expect(result).toBeNull()
  })

  it.each([
    ['{nope}', 'unknown field "nope"'],
    ['{pow.max}', 'field "pow" is a number field, only a track field has a max'],
    ['{tracker:gone}', 'unknown tracker "gone"'],
  ])('refuses %s as the sheet does', (formula, reason) => {
    expect(run(formula).error).toEqual({ message: `${formula}: ${reason}`, position: 1 })
  })

  it('reports the first refused reference, with its position', () => {
    expect(run('{pow} + {nope} + {gone}').error).toEqual({
      message: '{nope}: unknown field "nope"',
      position: 9,
    })
  })

  it('lets a band bound of a check with a difficulty read {check:difficulty}', () => {
    expect(run('{check:difficulty}+4')).toEqual({ error: null, result: { value: 17 } })
  })

  it('refuses {check:difficulty} in a band bound of a check without a difficulty', () => {
    expect(run('{check:difficulty}', 'band', null).error?.message).toBe(
      '{check:difficulty}: this check declares no difficulty',
    )
  })

  it('refuses any {check:…} in the modifier, with or without a difficulty', () => {
    expect(run('{check:difficulty}', 'modifier').error?.message).toContain(
      'the modifier cannot read a check reference',
    )
    expect(run('1', 'modifier', null).error).toBeNull()
  })

  it('refuses a check value other than difficulty', () => {
    expect(run('{check:luck}').error?.message).toBe(
      '{check:luck}: unknown check value "luck", only {check:difficulty} exists',
    )
  })

  it('reads a number field at the default the test roll starts it at, else 0', () => {
    const defaults = { pow: 4 }
    const read = (formula: string, withDefaults?: Record<string, number>) =>
      checkFormulaDiagnostics({
        formula,
        scope: 'modifier',
        catalog: { ...catalog, defaults: withDefaults },
        difficulty: null,
      })

    expect(read('{pow}', defaults).result).toEqual({ value: 4 })
    expect(read('{pow}').result).toEqual({ value: 0 })
    expect(read('{hp}', defaults).result).toEqual({ value: 0 })
  })

  it('has no cycle check: a derived field reads fine, with no value', () => {
    expect(run('{edge}')).toEqual({ error: null, result: { reason: 'incomplete value' } })
  })

  it('computes a value over the sample values', () => {
    expect(run('{pow} + {hp} + {hp.max} + {tracker:heat}').result).toEqual({ value: 15 })
  })

  it('has no value for a tracker without an initial value, or a difficulty without a default', () => {
    expect(run('{tracker:doom}').result).toEqual({ reason: 'incomplete value' })
    expect(run('{check:difficulty}', 'band', { default: null }).result).toEqual({
      reason: 'incomplete value',
    })
  })

  it('says why an evaluation fails', () => {
    expect(run('1 / {pow}').result).toEqual({ reason: 'division by zero' })
  })
})

describe('checkReferences', () => {
  it('lists the fields, track maxima and trackers', () => {
    expect(checkReferences(catalog, 'modifier', true).map((option) => option.reference)).toEqual([
      'pow',
      'hp',
      'hp.max',
      'edge',
      'tracker:heat',
      'tracker:doom',
    ])
  })

  it('adds {check:difficulty} only to a band of a check with a difficulty', () => {
    const has = (scope: 'modifier' | 'band', difficulty: boolean) =>
      checkReferences(catalog, scope, difficulty).some(
        (option) => option.reference === 'check:difficulty',
      )

    expect(has('band', true)).toBe(true)
    expect(has('band', false)).toBe(false)
    expect(has('modifier', true)).toBe(false)
  })
})

describe('checkReads', () => {
  it('names what a formula reads by label, once each, in the order it reads them', () => {
    expect(checkReads('{pow} + {tracker:heat} + {pow} + {hp.max}', catalog)).toEqual([
      'Power',
      'Heat',
      'Hit points max',
    ])
  })

  it('reads the key of a reference the catalog does not know, and nothing from a broken formula', () => {
    expect(checkReads('{gone}', catalog)).toEqual(['gone'])
    expect(checkReads('{pow} $', catalog)).toEqual([])
    expect(checkReads('3', catalog)).toEqual([])
  })
})
