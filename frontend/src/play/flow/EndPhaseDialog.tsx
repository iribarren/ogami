import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import { trackerValueText, type Tracker } from '../trackers/useTrackers'

/**
 * The "End the X phase" button with its confirmation: ending a phase is final, so the player
 * is asked to check their clues, facts and threads (and sees the trackers) before it is sent.
 */
export function EndPhaseDialog({
  label,
  trackers,
  disabled,
  onConfirm,
}: {
  /** "End the start phase": the button's name, and the question without its question mark. */
  label: string
  trackers: Tracker[]
  disabled: boolean
  onConfirm: () => void
}) {
  const [open, setOpen] = useState(false)

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button type="button" variant="outline" disabled={disabled}>
          {label}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{label}?</DialogTitle>
          <DialogDescription>
            You will not come back to this phase. Have you found what you need? Check your clues,
            facts and threads before you go on.
          </DialogDescription>
        </DialogHeader>
        {trackers.length > 0 && (
          <ul aria-label="Trackers" className="space-y-1">
            {trackers.map((tracker) => (
              <li key={tracker.key} className="flex items-baseline justify-between gap-2">
                <span className="font-medium">{tracker.name}</span>
                <span className="tabular-nums">
                  {trackerValueText(tracker)}
                  {tracker.levelLabel !== null && (
                    <span className="ml-2 text-xs text-muted-foreground">{tracker.levelLabel}</span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
        <div className="flex flex-wrap justify-end gap-2">
          <DialogClose asChild>
            <Button type="button" variant="outline">
              Keep playing
            </Button>
          </DialogClose>
          <Button
            type="button"
            onClick={() => {
              setOpen(false)
              onConfirm()
            }}
          >
            End the phase
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
