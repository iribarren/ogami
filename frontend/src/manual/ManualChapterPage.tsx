import { Link } from '@tanstack/react-router'
import { Fragment } from 'react'

import { findChapter, neighbours } from './chapters'
import { ManualContents } from './ManualContents'
import { ManualFlowDiagram } from './ManualFlowDiagram'
import { ManualImage } from './ManualImage'
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
      {/* A heading clears the half and spot illustrations that float beside the text above it. */}
      <article className="max-w-3xl min-w-0 space-y-8 [&_h2]:clear-both">
        {segments.map((segment, at) => {
          const run = (() => {
            switch (segment.kind) {
              case 'markdown':
                return <Markdown>{segment.text}</Markdown>
              case 'flow-diagram':
                return <ManualFlowDiagram flow={segment.flow} phase={segment.phase} />
              case 'image':
                return (
                  <ManualImage
                    file={segment.file}
                    style={segment.style}
                    size={segment.size}
                    alt={segment.alt}
                    subject={segment.subject}
                  />
                )
            }
          })()
          return segment.edition === 'common' ? (
            <Fragment key={at}>{run}</Fragment>
          ) : (
            <aside
              key={at}
              aria-label="In Ogami"
              className="space-y-2 rounded-xl border-l-4 border-info bg-well p-4"
            >
              <p className="text-xs font-semibold tracking-wide uppercase">In Ogami</p>
              {run}
            </aside>
          )
        })}
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
