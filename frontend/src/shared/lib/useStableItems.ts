import { useState } from 'react'

/**
 * Whether two values hold the same content: primitives, arrays and plain objects, compared deeply.
 * Anything else (a function, a Date, a Map, a class instance) is the same only when it is the same
 * reference, so a changed one is never mistaken for an unchanged one.
 */
export function sameContent(a: unknown, b: unknown): boolean {
  if (Object.is(a, b)) {
    return true
  }
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) {
    return false
  }
  if (Array.isArray(a) || Array.isArray(b)) {
    return (
      Array.isArray(a) &&
      Array.isArray(b) &&
      a.length === b.length &&
      a.every((item, at) => sameContent(item, b[at]))
    )
  }
  if (!isPlainObject(a) || !isPlainObject(b)) {
    return false
  }
  const left = a as Record<string, unknown>
  const right = b as Record<string, unknown>
  const keys = Object.keys(left)
  return (
    keys.length === Object.keys(right).length &&
    keys.every((key) => key in right && sameContent(left[key], right[key]))
  )
}

function isPlainObject(value: object): boolean {
  const prototype: unknown = Object.getPrototypeOf(value)
  return prototype === Object.prototype || prototype === null
}

/**
 * The items of a list that is rebuilt on every render, each keeping the reference it had last
 * time when its content is unchanged, so a memoised child that gets one item skips the render.
 * The array itself is new each time; hand its items to the children, not the array.
 * Items must be plain data. A function or class instance built during render is new on every
 * render, so it never matches and the update below would loop: build those outside render.
 */
export function useStableItems<T>(items: T[]): T[] {
  const [kept, setKept] = useState(items)
  const stable = items.map((item, at) =>
    at < kept.length && sameContent(kept[at], item) ? (kept[at] as T) : item,
  )
  if (stable.length !== kept.length || stable.some((item, at) => item !== kept[at])) {
    setKept(stable)
  }
  return stable
}
