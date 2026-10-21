import type { ReactNode } from 'react'
import { Check, CircleAlert, LoaderCircle } from 'lucide-react'

import { cn } from '@/shared/lib/utils'

export type SaveStateValue =
  | { kind: 'saving' }
  | { kind: 'saved'; at: Date }
  | { kind: 'failed'; errors: number; onGoToFirst?: () => void }
  | { kind: 'unsaved' }

/** `HH:MM` in the viewer's locale and zone. */
const time = (at: Date) => at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

/**
 * What happened to the last save, next to the Save button. Saved reads as a status and Not saved
 * as an alert, so assistive technology announces them when they appear.
 */
function SaveState({ state, className }: { state: SaveStateValue; className?: string }) {
  let body: ReactNode
  switch (state.kind) {
    case 'saving':
      body = (
        <span className="save-state">
          <LoaderCircle aria-hidden="true" className="animate-spin" />
          Saving…
        </span>
      )
      break
    case 'saved':
      body = (
        <span role="status" className="save-state">
          <Check aria-hidden="true" className="text-success" />
          Saved at {time(state.at)}
        </span>
      )
      break
    case 'failed':
      body = (
        <span role="alert" className="save-state text-destructive">
          <CircleAlert aria-hidden="true" />
          Not saved: {state.errors} {state.errors === 1 ? 'error' : 'errors'}
          {state.onGoToFirst && (
            <button
              type="button"
              className="ml-1 underline underline-offset-4"
              onClick={state.onGoToFirst}
            >
              Go to the first
            </button>
          )}
        </span>
      )
      break
    case 'unsaved':
      body = <span className="save-state">Unsaved changes</span>
      break
  }
  return <span className={cn('inline-flex', className)}>{body}</span>
}

export { SaveState }
