import { findChapter } from './chapters'
import { splitEditionBlocks, type Segment } from './editionBlocks'

// Imported by the manual routes only, so the page text stays out of the main bundle.
const sources = import.meta.glob<string>('./content/*.md', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** The Markdown of every file in `content/`, by slug. */
export function pageSources(): Record<string, string> {
  return Object.fromEntries(
    Object.entries(sources).map(([path, source]) => [path.replace(/^.*\/(.+)\.md$/, '$1'), source]),
  )
}

/** The segments of a manifest chapter. A missing file or a malformed block throws, naming the page. */
export function pageSegments(slug: string): Segment[] {
  const source = findChapter(slug) === undefined ? undefined : pageSources()[slug]
  if (source === undefined) {
    throw new Error(`The manual has no page "${slug}".`)
  }
  try {
    return splitEditionBlocks(source)
  } catch (error) {
    throw new Error(`Manual page "${slug}": ${error instanceof Error ? error.message : 'broken'}`, {
      cause: error,
    })
  }
}
