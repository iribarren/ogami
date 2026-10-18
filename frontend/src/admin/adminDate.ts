// API timestamps are UTC and shown by day, so the zone is fixed rather than the browser's.
const dateFormat = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'UTC' })

/** An API timestamp (RFC 3339) as a UTC date, like "1 Oct 2026". */
export const adminDate = (timestamp: string) => dateFormat.format(new Date(timestamp))
