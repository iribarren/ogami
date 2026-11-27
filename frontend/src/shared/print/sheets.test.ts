import { mirrorRows, paginate } from './sheets'

describe('sheets', () => {
  it('fills a sheet row by row and pads the last one', () => {
    expect(paginate([1, 2, 3, 4, 5], 2, 2)).toEqual([
      [1, 2, 3, 4],
      [5, null, null, null],
    ])
  })

  it('has no sheet for no cards', () => {
    expect(paginate([], 3, 3)).toEqual([])
  })

  it('puts the back of each card behind its front when the sheet is flipped on its long edge', () => {
    expect(mirrorRows([1, 2, 3, 4], 2)).toEqual([2, 1, 4, 3])
    expect(mirrorRows([1, 2, 3, 4, 5, 6, 7, 8, 9], 3)).toEqual([3, 2, 1, 6, 5, 4, 9, 8, 7])
  })
})
