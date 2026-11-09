/** A run of a manual page: shown in every edition, or only where the book is read in Ogami. */
export interface Segment {
  edition: 'common' | 'in-ogami'
  text: string
}

const opening = ':::in-ogami'
const closing = ':::'

/**
 * Splits a page into common and `:::in-ogami` segments before it is rendered as Markdown, so the
 * Paper edition can leave the second kind out. A marker is a line of its own and is ignored inside
 * a fenced code block. An unclosed, nested or stray block, or any other `:::` line, throws: a
 * manual with a broken block must fail loudly, never show a half page.
 */
export function splitEditionBlocks(source: string): Segment[] {
  const segments: Segment[] = []
  let lines: string[] = []
  let edition: Segment['edition'] = 'common'
  let openedAt = 0
  let fence: { char: string; length: number } | null = null

  function flush() {
    const text = lines.join('\n').trim()
    if (text !== '') {
      segments.push({ edition, text })
    }
    lines = []
  }

  for (const [index, line] of source.split('\n').entries()) {
    const at = index + 1
    const run = /^ {0,3}(`{3,}|~{3,})/.exec(line)?.[1]
    if (run !== undefined) {
      if (fence === null) {
        fence = { char: run.charAt(0), length: run.length }
      } else if (run.startsWith(fence.char) && run.length >= fence.length) {
        fence = null
      }
    }
    const marker = line.trimEnd()
    if (fence !== null || run !== undefined || !marker.startsWith(closing)) {
      lines.push(line)
      continue
    }
    if (marker === opening) {
      if (edition === 'in-ogami') {
        throw new Error(`Line ${String(at)}: a nested ${opening} block.`)
      }
      flush()
      edition = 'in-ogami'
      openedAt = at
    } else if (marker === closing) {
      if (edition === 'common') {
        throw new Error(`Line ${String(at)}: a closing ${closing} without an open block.`)
      }
      flush()
      edition = 'common'
    } else {
      throw new Error(`Line ${String(at)}: unknown block marker "${marker}".`)
    }
  }

  if (edition === 'in-ogami') {
    throw new Error(`Line ${String(openedAt)}: the ${opening} block is never closed.`)
  }
  flush()
  return segments
}
