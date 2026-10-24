import { renderHook } from '@testing-library/react'

import { sameContent, useStableItems } from './useStableItems'

describe('sameContent', () => {
  it('compares primitives, arrays and objects by content', () => {
    expect(sameContent('a', 'a')).toBe(true)
    expect(sameContent([1, { a: [2] }], [1, { a: [2] }])).toBe(true)
    expect(sameContent({ a: undefined }, { a: undefined })).toBe(true)
  })

  it('tells different content apart, however deep', () => {
    expect(sameContent([{ field: 'a' }], [{ field: 'b' }])).toBe(false)
    expect(sameContent({ a: { b: 1 } }, { a: { b: 2 } })).toBe(false)
    expect(sameContent([1, 2], [1, 2, 3])).toBe(false)
    expect(sameContent({ a: 1 }, { b: 1 })).toBe(false)
    expect(sameContent({ a: undefined }, { b: undefined })).toBe(false)
    expect(sameContent([], {})).toBe(false)
    expect(sameContent(null, {})).toBe(false)
  })
})

describe('useStableItems', () => {
  it('keeps the reference of an item whose content is unchanged, and takes the one that changed', () => {
    const { result, rerender } = renderHook(({ items }) => useStableItems(items), {
      initialProps: { items: [{ n: 1 }, { n: 2 }, ['x']] as unknown[] },
    })
    const [first, second, third] = result.current

    rerender({ items: [{ n: 1 }, { n: 3 }, ['x']] })

    expect(result.current[0]).toBe(first)
    expect(result.current[1]).not.toBe(second)
    expect(result.current[1]).toEqual({ n: 3 })
    expect(result.current[2]).toBe(third)
  })

  it('follows a list that grows and shrinks', () => {
    const { result, rerender } = renderHook(({ items }) => useStableItems(items), {
      initialProps: { items: [['a'], ['b']] },
    })
    const [first] = result.current

    rerender({ items: [['a'], ['b'], ['c']] })
    expect(result.current).toEqual([['a'], ['b'], ['c']])
    expect(result.current[0]).toBe(first)

    rerender({ items: [['a']] })
    expect(result.current).toEqual([['a']])
    expect(result.current[0]).toBe(first)
  })
})
