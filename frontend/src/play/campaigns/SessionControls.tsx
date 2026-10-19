import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { playOrdinals, titleAddsToType } from '../journal/sceneLabels'
import { PartyEditor } from './PartyEditor'
import {
  useEndSession,
  useRenameScene,
  useStartScene,
  useStartSession,
  useSwitchSceneType,
  type Campaign,
} from './useCampaigns'

/** The API's limit on a scene title (`Scene::MAX_TITLE_LENGTH`), after trimming. */
const MAX_SCENE_TITLE_LENGTH = 100

/** "Session 2 · Scene 1: Into the dark" (numbering the scenes of play only), or where play stands before that. */
function playPosition(campaign: Campaign) {
  const session = campaign.sessions.find(({ number }) => number === campaign.currentSessionNumber)
  if (session === undefined) {
    return 'No session yet'
  }
  const scene = session.scenes.find(({ number }) => number === campaign.currentSceneNumber)
  const sessionLabel = `Session ${String(session.number)}`
  if (scene === undefined) {
    return `${sessionLabel} · No scene yet`
  }
  // A title that only repeats the Scene Type ("Legwork 1") reads as the type alone.
  const title = titleAddsToType(scene.title, scene.sceneTypeName)
    ? scene.title
    : (scene.sceneTypeName ?? scene.title)
  const ordinal = playOrdinals(session.scenes).get(scene.number)
  return ordinal === undefined
    ? `${sessionLabel} · ${title}`
    : `${sessionLabel} · Scene ${String(ordinal)}: ${title}`
}

/** Where play stands, with the controls to start the next session or scene. */
export function SessionControls({ campaign }: { campaign: Campaign }) {
  const hasSession = campaign.currentSessionNumber !== null
  // While the Flow guides, the guide offers Start session (when it waits for one) and End
  // session (at the scene pick): one place for each.
  const guided = campaign.flowRun?.status === 'active'

  return (
    <section
      aria-label="Where play stands"
      className="flex min-w-0 flex-col gap-4 rounded-xl p-4 ring-1 ring-foreground/10 md:flex-row md:items-end md:justify-between"
    >
      <div className="min-w-0 space-y-2">
        <p className="font-medium break-words">{playPosition(campaign)}</p>
        {!guided &&
          (hasSession ? (
            <EndSessionButton campaignId={campaign.id} />
          ) : (
            <StartSessionButton campaignId={campaign.id} />
          ))}
        <PartyEditor campaign={campaign} />
        <SceneTypeSwitch campaign={campaign} />
        <RenameScene campaign={campaign} />
      </div>
      {/* While the Flow guides, the scenes come from it: no scene by hand. */}
      {!guided && (
        <NewSceneForm
          campaignId={campaign.id}
          hasSession={hasSession}
          sceneTypes={campaign.sceneTypes}
        />
      )}
    </section>
  )
}

/** Starts the next session; offered only when none is under way (one is open at a time). */
function StartSessionButton({ campaignId }: { campaignId: string }) {
  const startSession = useStartSession(campaignId)

  return (
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
  )
}

/** Ends the session under way, outside guided play (free play, or a paused or completed Flow). */
function EndSessionButton({ campaignId }: { campaignId: string }) {
  const endSession = useEndSession(campaignId)

  return (
    <>
      <Button
        type="button"
        variant="outline"
        disabled={endSession.isPending}
        onClick={() => {
          endSession.mutate()
        }}
      >
        End session
      </Button>
      {endSession.error && (
        <p role="alert" className="text-sm text-destructive">
          {endSession.error.message}
        </p>
      )}
    </>
  )
}

/**
 * Renames the current scene (the player's own name for it), in a small inline form. Any scene
 * can be renamed, a hook Scene too.
 */
function RenameScene({ campaign }: { campaign: Campaign }) {
  const scene = campaign.sessions
    .find(({ number }) => number === campaign.currentSessionNumber)
    ?.scenes.find(({ number }) => number === campaign.currentSceneNumber)
  if (scene === undefined) {
    return null
  }

  // Keyed by the scene, so the form closes when the scene changes under it.
  return (
    <RenameSceneForm
      key={`${String(campaign.currentSessionNumber)}-${String(scene.number)}`}
      campaignId={campaign.id}
      // A default title ("Legwork 1") is not the player's own: the field starts empty.
      title={titleAddsToType(scene.title, scene.sceneTypeName) ? scene.title : ''}
    />
  )
}

function RenameSceneForm({ campaignId, title }: { campaignId: string; title: string }) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(title)
  const renameScene = useRenameScene(campaignId)
  const inputId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    renameScene.mutate(draft, {
      onSuccess: () => {
        setEditing(false)
      },
    })
  }

  if (!editing) {
    return (
      <Button
        type="button"
        variant="outline"
        onClick={() => {
          setDraft(title)
          renameScene.reset()
          setEditing(true)
        }}
      >
        Rename scene
      </Button>
    )
  }

  return (
    <form aria-label="Rename scene" className="space-y-1" onSubmit={handleSubmit}>
      <Label htmlFor={inputId}>Scene name</Label>
      <div className="flex gap-2">
        <Input
          id={inputId}
          value={draft}
          className="min-w-0"
          maxLength={MAX_SCENE_TITLE_LENGTH}
          autoComplete="off"
          autoFocus
          onChange={(event) => {
            setDraft(event.target.value)
          }}
        />
        <Button type="submit" disabled={draft.trim() === '' || renameScene.isPending}>
          Save
        </Button>
        <Button
          type="button"
          variant="outline"
          onClick={() => {
            setEditing(false)
          }}
        >
          Cancel
        </Button>
      </div>
      {renameScene.error && (
        <p role="alert" className="text-sm text-destructive">
          {renameScene.error.message}
        </p>
      )}
    </form>
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
