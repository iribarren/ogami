import { useMemo } from 'react'

import { FlowDiagramView, flowDiagramFromReleaseContent } from '@/shared/flowDiagram'
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

/** The diagram of the campaign's Flow with the current phase marked; an unreadable one says so. */
function Diagram({ campaign, content }: { campaign: Campaign; content: unknown }) {
  const { flowKey, flowRun } = campaign
  const drawn = useMemo(() => {
    if (flowKey === null) {
      return null
    }
    try {
      return flowDiagramFromReleaseContent(content, flowKey)
    } catch (error) {
      return error instanceof Error ? error : new Error('The flow cannot be drawn.')
    }
  }, [content, flowKey])

  if (drawn === null) {
    return <p className="text-sm text-muted-foreground">This campaign plays without a flow.</p>
  }
  if (drawn instanceof Error) {
    return (
      <p role="alert" className="text-sm text-destructive">
        The flow map cannot be drawn: {drawn.message}
      </p>
    )
  }
  const phaseKey = flowRun?.status === 'active' ? flowRun.progress?.phaseKey : undefined
  return (
    <FlowDiagramView
      diagram={drawn}
      selectedId={phaseKey === undefined ? undefined : `phase:${phaseKey}`}
      className="max-w-full overflow-auto"
    />
  )
}

/**
 * The flow map (D8 of play-tabletop): the B34 diagram of the campaign's Flow, the current phase
 * marked. Wide, it lies small at the table's edge; narrow, the dock slot is a button that opens
 * it in a sheet (D10).
 */
export function FlowMap({ campaign, content }: { campaign: Campaign; content: unknown }) {
  return (
    <>
      <div className="hidden max-h-48 w-full overflow-auto @3xl:block">
        <Diagram campaign={campaign} content={content} />
      </div>
      <Dialog>
        <DialogTrigger asChild>
          <Button type="button" variant="ghost" className="h-auto w-full py-2 @3xl:hidden">
            Flow map
          </Button>
        </DialogTrigger>
        <DialogContent className="top-auto bottom-0 max-h-[85dvh] w-full max-w-none translate-y-0 rounded-b-none">
          <DialogHeader>
            <DialogTitle>Flow map</DialogTitle>
            <DialogDescription>Where the campaign stands in its flow.</DialogDescription>
          </DialogHeader>
          <Diagram campaign={campaign} content={content} />
        </DialogContent>
      </Dialog>
    </>
  )
}
