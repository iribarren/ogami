import { useState, type SubmitEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'

import { Button } from '@/shared/ui/button'

import { StudioError } from '../../useGameSystems'
import { TextField } from '../TextField'
import { Area, CheckList } from '../steps/fields'
import { StepListEditor } from '../steps/StepListEditor'
import { stepContext } from '../steps/stepContext'
import { clientStepErrors } from '../steps/stepError'
import {
  useGameSystemNarrative,
  useSaveNarrativeItem,
  type GameSystemNarrative,
} from '../useGameSystemNarrative'
import {
  scenePartNames,
  sceneTypeToDraft,
  sceneTypeToItem,
  type SceneTypeDraft,
} from './sceneTypeDraft'
import { noAnswer, sceneTypeAnswer, type SceneTypeAnswer } from './sceneTypeError'

const partTitles = { setup: 'Setup', play: 'Play', closing: 'Closing' }

/**
 * The form of one Scene Type. It is filled once, from `initial` read at `version`; a save sends
 * that version, and a reload replaces the draft and the version together or neither.
 */
export function SceneTypeEditor({
  gameSystemKey,
  narrative,
  original,
  initial,
  version,
}: {
  gameSystemKey: string
  narrative: GameSystemNarrative
  /** The key the Scene Type is stored under. */
  original: string
  initial: SceneTypeDraft
  version: number
}) {
  const [draft, setDraft] = useState(initial)
  const [readAt, setReadAt] = useState(version)
  const [current, setCurrent] = useState(original)
  const [answer, setAnswer] = useState<SceneTypeAnswer>(noAnswer)
  // Why a reload did not replace the draft; the old version is kept then, so a save still conflicts.
  const [reloadNote, setReloadNote] = useState<string | null>(null)
  // Remounts the step lists on a reload: their cards keep no state of the draft they replace.
  const [generation, setGeneration] = useState(0)
  const save = useSaveNarrativeItem(gameSystemKey)
  const latest = useGameSystemNarrative(gameSystemKey)
  const navigate = useNavigate()
  const context = stepContext(narrative)
  const options = [...context.tables, ...context.likelihood]

  async function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = sceneTypeToItem(draft)
    if ('errors' in result) {
      const lists = { ...noAnswer.lists }
      for (const part of scenePartNames) {
        lists[part] = clientStepErrors(result.errors[part])
      }
      setAnswer({ ...noAnswer, lists })
      return
    }
    setAnswer(noAnswer)
    setReloadNote(null)
    const key = String(result.item.key)
    try {
      const saved = await save.mutateAsync({
        expectedVersion: readAt,
        section: 'scene-types',
        original: current,
        item: result.item,
      })
      setReadAt(saved.version)
      setCurrent(key)
    } catch (error) {
      const index = narrative.sceneTypes.findIndex((item) => item.key === current)
      const message = error instanceof Error ? error.message : 'Saving the item failed.'
      setAnswer({
        ...sceneTypeAnswer(message, index),
        conflict: error instanceof StudioError && error.status === 409,
      })
      return
    }
    if (key !== current) {
      try {
        await navigate({
          to: '/studio/game-systems/$key/scene-types/$sceneType',
          params: { key: gameSystemKey, sceneType: key },
          replace: true,
        })
      } catch {
        // The save went through; if the router refuses, the URL decides what the page shows.
      }
    }
  }

  async function reload() {
    const result = await latest.refetch()
    if (result.isError || result.data === undefined) {
      setReloadNote("Couldn't reload the latest version. Try again.")
      return
    }
    // The Scene Type and its version come from the same read, and the version is only taken with a
    // replaced draft: otherwise a later save would overwrite what was not seen.
    const stored = result.data.sceneTypes.find((item) => item.key === current)
    const reloaded = stored === undefined ? null : sceneTypeToDraft(stored.definition)
    if (reloaded === null) {
      setReloadNote(
        stored === undefined
          ? `The Scene Type ${current} no longer exists. Your changes are kept; go back to the list.`
          : 'The Scene Type changed to a shape this editor cannot read. Your changes are kept; the next save still conflicts.',
      )
      return
    }
    setDraft(reloaded)
    setReadAt(result.data.version)
    setReloadNote(null)
    setAnswer(noAnswer)
    setGeneration((count) => count + 1)
  }

  return (
    <form
      aria-label="Scene Type"
      className="space-y-6"
      noValidate
      onSubmit={(event) => {
        void submit(event)
      }}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="Key"
          value={draft.key}
          help="Renaming a Scene Type the draft uses is refused."
          error={answer.fields.key}
          onChange={(key) => {
            setDraft({ ...draft, key })
          }}
        />
        <TextField
          label="Name"
          value={draft.name}
          error={answer.fields.name}
          onChange={(name) => {
            setDraft({ ...draft, name })
          }}
        />
      </div>
      <Area
        label="Purpose"
        value={draft.purpose}
        error={answer.fields.purpose}
        onChange={(purpose) => {
          setDraft({ ...draft, purpose })
        }}
      />
      <Area
        label="Tips"
        value={draft.tips}
        error={answer.fields.tips}
        onChange={(tips) => {
          setDraft({ ...draft, tips })
        }}
      />
      <div className="space-y-1">
        <CheckList
          legend="Oracle shortcuts"
          options={options}
          value={draft.oracles}
          onChange={(oracles) => {
            setDraft({ ...draft, oracles })
          }}
        />
        {answer.fields.oracles && (
          <p className="text-sm text-destructive">{answer.fields.oracles}</p>
        )}
      </div>
      {scenePartNames.map((part) => (
        <section
          key={`${part}-${String(generation)}`}
          aria-label={partTitles[part]}
          className="space-y-3"
        >
          <h2 className="font-heading text-lg font-semibold">{partTitles[part]}</h2>
          <StepListEditor
            steps={draft.steps[part]}
            context={context}
            errors={answer.lists[part]}
            onChange={(steps) => {
              setDraft({ ...draft, steps: { ...draft.steps, [part]: steps } })
            }}
          />
        </section>
      ))}
      {answer.page !== null && (
        <p role="alert" className="text-sm text-destructive">
          {answer.page}
        </p>
      )}
      {reloadNote !== null && (
        <p role="alert" className="text-sm text-destructive">
          {reloadNote}
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={save.isPending}>
          Save
        </Button>
        {answer.conflict && (
          <Button
            type="button"
            variant="outline"
            onClick={() => {
              void reload()
            }}
          >
            Reload the latest version
          </Button>
        )}
      </div>
    </form>
  )
}
