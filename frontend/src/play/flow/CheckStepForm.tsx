import type { Campaign } from '../campaigns/useCampaigns'
import { CheckForm } from '../checks/CheckForm'
import type { CompleteFlowStepRequest, FlowStep } from './useFlowRun'

/**
 * The control of a `check` step: the player picks the Character who rolls (one of those the
 * server lists, preselected when there is just one), the Check difficulty unless the step fixes
 * it, and a Situational modifier. The step fails closed when the check is not in the campaign's
 * release or no Character can roll it. A refusal comes back as the answer's error.
 */
export function CheckStepForm({
  campaign,
  step,
  pending,
  onRoll,
}: {
  campaign: Campaign
  step: FlowStep
  pending: boolean
  onRoll: (fields: Omit<CompleteFlowStepRequest, 'stepKey'>) => void
}) {
  const check = campaign.checks.find(({ key }) => key === step.check)

  if (check === undefined) {
    return (
      <p role="alert" className="text-sm text-destructive">
        This step rolls the check “{step.check}”, which the release does not define.
      </p>
    )
  }
  if (step.characters.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No Character can roll this check: only a Character in the scene can (in the session party
        outside a scene).
      </p>
    )
  }

  return (
    <CheckForm
      checks={[check]}
      characters={step.characters}
      fixedDifficulty={step.difficulty}
      pending={pending}
      onSubmit={({ character, difficulty, situational }) => {
        onRoll({ characterId: character, difficulty, situational })
      }}
    />
  )
}
