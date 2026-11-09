import { Link } from '@tanstack/react-router'

import { findChapter, neighbours } from './chapters'
import { ManualContents } from './ManualContents'
import { Markdown } from './Markdown'
import { pageSegments } from './pages'

/** `/manual/$chapter`: one page of the Full edition, with the contents beside it. */
export function ManualChapterPage({ slug }: { slug: string }) {
  const chapter = findChapter(slug)
  if (chapter === undefined) {
    throw new Error(`The manual has no page "${slug}".`)
  }
  const segments = pageSegments(slug)
  const { previous, next } = neighbours(slug)

  return (
    <div className="grid gap-8 lg:grid-cols-[14rem_minmax(0,1fr)]">
      <ManualContents label="Manual contents" />
      <article className="max-w-3xl min-w-0 space-y-8">
        {segments.map((segment, at) =>
          segment.edition === 'common' ? (
            <Markdown key={at}>{segment.text}</Markdown>
          ) : (
            <aside
              key={at}
              aria-label="In Ogami"
              className="space-y-2 rounded-xl border-l-4 border-info bg-well p-4"
            >
              <p className="text-xs font-semibold tracking-wide uppercase">In Ogami</p>
              <Markdown>{segment.text}</Markdown>
            </aside>
          ),
        )}
        <nav
          aria-label="Chapter navigation"
          className="flex flex-wrap justify-between gap-4 border-t pt-4"
        >
          {previous ? (
            <Link to="/manual/$chapter" params={{ chapter: previous.slug }} rel="prev">
              Previous: {previous.title}
            </Link>
          ) : (
            <span />
          )}
          {next && (
            <Link to="/manual/$chapter" params={{ chapter: next.slug }} rel="next">
              Next: {next.title}
            </Link>
          )}
        </nav>
      </article>
    </div>
  )
}
