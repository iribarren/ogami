/** A value a formula reads: `{key}`, `{key.max}`, `{tracker:key}` or `{check:key}`. */
export interface Reference {
  kind: 'field' | 'trackMax' | 'tracker' | 'check'
  key: string
}

const keyPattern = /^[a-z0-9-]{1,64}$/
const trackerPrefix = 'tracker:'
const checkPrefix = 'check:'
const maxSuffix = '.max'

/** Reads what is written between the braces; null when it is none of the four spellings. */
export function referenceFromInner(inner: string): Reference | null {
  if (inner.startsWith(trackerPrefix)) {
    const key = inner.slice(trackerPrefix.length)
    return keyPattern.test(key) ? { kind: 'tracker', key } : null
  }
  if (inner.startsWith(checkPrefix)) {
    const key = inner.slice(checkPrefix.length)
    return keyPattern.test(key) ? { kind: 'check', key } : null
  }
  if (inner.endsWith(maxSuffix)) {
    const key = inner.slice(0, -maxSuffix.length)
    return keyPattern.test(key) ? { kind: 'trackMax', key } : null
  }
  return keyPattern.test(inner) ? { kind: 'field', key: inner } : null
}

/** The canonical spelling inside the braces: `str`, `hp.max`, `tracker:chaos` or `check:difficulty`. */
export function referenceToString(reference: Reference): string {
  switch (reference.kind) {
    case 'field':
      return reference.key
    case 'trackMax':
      return `${reference.key}${maxSuffix}`
    case 'tracker':
      return `${trackerPrefix}${reference.key}`
    case 'check':
      return `${checkPrefix}${reference.key}`
  }
}
