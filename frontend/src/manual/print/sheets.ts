/**
 * Lays cards out on sheets. `paginate` fills a sheet row by row, padding the last one with `null`.
 * `mirrorRows` is the back of that sheet: flipped along its long edge, the first card of a row is
 * behind the last, so each row is reversed.
 */
export function paginate<T>(items: readonly T[], columns: number, rows: number): (T | null)[][] {
  const perSheet = columns * rows
  const sheets: (T | null)[][] = []
  for (let from = 0; from < items.length; from += perSheet) {
    const sheet: (T | null)[] = items.slice(from, from + perSheet)
    while (sheet.length < perSheet) {
      sheet.push(null)
    }
    sheets.push(sheet)
  }
  return sheets
}

export function mirrorRows<T>(sheet: readonly T[], columns: number): T[] {
  const mirrored: T[] = []
  for (let from = 0; from < sheet.length; from += columns) {
    mirrored.push(...sheet.slice(from, from + columns).reverse())
  }
  return mirrored
}
