import type { Campaign } from '../campaigns/useCampaigns'
import { CheckForm } from '../checks/CheckForm'
import type { CompleteFlowStepRequest, FlowStep } from './useFlowRun'

/**
 * The control of a `check` step: the player picks the Character who rolls (one of those the
 * server lists, preselected when there is just one), the check when the step offers a set (none
 * preselected, ADR 0042), the Check difficulty unless the step fixes it, and a Situational
 * modifier. The step fails closed when a check is not in the campaign's
 * release, no Character can roll it, or the difficulty it fixes cannot be worked out right now. A refusal comes back as the answer's error.
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
  // A set step lists its checks; a single-check step names one.
  const keys = step.checks.length > 0 ? step.checks : step.check === null ? [] : [step.check]
  const checks = keys.flatMap((key) => campaign.checks.filter((check) => check.key === key))
  const missing = keys.find((key) => !campaign.checks.some((check) => check.key === key))

  if (checks.length === 0 || missing !== undefined) {
    return (
      <p role="alert" className="text-sm text-destructive">
        This step rolls the check “{missing ?? step.check}”, which the release does not define.
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

  if (step.difficultyFixed && step.difficulty === null) {
    return (
      <p role="alert" className="text-sm text-destructive">
        The difficulty can't be worked out right now.
      </p>
    )
  }

  return (
    <CheckForm
      // Each check step starts from its own values, not the previous step's edits.
      key={step.key}
      checks={checks}
      pickCheck={step.checks.length > 0}
      characters={step.characters}
      fixedDifficulty={step.difficulty}
      pending={pending}
      onSubmit={({ check, character, difficulty, situational }) => {
        onRoll({
          characterId: character,
          difficulty,
          situational,
          // A set step is answered with the check picked; a single check is the step's own.
          ...(step.checks.length > 0 ? { checkKey: check } : {}),
        })
      }}
    />
  )
}
