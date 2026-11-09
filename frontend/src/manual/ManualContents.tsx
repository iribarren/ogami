import { Link } from '@tanstack/react-router'

import { parts } from './chapters'

/** The chapters grouped by part, in reading order; the current page is marked by the router. */
export function ManualContents({ label = 'Contents' }: { label?: string }) {
  return (
    <nav aria-label={label} className="space-y-4">
      {parts().map((part) => (
        <section key={part.name} aria-label={part.name} className="space-y-1.5">
          <h2 className="section-title">{part.name}</h2>
          <ol className="space-y-1">
            {part.chapters.map((chapter) => (
              <li key={chapter.slug}>
                <Link
                  to="/manual/$chapter"
                  params={{ chapter: chapter.slug }}
                  className="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                  activeProps={{ className: 'font-medium text-foreground' }}
                >
                  {chapter.title}
                </Link>
              </li>
            ))}
          </ol>
        </section>
      ))}
    </nav>
  )
}
