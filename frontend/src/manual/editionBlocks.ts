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
 * a fenced code block, which follows CommonMark's fence rules and must be closed. An unclosed, nested or stray block, or any other `:::` line, throws: a
 * manual with a broken block must fail loudly, never show a half page.
 */
export function splitEditionBlocks(source: string): Segment[] {
  const segments: Segment[] = []
  let lines: string[] = []
  let edition: Segment['edition'] = 'common'
  let openedAt = 0
  let fence: { char: string; length: number; at: number } | null = null

  function flush() {
    const text = lines.join('\n').trim()
    if (text !== '') {
      segments.push({ edition, text })
    }
    lines = []
  }

  for (const [index, line] of source.split('\n').entries()) {
    const at = index + 1
    // CommonMark: up to three spaces of indent, then three or more backticks or tildes. A backtick
    // fence's info string cannot hold a backtick (so "``` a ```" is inline code, not a fence), and
    // a closing fence has no info string at all.
    const opens = /^ {0,3}(`{3,}|~{3,})(.*)$/.exec(line)
    const run = opens?.[1]
    const rest = opens?.[2] ?? ''
    if (run !== undefined) {
      if (fence === null) {
        if (!(run.startsWith('`') && rest.includes('`'))) {
          fence = { char: run.charAt(0), length: run.length, at }
        }
      } else if (run.startsWith(fence.char) && run.length >= fence.length && rest.trim() === '') {
        fence = null
      }
    }
    const marker = line.trimEnd()
    if (fence !== null || !marker.startsWith(closing)) {
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

  if (fence !== null) {
    // CommonMark runs an unclosed fence to the end of the page, which would hide any marker in it.
    throw new Error(`Line ${String(fence.at)}: the code fence is never closed.`)
  }
  if (edition === 'in-ogami') {
    throw new Error(`Line ${String(openedAt)}: the ${opening} block is never closed.`)
  }
  flush()
  return segments
}
