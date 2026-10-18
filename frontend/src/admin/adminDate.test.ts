import { adminDate } from './adminDate'

describe('adminDate', () => {
  it('shows the UTC day, whatever the offset of the timestamp', () => {
    // 04:30 UTC on the 2nd, but still the evening of the 1st in New York.
    expect(adminDate('2026-10-01T23:30:00-05:00')).toBe('2 Oct 2026')
    // 22:30 UTC on 31 October, but already 1 November in Paris.
    expect(adminDate('2026-11-01T00:30:00+02:00')).toBe('31 Oct 2026')
  })
})
