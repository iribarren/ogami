import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/dialog'

/**
 * Asks before a change that is hard to notice or undo. The refusal, when there is one, shows
 * inside the dialog, which stays open; the caller closes it on success.
 */
export function ConfirmDialog({
  title,
  description,
  confirmLabel,
  pending,
  error,
  onConfirm,
  onClose,
}: {
  title: string
  description: string
  confirmLabel: string
  pending: boolean
  error: string | undefined
  onConfirm: () => void
  onClose: () => void
}) {
  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open) {
          onClose()
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {error !== undefined && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="destructive" disabled={pending} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
