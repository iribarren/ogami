const FALLBACK = '✦'

/**
 * The letter on a campaign's wax seal: the first letter or digit of its name, upper-cased.
 * Leading spaces, quotes and emoji are skipped; a name without any shows a neutral glyph.
 */
export function markInitial(name: string): string {
  const first = /[\p{L}\p{N}]/u.exec(name)?.[0]
  return first === undefined ? FALLBACK : first.toUpperCase()
}
