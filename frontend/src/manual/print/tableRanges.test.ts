import { tableRanges } from './tableRanges'

describe('tableRanges', () => {
  it('numbers an unweighted table on the smallest die that covers it', () => {
    expect(tableRanges([1, 1, 1, 1, 1, 1, 1, 1])).toEqual({
      die: 8,
      rollAgainAbove: null,
      ranges: ['1', '2', '3', '4', '5', '6', '7', '8'],
    })
  })

  it('gives an entry as many numbers as its weight, and rolls again above the total', () => {
    // Road Risks and Ship trouble: one entry of weight 3 and six of weight 1.
    expect(tableRanges([3, 1, 1, 1, 1, 1, 1])).toEqual({
      die: 10,
      rollAgainAbove: 9,
      ranges: ['1-3', '4', '5', '6', '7', '8', '9'],
    })
  })

  it('picks each standard die at its limit', () => {
    const dice = [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 20, 21, 100].map(
      (total) => tableRanges([total]).die,
    )

    expect(dice).toEqual([4, 6, 6, 8, 8, 10, 10, 12, 12, 20, 20, 100, 100])
  })

  it('has nothing to roll again when the total is a die', () => {
    expect(tableRanges([10, 10]).rollAgainAbove).toBeNull()
  })

  it.each([
    ['an empty table', []],
    ['a total above 100', [60, 41]],
    ['a zero weight', [1, 0]],
    ['a fractional weight', [1.5]],
  ])('throws on %s', (_name, weights) => {
    expect(() => tableRanges(weights)).toThrow()
  })
})
