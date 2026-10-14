import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import type { Campaign } from '../campaigns/useCampaigns'
import { CheckForm } from './CheckForm'
import { usePerformCheck } from './usePerformCheck'

/**
 * "Roll a check" for one Character of the sheet: a dialog with the check form. The result is
 * recorded in the journal, and the dialog closes on success; a refusal stays in the form.
 */
export function RollCheckDialog({
  campaign,
  character,
}: {
  campaign: Campaign
  character: { id: string; name: string }
}) {
  const [open, setOpen] = useState(false)
  const perform = usePerformCheck(campaign.id)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        perform.reset()
        setOpen(next)
      }}
    >
      <DialogTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          aria-label={`Roll a check for ${character.name}`}
        >
          Roll a check
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Roll a check for {character.name}</DialogTitle>
          <DialogDescription>
            The result is recorded in the journal, in the current scene.
          </DialogDescription>
        </DialogHeader>
        <CheckForm
          checks={campaign.checks}
          pending={perform.isPending}
          error={perform.error?.message}
          onSubmit={({ check, difficulty, situational }) => {
            perform.mutate(
              { check, character: character.id, difficulty, situational },
              {
                onSuccess: () => {
                  setOpen(false)
                },
              },
            )
          }}
        />
      </DialogContent>
    </Dialog>
  )
}
