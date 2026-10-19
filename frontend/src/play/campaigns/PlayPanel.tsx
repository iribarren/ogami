import { ChevronDown } from 'lucide-react'
import { useEffect, useId, useRef, useState, type ReactNode } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Collapsible, CollapsibleTrigger } from '@/shared/ui/collapsible'
import { IconTile } from '@/shared/ui/icon-tile'

import { openPlayPanelEvent } from './openPlayPanel'
import { readPanelOpen, storePanelOpen } from './playPanelStorage'

interface PlayPanelProps {
  /** Stable key of the section (`dice`, `facts`…), under which its open state is remembered. */
  id: string
  title: string
  /** Decorative icon shown in a themed tile before the title. */
  icon?: ReactNode
  /** Shown next to the title when the number is cheap to know. */
  count?: number
  /** One line under the title saying what the tool is for. */
  help?: string
  children: ReactNode
}

/**
 * One tool of the Play sidebar: a titled card whose body the player can collapse. Open by
 * default; the choice is remembered per section in this browser.
 */
export function PlayPanel({ id, title, icon, count, help, children }: PlayPanelProps) {
  const headingId = useId()
  const bodyId = useId()
  const [open, setOpen] = useState(() => readPanelOpen(id))
  const trigger = useRef<HTMLButtonElement>(null)

  // openPlayPanel(id) expands this panel, brings it into view and puts the focus on its title.
  useEffect(() => {
    const onOpen = (event: Event) => {
      if (!(event instanceof CustomEvent) || event.detail !== id) {
        return
      }
      setOpen(true)
      storePanelOpen(id, true)
      const title = trigger.current
      if (title !== null) {
        // jsdom has no scrollIntoView.
        if ('scrollIntoView' in title) {
          title.scrollIntoView({ block: 'nearest' })
        }
        title.focus()
      }
    }
    window.addEventListener(openPlayPanelEvent, onOpen)
    return () => {
      window.removeEventListener(openPlayPanelEvent, onOpen)
    }
  }, [id])

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
        className="rounded-xl border bg-card p-4 text-card-foreground shadow-card"
      >
        <div className="flex items-center justify-between gap-2">
          <h2 id={headingId} className="min-w-0 font-heading text-panel-title panel-title-case">
            {/* Radix points aria-controls at CollapsibleContent, which this panel does not render. */}
            <CollapsibleTrigger
              ref={trigger}
              aria-controls={bodyId}
              className="group/trigger -m-1 flex items-center gap-2 rounded-md p-1 outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            >
              {icon !== undefined && <IconTile size="sm">{icon}</IconTile>}
              {title}
              <ChevronDown
                aria-hidden="true"
                className="size-4 text-muted-foreground transition-transform group-data-[state=closed]/trigger:-rotate-90"
              />
            </CollapsibleTrigger>
          </h2>
          {count !== undefined && <Badge variant="secondary">{count}</Badge>}
        </div>
        {/*
          Hidden, not unmounted: Radix's CollapsibleContent drops its children when closed, which
          would lose the tools' local state (drafts, dice expression, last roll or oracle result).
          `hidden` keeps the body out of the accessibility tree and the tab order.
        */}
        <div
          id={bodyId}
          hidden={!open}
          data-state={open ? 'open' : 'closed'}
          className="space-y-3 pt-3"
        >
          {help !== undefined && <p className="text-sm text-muted-foreground">{help}</p>}
          {children}
        </div>
      </section>
    </Collapsible>
  )
}
