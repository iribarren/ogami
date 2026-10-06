import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { useStartScene, useStartSession, type Campaign } from './useCampaigns'

/** The API's limit on a scene title (`Scene::MAX_TITLE_LENGTH`), after trimming. */
const MAX_SCENE_TITLE_LENGTH = 100

/** "Session 2 · Scene 1: Into the dark", or where play stands before that. */
function playPosition(campaign: Campaign) {
  const session = campaign.sessions.find(({ number }) => number === campaign.currentSessionNumber)
  if (session === undefined) {
    return 'No session yet'
  }
  const scene = session.scenes.find(({ number }) => number === campaign.currentSceneNumber)
  const sessionLabel = `Session ${String(session.number)}`
  return scene === undefined
    ? `${sessionLabel} · No scene yet`
    : `${sessionLabel} · Scene ${String(scene.number)}: ${scene.title}`
}

/** Where play stands, with the controls to start the next session or scene. */
export function SessionControls({ campaign }: { campaign: Campaign }) {
  const startSession = useStartSession(campaign.id)
  const hasSession = campaign.currentSessionNumber !== null

  return (
    <section
      aria-label="Where play stands"
      className="flex flex-col gap-4 rounded-xl p-4 ring-1 ring-foreground/10 md:flex-row md:items-end md:justify-between"
    >
      <div className="space-y-2">
        <p className="font-medium">{playPosition(campaign)}</p>
        <Button
          type="button"
          variant="outline"
          disabled={startSession.isPending}
          onClick={() => {
            startSession.mutate()
          }}
        >
          Start session
        </Button>
        {startSession.error && (
          <p role="alert" className="text-sm text-destructive">
            {startSession.error.message}
          </p>
        )}
      </div>
      <NewSceneForm campaignId={campaign.id} hasSession={hasSession} />
    </section>
  )
}

function NewSceneForm({ campaignId, hasSession }: { campaignId: string; hasSession: boolean }) {
  const [title, setTitle] = useState('')
  const startScene = useStartScene(campaignId)
  const titleId = useId()
  const hintId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    startScene.mutate(
      { title },
      {
        onSuccess: () => {
          setTitle('')
        },
      },
    )
  }

  return (
    <form aria-label="New scene" className="space-y-2 md:w-80" onSubmit={handleSubmit}>
      <Label htmlFor={titleId}>Scene title</Label>
      <div className="flex gap-2">
        <Input
          id={titleId}
          value={title}
          maxLength={MAX_SCENE_TITLE_LENGTH}
          autoComplete="off"
          disabled={!hasSession}
          aria-describedby={hasSession ? undefined : hintId}
          onChange={(event) => {
            setTitle(event.target.value)
          }}
        />
        <Button type="submit" disabled={!hasSession || title.trim() === '' || startScene.isPending}>
          Start scene
        </Button>
      </div>
      {!hasSession && (
        <p id={hintId} className="text-sm text-muted-foreground">
          Start a session to begin a scene.
        </p>
      )}
      {startScene.error && (
        <p role="alert" className="text-sm text-destructive">
          {startScene.error.message}
        </p>
      )}
    </form>
  )
}
