// Browsers strip tabs and newlines from URLs and trim surrounding spaces, which
// can turn `/\t/evil.com` into `//evil.com`; such input is never a valid target.
// eslint-disable-next-line no-control-regex -- matching control characters is the point
const controlOrWhitespace = /[\s\u0000-\u001f\u007f]/

/**
 * Returns `value` when it is a same-origin path the app may navigate to after
 * signing in, `undefined` otherwise. Guards against open redirects: absolute
 * URLs, protocol-relative `//host`, the `/\host` form browsers treat alike, and
 * control characters or whitespace browsers would strip.
 */
export function safeRedirect(value: unknown): string | undefined {
  if (typeof value !== 'string' || controlOrWhitespace.test(value)) {
    return undefined
  }
  if (!value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) {
    return undefined
  }
  const origin = window.location.origin
  return new URL(value, origin).origin === origin ? value : undefined
}
