import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { releaseLabel } from './releaseLabel'
import { useCreateCampaign, type GameSystemSummary } from './useCampaigns'

/** The API's limit on a campaign name (`Campaign::MAX_NAME_LENGTH`), after trimming. */
const MAX_NAME_LENGTH = 100

// Native select styled like the shadcn Input; no select primitive is installed yet (ADR 0004).
const selectClassName =
  'h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:opacity-50 md:text-sm dark:bg-input/30'

/**
 * Names a new campaign and picks the GameSystem it is pinned to (its latest release).
 * Creating it opens the campaign; a refusal shows the API's message.
 */
export function NewCampaignForm({ gameSystems }: { gameSystems: GameSystemSummary[] }) {
  const [name, setName] = useState('')
  const [gameSystemKey, setGameSystemKey] = useState(gameSystems[0]?.gameSystemKey ?? '')
  const create = useCreateCampaign()
  const nameId = useId()
  const gameSystemId = useId()
  const valid = name.trim() !== '' && gameSystemKey !== ''

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    create.mutate({ name, gameSystemKey })
  }

  return (
    <form aria-label="New campaign" className="max-w-md space-y-4" onSubmit={handleSubmit}>
      <div className="space-y-2">
        <Label htmlFor={nameId}>Campaign name</Label>
        <Input
          id={nameId}
          value={name}
          maxLength={MAX_NAME_LENGTH}
          autoComplete="off"
          onChange={(event) => {
            setName(event.target.value)
          }}
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor={gameSystemId}>GameSystem</Label>
        <select
          id={gameSystemId}
          className={selectClassName}
          value={gameSystemKey}
          onChange={(event) => {
            setGameSystemKey(event.target.value)
          }}
        >
          {gameSystems.map((gameSystem) => (
            <option key={gameSystem.gameSystemKey} value={gameSystem.gameSystemKey}>
              {releaseLabel(gameSystem.name, gameSystem.version)}
            </option>
          ))}
        </select>
      </div>
      {create.error && (
        <p role="alert" className="text-sm text-destructive">
          {create.error.message}
        </p>
      )}
      <Button type="submit" disabled={!valid || create.isPending}>
        Create campaign
      </Button>
    </form>
  )
}
