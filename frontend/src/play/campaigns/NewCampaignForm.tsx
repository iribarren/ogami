import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { releaseLabel } from './releaseLabel'
import { useCreateCampaign, type GameSystemSummary } from './useCampaigns'

/** The API's limit on a campaign name (`Campaign::MAX_NAME_LENGTH`), after trimming. */
const MAX_NAME_LENGTH = 100

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
        <NativeSelect
          id={gameSystemId}
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
        </NativeSelect>
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
