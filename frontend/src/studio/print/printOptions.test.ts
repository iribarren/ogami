import { readRelease } from '@/shared/print/release'
import { miniRelease } from '@/shared/print/testRelease'

import { parsePrintSearch, resolvePrintOptions } from './printOptions'

const release = readRelease(miniRelease())

describe('parsePrintSearch', () => {
  it('keeps what the address says as text, and leaves out what it does not say', () => {
    expect(parsePrintSearch({})).toEqual({
      flow: undefined,
      paper: undefined,
      bleed: undefined,
      decks: undefined,
    })
    // The router parses the query string as JSON: a boolean, a number and a list arrive typed.
    expect(
      parsePrintSearch({ flow: 7, paper: 'a5', bleed: true, decks: ['luck', 'deck'] }),
    ).toEqual({ flow: '7', paper: 'a5', bleed: 'true', decks: ['luck', 'deck'] })
  })

  it('reads one deck table given as a single value, and keeps a value of another shape as text', () => {
    expect(parsePrintSearch({ decks: 'luck' }).decks).toEqual(['luck'])
    expect(parsePrintSearch({ paper: { size: 'a4' } }).paper).toBe('{"size":"a4"}')
    expect(parsePrintSearch({ flow: null }).flow).toBeUndefined()
  })
})

describe('resolvePrintOptions', () => {
  it('defaults paper and bleed only when they are absent', () => {
    expect(resolvePrintOptions({ flow: 'mini' }, release)).toEqual({
      ok: true,
      options: { flow: 'mini', paper: 'a4', bleed: false, decks: [] },
    })
  })

  it('takes every option it is given', () => {
    expect(
      resolvePrintOptions(
        { flow: 'mini', paper: 'a5', bleed: 'true', decks: ['luck', 'luck'] },
        release,
      ),
    ).toEqual({ ok: true, options: { flow: 'mini', paper: 'a5', bleed: true, decks: ['luck'] } })
  })

  it.each([
    ['no flow', {}, /Choose a flow/],
    ['an empty flow', { flow: '' }, /Choose a flow/],
    ['an unknown flow', { flow: 'nope' }, /no flow "nope"/],
  ])('lists the flows of the release for %s', (_name, search, problem) => {
    const resolved = resolvePrintOptions(search, release)

    expect(resolved).toMatchObject({ ok: false, flows: [{ key: 'mini', name: 'Mini' }] })
    expect(resolved.ok ? [] : resolved.problems.join(' ')).toMatch(problem)
  })

  it.each([
    ['an unknown paper', { paper: 'letter' }, /Unknown paper "letter": use a4 or a5/],
    ['a paper in capitals', { paper: 'A4' }, /Unknown paper "A4"/],
    ['an unknown bleed value', { bleed: 'yes' }, /Unknown bleed value "yes"/],
    [
      'an unknown deck table',
      { decks: ['luck', 'missing'] },
      /no oracle table "missing".*deck, luck/,
    ],
  ])('refuses %s instead of defaulting it', (_name, extra, problem) => {
    const resolved = resolvePrintOptions({ flow: 'mini', ...extra }, release)

    expect(resolved.ok).toBe(false)
    expect(resolved.ok ? [] : resolved.problems.join(' ')).toMatch(problem)
    expect(resolved.ok ? [] : resolved.flows).toEqual([])
  })

  it('reports every problem at once', () => {
    const resolved = resolvePrintOptions({ paper: 'x', bleed: 'y' }, release)

    expect(resolved.ok ? [] : resolved.problems).toHaveLength(3)
  })
})
