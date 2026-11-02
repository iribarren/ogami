import { useEffect } from 'react'
import { useBlocker } from '@tanstack/react-router'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/dialog'

import { parseGameSystemTab } from './gameSystemTabs'

/** The section a location shows: no tab, and a tab the page does not know, are the Overview. */
function sectionOf(search: unknown): string {
  const tab = (search as { tab?: unknown } | undefined)?.tab
  return parseGameSystemTab(tab) ?? 'overview'
}

/**
 * Asks before the person leaves a page that holds unsaved changes: through a link or the back
 * button (a dialog) and by closing or reloading the tab (the browser's own prompt). A change of
 * search or hash on the same page never asks, except, with `blockTabChange`, a change of the
 * GameSystem page's section (`tab`): for a form that a tab switch unmounts. Rendered by the
 * editor, so it blocks while that editor is mounted and `dirty`; the editor's own navigation
 * after a save passes `ignoreBlocker`.
 */
export function UnsavedChangesGuard({
  dirty,
  blockTabChange = false,
}: {
  dirty: boolean
  blockTabChange?: boolean
}) {
  const blocker = useBlocker({
    shouldBlockFn: ({ current, next }) =>
      dirty &&
      (current.pathname !== next.pathname ||
        (blockTabChange && sectionOf(current.search) !== sectionOf(next.search))),
    enableBeforeUnload: dirty,
    withResolver: true,
  })

  // The person asked to leave and the changes were saved meanwhile (a save pending when they
  // pressed Back): nothing is left to lose, so the dialog would only say something false.
  const proceed = blocker.status === 'blocked' ? blocker.proceed : undefined
  useEffect(() => {
    if (!dirty) {
      proceed?.()
    }
  }, [dirty, proceed])

  return (
    <UnsavedChangesDialog
      open={blocker.status === 'blocked'}
      onStay={() => {
        blocker.reset?.()
      }}
      onLeave={() => {
        blocker.proceed?.()
      }}
    />
  )
}

/** The question both the guard and a form's own in-page switch put to the person. */
export function UnsavedChangesDialog({
  open,
  onStay,
  onLeave,
}: {
  open: boolean
  onStay: () => void
  onLeave: () => void
}) {
  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // Escape and a click outside mean Stay.
        if (!next) {
          onStay()
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Leave without saving?</DialogTitle>
          <DialogDescription>Your changes have not been saved.</DialogDescription>
        </DialogHeader>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onStay}>
            Stay
          </Button>
          <Button type="button" onClick={onLeave}>
            Leave without saving
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
