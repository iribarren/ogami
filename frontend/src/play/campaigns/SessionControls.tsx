import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { PartyEditor } from './PartyEditor'
import { useStartScene, useStartSession, useSwitchSceneType, type Campaign } from './useCampaigns'

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
  // While the Flow waits for a session, the guide offers Start session: one place for it.
  const guideStarts = campaign.flowRun?.status === 'active' && campaign.flowRun.waitsForSession

  return (
    <section
      aria-label="Where play stands"
      className="flex min-w-0 flex-col gap-4 rounded-xl p-4 ring-1 ring-foreground/10 md:flex-row md:items-end md:justify-between"
    >
      <div className="min-w-0 space-y-2">
        <p className="font-medium break-words">{playPosition(campaign)}</p>
        {!guideStarts && (
          <>
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
          </>
        )}
        <PartyEditor campaign={campaign} />
        <SceneTypeSwitch campaign={campaign} />
      </div>
      <NewSceneForm
        campaignId={campaign.id}
        hasSession={hasSession}
        sceneTypes={campaign.sceneTypes}
      />
    </section>
  )
}

/**
 * Switches the current scene to another Scene Type by hand, when the release has Scene Types.
 * A hook Scene has none to switch.
 */
function SceneTypeSwitch({ campaign }: { campaign: Campaign }) {
  const switchSceneType = useSwitchSceneType(campaign.id)
  const selectId = useId()
  const scene = campaign.sessions
    .find(({ number }) => number === campaign.currentSessionNumber)
    ?.scenes.find(({ number }) => number === campaign.currentSceneNumber)
  if (scene?.kind !== 'scene' || campaign.sceneTypes.length === 0) {
    return null
  }

  return (
    <div className="space-y-1">
      <Label htmlFor={selectId}>Scene Type</Label>
      <NativeSelect
        id={selectId}
        value={scene.sceneType ?? ''}
        disabled={switchSceneType.isPending}
        onChange={(event) => {
          switchSceneType.mutate(event.target.value)
        }}
      >
        {scene.sceneType === null && (
          <option value="" disabled>
            None
          </option>
        )}
        {campaign.sceneTypes.map((sceneType) => (
          <option key={sceneType.key} value={sceneType.key}>
            {sceneType.name}
          </option>
        ))}
      </NativeSelect>
      {switchSceneType.error && (
        <p role="alert" className="text-sm text-destructive">
          {switchSceneType.error.message}
        </p>
      )}
    </div>
  )
}

function NewSceneForm({
  campaignId,
  hasSession,
  sceneTypes,
}: {
  campaignId: string
  hasSession: boolean
  sceneTypes: Campaign['sceneTypes']
}) {
  const [title, setTitle] = useState('')
  const [sceneType, setSceneType] = useState('')
  const startScene = useStartScene(campaignId)
  const titleId = useId()
  const typeId = useId()
  const hintId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    // With a Scene Type the title is optional: the scene is named after the type.
    const request =
      sceneType === '' ? { title } : { sceneType, ...(title.trim() === '' ? {} : { title }) }
    startScene.mutate(request, {
      onSuccess: () => {
        setTitle('')
        setSceneType('')
      },
    })
  }

  return (
    <form aria-label="New scene" className="min-w-0 space-y-2 md:w-80" onSubmit={handleSubmit}>
      <Label htmlFor={titleId}>Scene title</Label>
      <div className="flex gap-2">
        <Input
          id={titleId}
          value={title}
          className="min-w-0"
          maxLength={MAX_SCENE_TITLE_LENGTH}
          autoComplete="off"
          disabled={!hasSession}
          aria-describedby={hasSession ? undefined : hintId}
          onChange={(event) => {
            setTitle(event.target.value)
          }}
        />
        <Button
          type="submit"
          disabled={
            !hasSession || (title.trim() === '' && sceneType === '') || startScene.isPending
          }
        >
          Start scene
        </Button>
      </div>
      {sceneTypes.length > 0 && (
        <div className="space-y-1">
          <Label htmlFor={typeId}>Scene Type (optional)</Label>
          <NativeSelect
            id={typeId}
            value={sceneType}
            disabled={!hasSession}
            onChange={(event) => {
              setSceneType(event.target.value)
            }}
          >
            <option value="">None</option>
            {sceneTypes.map((type) => (
              <option key={type.key} value={type.key}>
                {type.name}
              </option>
            ))}
          </NativeSelect>
        </div>
      )}
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
