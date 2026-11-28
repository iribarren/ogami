import { DiceRoller } from '../dice/DiceRoller'
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
import { useJournal, useRecordRoll } from '../journal/useJournal'
import { LatestRollView } from './LatestRollView'
import { latestRoll } from './latestRoll'

/** The roller and the latest roll, as the tray and its sheet show them. */
function TrayContent({ campaign }: { campaign: Campaign }) {
  const recordRoll = useRecordRoll(campaign.id)
  const journal = useJournal(campaign.id)
  // As the Play page's dice panel: a roll is recorded in a scene, so none is thrown without one.
  const disabled = campaign.currentSceneNumber === null

  return (
    <div className="space-y-3 text-left text-sm">
      <DiceRoller
        onRoll={(expression) => {
          recordRoll.mutate({ expression })
        }}
        pending={recordRoll.isPending}
        error={recordRoll.error}
        disabled={disabled}
      />
      {disabled && (
        <p className="text-muted-foreground">Start a scene to throw dice: a roll is written in the journal.</p>
      )}
      {journal.status === 'error' ? (
        <p role="alert" className="text-destructive">
          {journal.error.message}
        </p>
      ) : (
        <LatestRollView roll={latestRoll(journal.data ?? [])} />
      )}
    </div>
  )
}

/**
 * The dice tray (D6 of play-tabletop): free rolls recorded in the journal, and the latest roll, a
 * step's check included, as dice on the table. Wide, it lies open at the table's edge; narrow, the
 * dock slot is a button that opens it in a sheet (D10).
 */
export function DiceTray({ campaign }: { campaign: Campaign }) {
  return (
    <>
      <div className="hidden w-full @3xl:block">
        <TrayContent campaign={campaign} />
      </div>
      <Dialog>
        <DialogTrigger asChild>
          <Button type="button" variant="ghost" className="h-auto w-full py-2 @3xl:hidden">
            Dice
          </Button>
        </DialogTrigger>
        <DialogContent className="top-auto bottom-0 max-h-[85dvh] w-full max-w-none translate-y-0 rounded-b-none">
          <DialogHeader>
            <DialogTitle>Dice tray</DialogTitle>
            <DialogDescription>Throw dice; the roll is written in the journal.</DialogDescription>
          </DialogHeader>
          <TrayContent campaign={campaign} />
        </DialogContent>
      </Dialog>
    </>
  )
}
