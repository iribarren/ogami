const dateFormat = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' })

/** An API timestamp (RFC 3339) as a date, like "1 Oct 2026". */
export const adminDate = (timestamp: string) => dateFormat.format(new Date(timestamp))
