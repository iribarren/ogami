/**
 * Returns `value` when it is a same-origin path the app may navigate to after
 * signing in, `undefined` otherwise. Guards against open redirects: absolute
 * URLs, protocol-relative `//host` and the `/\host` form browsers treat alike.
 */
export function safeRedirect(value: unknown): string | undefined {
  if (typeof value !== 'string' || !value.startsWith('/')) {
    return undefined
  }
  if (value.startsWith('//') || value.startsWith('/\\')) {
    return undefined
  }
  return value
}
