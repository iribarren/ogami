import { safeRedirect } from './safeRedirect'

describe('safeRedirect', () => {
  it.each(['/', '/play', '/studio?tab=checks#oracles'])('keeps the same-origin path %s', (path) => {
    expect(safeRedirect(path)).toBe(path)
  })

  it.each([
    '//evil.com',
    '/\\evil.com',
    'https://evil.com',
    'http://ogami.test/play',
    'javascript:alert(1)',
    'play',
    '',
    undefined,
    42,
  ])('rejects %s', (value) => {
    expect(safeRedirect(value)).toBeUndefined()
  })
})
