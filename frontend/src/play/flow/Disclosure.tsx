import type { ReactNode } from 'react'

import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'

/**
 * A titled text the player can fold away: the Flow introduction and the tips of a Scene Type.
 * Unmounts its body when closed, so it only holds text.
 */
export function Disclosure({
  title,
  defaultOpen,
  children,
}: {
  title: string
  defaultOpen: boolean
  children: ReactNode
}) {
  return (
    <Collapsible defaultOpen={defaultOpen} className="rounded-lg ring-1 ring-foreground/10">
      <CollapsibleTrigger className="group/trigger flex w-full items-center justify-between gap-2 rounded-lg p-3 text-left text-sm font-medium outline-none focus-visible:ring-3 focus-visible:ring-ring/50">
        {title}
        <svg
          aria-hidden="true"
          viewBox="0 0 16 16"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=closed]/trigger:-rotate-90"
        >
          <path d="m4 6 4 4 4-4" />
        </svg>
      </CollapsibleTrigger>
      <CollapsibleContent className="px-3 pb-3 text-sm whitespace-pre-line">
        {children}
      </CollapsibleContent>
    </Collapsible>
  )
}
