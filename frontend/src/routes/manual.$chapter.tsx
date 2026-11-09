import { createFileRoute, notFound } from '@tanstack/react-router'

import { findChapter } from '@/manual/chapters'
import { ManualChapterPage } from '@/manual/ManualChapterPage'

// Public: the manual needs no role. Only the manifest is read before the page chunk loads.
export const Route = createFileRoute('/manual/$chapter')({
  beforeLoad: ({ params }) => {
    if (findChapter(params.chapter) === undefined) {
      // TanStack Router's documented way to reach the not-found page.
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw notFound()
    }
  },
  component: ChapterRoute,
})

function ChapterRoute() {
  const { chapter } = Route.useParams()
  return <ManualChapterPage slug={chapter} />
}
