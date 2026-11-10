import { Fragment } from 'react'

import { Markdown } from '../Markdown'
import { paperBook } from './paperEdition'

/**
 * The Paper edition: a title page, the contents by part (no page numbers: the PDF footer has
 * them), then each part's divider and each chapter on a page of its own.
 */
export function PaperBook({ book }: { book: ReturnType<typeof paperBook> }) {
  const [title, subtitle] = book.title.split(': ')
  return (
    <div className="paper">
      <section className="paper-title">
        <h1>{title}</h1>
        {subtitle !== undefined && <p>{subtitle}</p>}
      </section>
      <section className="paper-page">
        <h2 className="paper-contents">Contents</h2>
        {book.parts.map((part) => (
          <Fragment key={part.name}>
            <h3>{part.name}</h3>
            <ol className={part.name.startsWith('Part') ? undefined : 'plain'}>
              {part.chapters.map((chapter) => (
                <li key={chapter.slug}>{chapter.title}</li>
              ))}
            </ol>
          </Fragment>
        ))}
      </section>
      {book.parts.map((part) => (
        <Fragment key={part.name}>
          <section className="paper-part">
            <h2>{part.name}</h2>
          </section>
          {part.chapters.map((chapter) => (
            <article key={chapter.slug} className="paper-page">
              <Markdown>{chapter.markdown}</Markdown>
            </article>
          ))}
        </Fragment>
      ))}
    </div>
  )
}
