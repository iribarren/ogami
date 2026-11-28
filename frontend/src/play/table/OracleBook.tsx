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
import { OraclePanel } from '../oracles/OraclePanel'

/**
 * The oracle book (D8 of play-tabletop): Play's own `OraclePanel`, with the props the Play page
 * gives it, in a side sheet on a wide screen and a bottom sheet on a narrow one. Results are
 * recorded by the panel exactly as on the Play page.
 */
export function OracleBook({ campaign }: { campaign: Campaign }) {
  const scene = campaign.sessions
    .find(({ number }) => number === campaign.currentSessionNumber)
    ?.scenes.find(({ number }) => number === campaign.currentSceneNumber)
  const flow = campaign.flows.find(({ key }) => key === campaign.flowKey)

  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button type="button" variant="ghost" className="h-auto w-full py-2 @3xl:min-h-20">
          Open the oracle book
        </Button>
      </DialogTrigger>
      <DialogContent
        className={
          // Narrow: a bottom sheet, as the dice tray. From `md`: a sheet at the right edge.
          'top-auto bottom-0 max-h-[85dvh] w-full max-w-none translate-y-0 rounded-b-none text-base md:top-0 md:right-0 md:bottom-auto md:left-auto md:h-dvh md:max-h-dvh md:w-[28rem] md:translate-x-0 md:rounded-none'
        }
      >
        <DialogHeader>
          <DialogTitle>Oracle book</DialogTitle>
          <DialogDescription>
            Ask a question or roll a table; the result is written in the journal.
          </DialogDescription>
        </DialogHeader>
        <OraclePanel
          campaignId={campaign.id}
          tables={campaign.oracleTables}
          likelihoodOracles={campaign.likelihoodOracles}
          sceneOracleKeys={campaign.sceneTypes.find(({ key }) => key === scene?.sceneType)?.oracles}
          flow={
            flow === undefined || campaign.flowRun?.status !== 'active'
              ? null
              : { name: flow.name, oracleKeys: flow.oracles }
          }
          disabled={campaign.currentSceneNumber === null}
        />
      </DialogContent>
    </Dialog>
  )
}
