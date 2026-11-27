import { imageStyleKeys } from './images/styles'

type Edition = 'common' | 'in-ogami'

/** A run of Markdown: shown in every edition, or only where the book is read in Ogami. */
export interface MarkdownSegment {
  edition: Edition
  kind: 'markdown'
  text: string
}

/**
 * A `::flow-diagram{…}` directive: the diagram of one flow of a manual preset, or of one of its
 * phases. Where it sits decides its edition, like any other run.
 */
export interface FlowDiagramSegment {
  edition: Edition
  kind: 'flow-diagram'
  flow: string
  phase?: string
}

export type ImageSize = 'banner' | 'half' | 'spot'

/**
 * An `::image{…}` directive: one illustration, its file, art style, size, alt text and the subject
 * of its prompt. The file may not exist yet; the page then shows a placeholder.
 */
export interface ImageSegment {
  edition: Edition
  kind: 'image'
  file: string
  style: string
  size: ImageSize
  alt: string
  subject: string
}

/** A run of a manual page, in page order. */
export type Segment = MarkdownSegment | FlowDiagramSegment | ImageSegment

const opening = ':::in-ogami'
const closing = ':::'
const directiveName = 'flow-diagram'
const flowDirective = /^::flow-diagram\{flow="([a-z0-9-]+)"(?: phase="([a-z0-9-]+)")?\}$/
const imageDirective =
  /^::image\{file="([a-z0-9-]+\.(?:webp|png|jpg))" style="([a-z0-9-]+)" size="(banner|half|spot)" alt="([^"]*\S[^"]*)" subject="([^"]*\S[^"]*)"\}$/

/**
 * Splits a page into common and `:::in-ogami` segments before it is rendered as Markdown, so the
 * Paper edition can leave the second kind out. A marker is a line of its own and is ignored inside
 * a fenced code block, which follows CommonMark's fence rules and must be closed. An unclosed, nested or stray block, or any other `:::` line, throws: a
 * manual with a broken block must fail loudly, never show a half page.
 *
 * A line of its own `::flow-diagram{flow="key"}` (or with ` phase="key"` too) becomes a diagram
 * segment in the edition it sits in; the splitter only parses it, `manualFlowDiagram` resolves it.
 * A line of its own `::image{file="…" style="…" size="…" alt="…" subject="…"}` becomes an image
 * segment, whose style must be a key of `images/styles.ts`. Any other `::name` line, or a malformed
 * `::flow-diagram` or `::image` line, throws.
 */
export function splitEditionBlocks(source: string): Segment[] {
  const segments: Segment[] = []
  let lines: string[] = []
  let edition: Edition = 'common'
  let openedAt = 0
  let fence: { char: string; length: number; at: number } | null = null

  function flush() {
    const text = lines.join('\n').trim()
    if (text !== '') {
      segments.push({ edition, kind: 'markdown', text })
    }
    lines = []
  }

  for (const [index, line] of source.split(/\r?\n/).entries()) {
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
    if (fence === null && /^::image(?![A-Za-z0-9-])/.test(marker)) {
      const found = imageDirective.exec(marker)
      if (found === null) {
        throw new Error(
          `Line ${String(at)}: malformed directive "${marker}", expected ::image{file="…" style="…" size="banner|half|spot" alt="…" subject="…"}.`,
        )
      }
      const [, file = '', style = '', size = '', alt = '', subject = ''] = found
      if (!imageStyleKeys.includes(style)) {
        throw new Error(
          `Line ${String(at)}: unknown image style "${style}", expected one of: ${imageStyleKeys.join(', ')}.`,
        )
      }
      flush()
      segments.push({ edition, kind: 'image', file, style, size: size as ImageSize, alt, subject })
      continue
    }
    if (fence === null && /^::[A-Za-z]/.test(marker)) {
      const found = flowDirective.exec(marker)
      if (found === null) {
        throw new Error(
          marker.startsWith(`::${directiveName}`)
            ? `Line ${String(at)}: malformed directive "${marker}", expected ::${directiveName}{flow="key"} or ::${directiveName}{flow="key" phase="key"}.`
            : `Line ${String(at)}: unknown directive "${marker}".`,
        )
      }
      flush()
      const [, flow = '', phase] = found
      segments.push(
        phase === undefined
          ? { edition, kind: 'flow-diagram', flow }
          : { edition, kind: 'flow-diagram', flow, phase },
      )
      continue
    }
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
