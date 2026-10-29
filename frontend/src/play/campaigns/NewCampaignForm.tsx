import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { CreditsNotice } from './CreditsNotice'
import { releaseLabel } from './releaseLabel'
import {
  MAX_NAME_LENGTH,
  PLAY_FREELY,
  useCreateCampaign,
  type GameSystemSummary,
} from './useCampaigns'

/** The release's default Flow, else playing freely. */
function defaultFlowKey(gameSystem: GameSystemSummary | undefined) {
  return gameSystem?.flows.find((flow) => flow.default)?.key ?? PLAY_FREELY
}

/**
 * Names a new campaign and picks the GameSystem it is pinned to (its latest release) and, when
 * the release has Flows, the Flow to play along (its default one, or playing freely). It shows the credits of the chosen
 * release and the introduction of the chosen Flow, and the description of the chosen GameSystem.
 * Creating it opens the campaign; a refusal shows the API's message.
 */
export function NewCampaignForm({ gameSystems }: { gameSystems: GameSystemSummary[] }) {
  const [name, setName] = useState('')
  const [gameSystemKey, setGameSystemKey] = useState(gameSystems[0]?.gameSystemKey ?? '')
  const [flowKey, setFlowKey] = useState(defaultFlowKey(gameSystems[0]))
  const create = useCreateCampaign()
  const nameId = useId()
  const gameSystemId = useId()
  const flowId = useId()
  const chosenGameSystem = gameSystems.find(
    (gameSystem) => gameSystem.gameSystemKey === gameSystemKey,
  )
  const flows = chosenGameSystem?.flows ?? []
  const chosenFlow = flows.find((flow) => flow.key === flowKey)
  const valid = name.trim() !== '' && gameSystemKey !== ''

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    // A release without Flows has nothing to choose: the request says nothing about a Flow.
    create.mutate(
      flows.length === 0
        ? { name, gameSystemKey }
        : { name, gameSystemKey, flowKey: chosenFlow?.key ?? null },
    )
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
        <Label htmlFor={gameSystemId}>Game system</Label>
        <NativeSelect
          id={gameSystemId}
          value={gameSystemKey}
          onChange={(event) => {
            setGameSystemKey(event.target.value)
            setFlowKey(
              defaultFlowKey(
                gameSystems.find((gameSystem) => gameSystem.gameSystemKey === event.target.value),
              ),
            )
          }}
        >
          {gameSystems.map((gameSystem) => (
            <option key={gameSystem.gameSystemKey} value={gameSystem.gameSystemKey}>
              {releaseLabel(gameSystem.name, gameSystem.version)}
            </option>
          ))}
        </NativeSelect>
        {chosenGameSystem?.description && (
          <p className="text-sm text-muted-foreground">{chosenGameSystem.description}</p>
        )}
        <CreditsNotice credits={chosenGameSystem?.credits ?? null} />
      </div>
      {flows.length > 0 && (
        <div className="space-y-2">
          <Label htmlFor={flowId}>Flow</Label>
          <NativeSelect
            id={flowId}
            value={flowKey}
            onChange={(event) => {
              setFlowKey(event.target.value)
            }}
          >
            <option value={PLAY_FREELY}>Play freely</option>
            {flows.map((flow) => (
              <option key={flow.key} value={flow.key}>
                {flow.name}
              </option>
            ))}
          </NativeSelect>
          {chosenFlow?.description && (
            <p className="text-sm text-muted-foreground">{chosenFlow.description}</p>
          )}
          {chosenFlow?.introduction && (
            <p className="text-sm whitespace-pre-line">{chosenFlow.introduction}</p>
          )}
        </div>
      )}
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
