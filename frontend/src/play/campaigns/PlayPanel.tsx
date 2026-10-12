import { useId, useState, type ReactNode } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'

import { readPanelOpen, storePanelOpen } from './playPanelStorage'

interface PlayPanelProps {
  /** Stable key of the section (`dice`, `facts`…), under which its open state is remembered. */
  id: string
  title: string
  /** Shown next to the title when the number is cheap to know. */
  count?: number
  children: ReactNode
}

/**
 * One tool of the Play sidebar: a titled card whose body the player can collapse. Open by
 * default; the choice is remembered per section in this browser.
 */
export function PlayPanel({ id, title, count, children }: PlayPanelProps) {
  const headingId = useId()
  const [open, setOpen] = useState(() => readPanelOpen(id))

  return (
    <Collapsible
      asChild
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        storePanelOpen(id, next)
      }}
    >
      <section
        aria-labelledby={headingId}
        className="rounded-xl border bg-card p-4 text-card-foreground"
      >
        <div className="flex items-center justify-between gap-2">
          <h2 id={headingId} className="min-w-0 font-heading text-panel-title">
            <CollapsibleTrigger className="group/trigger -m-1 flex items-center gap-2 rounded-md p-1 outline-none focus-visible:ring-3 focus-visible:ring-ring/50">
              {title}
              <svg
                aria-hidden="true"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
                className="size-4 text-muted-foreground transition-transform group-data-[state=closed]/trigger:-rotate-90"
              >
                <path d="m4 6 4 4 4-4" />
              </svg>
            </CollapsibleTrigger>
          </h2>
          {count !== undefined && <Badge variant="secondary">{count}</Badge>}
        </div>
        <CollapsibleContent className="pt-3">{children}</CollapsibleContent>
      </section>
    </Collapsible>
  )
}
