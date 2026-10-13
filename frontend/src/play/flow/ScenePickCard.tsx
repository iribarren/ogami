import { Button } from '@/shared/ui/button'

import type { Campaign } from '../campaigns/useCampaigns'
import type { FlowRunCommand, ScenePick } from './useFlowRun'

const ruleLabels: Record<ScenePick['rule'], string> = {
  player: 'Choose the next Scene Type',
  sequence: 'The Flow sets the next Scene Type',
  oracle: 'Roll the next Scene Type',
}

/**
 * The scene pick: one card per Scene Type the player may choose (a sequence or a forced Scene
 * Type offers its single card), or a roll on the oracle table that names it.
 */
export function ScenePickCard({
  campaign,
  pick,
  pending,
  onCommand,
}: {
  campaign: Campaign
  pick: ScenePick
  pending: boolean
  onCommand: (command: FlowRunCommand) => void
}) {
  const table = campaign.oracleTables.find(({ key }) => key === pick.table)

  return (
    <section aria-label="Scene pick" className="space-y-3 rounded-xl p-4 ring-2 ring-primary/40">
      <h4 className="font-heading text-base font-semibold">{ruleLabels[pick.rule]}</h4>
      {pick.rule === 'oracle' && pick.cards.length === 0 ? (
        <Button
          type="button"
          disabled={pending}
          onClick={() => {
            onCommand({ type: 'pickByRoll' })
          }}
        >
          Roll on {table?.name ?? pick.table}
        </Button>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {pick.cards.map((card) => (
            <li key={card.key} className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10">
              <p className="font-medium">{card.name}</p>
              {pick.forced && (
                <p className="text-xs text-muted-foreground">Forced for this scene</p>
              )}
              <p className="text-sm text-muted-foreground">{card.purpose}</p>
              {card.tips !== null && (
                <p className="text-xs whitespace-pre-line text-muted-foreground">{card.tips}</p>
              )}
              <Button
                type="button"
                variant="outline"
                aria-label={`Play ${card.name}`}
                disabled={pending}
                onClick={() => {
                  onCommand({ type: 'pick', sceneType: card.key })
                }}
              >
                Play this
              </Button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
