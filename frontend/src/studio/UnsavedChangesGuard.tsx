import { useBlocker } from '@tanstack/react-router'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/dialog'

/**
 * Asks before the person leaves a page that holds unsaved changes: through a link or the back
 * button (a dialog) and by closing or reloading the tab (the browser's own prompt). A change of
 * search or hash on the same page never asks. Rendered by the editor, so it blocks while that
 * editor is mounted and `dirty`; the editor's own navigation after a save passes `ignoreBlocker`.
 */
export function UnsavedChangesGuard({ dirty }: { dirty: boolean }) {
  const blocker = useBlocker({
    shouldBlockFn: ({ current, next }) => dirty && current.pathname !== next.pathname,
    enableBeforeUnload: dirty,
    withResolver: true,
  })

  return (
    <Dialog
      open={blocker.status === 'blocked'}
      onOpenChange={(open) => {
        // Escape and a click outside mean Stay.
        if (!open) {
          blocker.reset?.()
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Leave without saving?</DialogTitle>
          <DialogDescription>Your changes have not been saved.</DialogDescription>
        </DialogHeader>
        <div className="flex justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => {
              blocker.reset?.()
            }}
          >
            Stay
          </Button>
          <Button
            type="button"
            onClick={() => {
              blocker.proceed?.()
            }}
          >
            Leave without saving
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
