import { useRef, type KeyboardEvent, type ReactNode } from 'react'

import { cn } from '@/shared/lib/utils'

import type { EditorView } from './flowNode'

const tabs: { view: EditorView; label: string }[] = [
  { view: 'panel', label: 'Panel' },
  { view: 'map', label: 'Map' },
]

const tabId = (view: EditorView) => `flow-tab-${view}`
const paneId = 'flow-pane'

/** The tabs of the editor's right-hand pane: arrows, Home and End move between them, as a tablist does. */
export function EditorTabs({
  view,
  onChange,
}: {
  view: EditorView
  onChange: (view: EditorView) => void
}) {
  const list = useRef<HTMLDivElement>(null)

  function move(event: KeyboardEvent, from: number) {
    const last = tabs.length - 1
    const to =
      event.key === 'ArrowRight'
        ? (from + 1) % tabs.length
        : event.key === 'ArrowLeft'
          ? (from + last) % tabs.length
          : event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? last
              : null
    const next = to === null ? undefined : tabs[to]
    if (next === undefined) {
      return
    }
    event.preventDefault()
    onChange(next.view)
    list.current?.querySelector<HTMLElement>(`#${tabId(next.view)}`)?.focus()
  }

  return (
    <div
      ref={list}
      role="tablist"
      aria-label="Flow view"
      className="inline-flex items-center gap-0.5 rounded-lg bg-muted p-0.5"
    >
      {tabs.map(({ view: tab, label }, at) => (
        <button
          key={tab}
          id={tabId(tab)}
          type="button"
          role="tab"
          aria-selected={tab === view}
          aria-controls={paneId}
          tabIndex={tab === view ? 0 : -1}
          className={cn(
            'inline-flex h-7 items-center rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50',
            tab === view && 'bg-background text-foreground shadow-sm',
          )}
          onClick={() => {
            onChange(tab)
          }}
          onKeyDown={(event) => {
            move(event, at)
          }}
        >
          {label}
        </button>
      ))}
    </div>
  )
}

/** The panel the tabs control: it is labelled by the selected tab. */
export function EditorPane({ view, children }: { view: EditorView; children: ReactNode }) {
  return (
    <div role="tabpanel" id={paneId} aria-labelledby={tabId(view)} className="space-y-4">
      {children}
    </div>
  )
}
