import { Alert } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'

import type { StepContext } from '../steps/stepContext'
import { hasMissing, type Missing } from './flowEnable'

/**
 * A warning that lists what is used but not enabled on the flow, with a button that enables all of
 * it (S16). The flow changes only when it is pressed; it shows nothing when nothing is missing.
 */
export function EnableOnFlow({
  missing,
  context,
  intro,
  onEnable,
}: {
  missing: Missing
  context: StepContext
  intro: string
  onEnable: () => void
}) {
  if (!hasMissing(missing)) {
    return null
  }
  const nameIn = (options: { key: string; name: string }[], key: string) =>
    options.find((option) => option.key === key)?.name ?? key

  return (
    <Alert
      variant="warning"
      action={
        <Button type="button" variant="outline" size="sm" onClick={onEnable}>
          Enable on flow
        </Button>
      }
    >
      <p>{intro}</p>
      <ul className="list-disc pl-5">
        {missing.trackers.map((key) => (
          <li key={`tracker:${key}`}>Tracker {nameIn(context.trackers, key)}</li>
        ))}
        {missing.oracles.map((key) => (
          <li key={`oracle:${key}`}>
            Oracle shortcut {nameIn([...context.tables, ...context.likelihood], key)}
          </li>
        ))}
      </ul>
    </Alert>
  )
}
