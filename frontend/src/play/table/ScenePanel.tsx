import type { ReactNode } from 'react'

import { cn } from '@/shared/lib/utils'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/dialog'

import type { Campaign } from '../campaigns/useCampaigns'
import { FocusPanel } from '../flow/FocusPanel'
import { GuidedControls } from '../flow/GuidedParts'
import type { useFlowRunCommand } from '../flow/useFlowRun'

/**
 * The panel a scene is played in (D3, D5 of play-tabletop): Play's own focus panel and guided
 * controls, unchanged, in a dialog that takes the whole screen on a phone and at least two thirds
 * of the width on a desktop, with its own scroll. The open state belongs to the table, so a
 * command that refreshes the campaign never closes it.
 */
export function ScenePanel({
  campaign,
  command,
  open,
  onOpenChange,
  opener,
}: {
  campaign: Campaign
  command: ReturnType<typeof useFlowRunCommand>
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The card that opened the panel, which gets the focus back when it closes. */
  opener: React.RefObject<HTMLElement | null>
}) {
  const { flowRun } = campaign
  const scene = campaign.sessions
    .find(({ number }) => number === campaign.currentSessionNumber)
    ?.scenes.find(({ number }) => number === campaign.currentSceneNumber)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        onCloseAutoFocus={(event) => {
          event.preventDefault()
          opener.current?.focus()
        }}
        className={cn(
          // A phone: the whole screen. From `md`: centred, at least two thirds wide, full height.
          'inset-0 top-0 left-0 h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 content-start gap-4 rounded-none p-4 text-base',
          'md:top-1/2 md:left-1/2 md:w-[max(66vw,42rem)] md:max-w-[100vw] md:-translate-x-1/2 md:-translate-y-1/2 md:rounded-xl md:p-6',
        )}
      >
        <DialogHeader>
          <DialogTitle className="text-xl">
            {scene === undefined ? 'Play' : `Scene: ${scene.title}`}
          </DialogTitle>
          <DialogDescription>
            {scene === undefined
              ? 'No scene is under way. Start the session or pick the next scene here.'
              : 'Play the scene step by step.'}
          </DialogDescription>
        </DialogHeader>
        {flowRun === null ? (
          <NoFlow />
        ) : (
          <>
            <FocusPanel campaign={campaign} flowRun={flowRun} command={command} />
            <GuidedControls campaign={campaign} flowRun={flowRun} command={command} />
          </>
        )}
        <DialogClose asChild>
          <Button type="button" variant="outline" className="justify-self-start">
            Close
          </Button>
        </DialogClose>
      </DialogContent>
    </Dialog>
  )
}

function NoFlow(): ReactNode {
  return (
    <p className="text-muted-foreground">
      This campaign plays without a guided flow. Write what happens in the journal on the Play page.
    </p>
  )
}
