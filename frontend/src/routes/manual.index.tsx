import { createFileRoute } from '@tanstack/react-router'

import { ManualIndexPage } from '@/manual/ManualIndexPage'

// Public: the manual needs no role (ADR 0050).
export const Route = createFileRoute('/manual/')({
  component: ManualIndexPage,
})
