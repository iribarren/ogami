import { useState } from 'react'

import { Button } from '@/shared/ui/button'

import type { FlowStep } from './useFlowRun'

type PickDetails = NonNullable<FlowStep['pick']>

interface PickFormProps {
  /** What the step asks; null for a step that arrived without it, which fails closed. */
  pick: FlowStep['pick']
  pending: boolean
  /** Completes the step; `pickedIds` is sent in mode choose only. */
  onPick: (pickedIds: string[] | undefined) => void
}

/**
 * The control of a `pick` step. Random: the player presses "Pick" and the server draws. Choose:
 * the player ticks between `min` and `max` of the candidates. With fewer candidates than `min`
 * nothing can be picked: "No match", and Continue completes the step as no match.
 */
export function PickForm({ pick, pending, onPick }: PickFormProps) {
  if (pick === null) {
    return (
      <p role="alert" className="text-sm text-destructive">
        This pick step has no details to show.
      </p>
    )
  }
  if (pick.candidates.length < pick.min) {
    return (
      <div className="space-y-2">
        <p className="text-sm font-medium">No match</p>
        <Button
          type="button"
          disabled={pending}
          onClick={() => {
            onPick(pick.mode === 'choose' ? [] : undefined)
          }}
        >
          Continue
        </Button>
      </div>
    )
  }
  return pick.mode === 'random' ? (
    <div className="space-y-2">
      <PickSummary pick={pick} />
      <Button
        type="button"
        disabled={pending}
        onClick={() => {
          onPick(undefined)
        }}
      >
        Pick
      </Button>
    </div>
  ) : (
    <ChooseForm pick={pick} pending={pending} onPick={onPick} />
  )
}

/** "Pick 1 Thread tagged Ally", "Choose 1 to 2 NPCs": what is asked, as the step states it. */
function PickSummary({ pick }: { pick: PickDetails }) {
  const count =
    pick.min === pick.max ? String(pick.min) : `${String(pick.min)} to ${String(pick.max)}`
  const kind = pick.of === 'npc' ? 'NPC' : 'Thread'
  const tags = pick.tags.map(({ label }) => label).join(', ')
  return (
    <p className="text-sm text-muted-foreground">
      {pick.mode === 'random' ? 'Pick' : 'Choose'} {count} {kind}
      {pick.max > 1 ? 's' : ''}
      {tags === '' ? '' : ` tagged ${tags}`}
    </p>
  )
}

function ChooseForm({
  pick,
  pending,
  onPick,
}: Omit<PickFormProps, 'pick'> & { pick: PickDetails }) {
  const [chosen, setChosen] = useState<ReadonlySet<string>>(new Set())
  const inRange = chosen.size >= pick.min && chosen.size <= pick.max

  return (
    <form
      className="space-y-2"
      onSubmit={(event) => {
        event.preventDefault()
        // In list order, whatever order the player ticked them in.
        onPick(pick.candidates.filter(({ id }) => chosen.has(id)).map(({ id }) => id))
      }}
    >
      <PickSummary pick={pick} />
      <ul aria-label="Candidates" className="space-y-1">
        {pick.candidates.map(({ id, name }) => (
          <li key={id}>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={chosen.has(id)}
                onChange={(event) => {
                  const next = new Set(chosen)
                  if (event.target.checked) {
                    next.add(id)
                  } else {
                    next.delete(id)
                  }
                  setChosen(next)
                }}
              />
              {name}
            </label>
          </li>
        ))}
      </ul>
      <Button type="submit" disabled={pending || !inRange}>
        Choose
      </Button>
    </form>
  )
}
