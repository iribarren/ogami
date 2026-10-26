import { useId, useMemo, useState, type SubmitEvent } from 'react'
import { BookOpen, List, RotateCcw } from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'

import { Alert } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import { IconTile } from '@/shared/ui/icon-tile'

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
  const id = useId()
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
  // Kept while the narrative is the same, so the step cards below can skip renders.
  const context = useMemo(() => stepContext(narrative), [narrative])
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
      <section aria-labelledby={`${id}-details`} className="panel">
        <div className="panel-head">
          <IconTile size="sm">
            <BookOpen />
          </IconTile>
          <h2 id={`${id}-details`} className="font-heading text-panel-title font-semibold">
            Details
          </h2>
        </div>
        <div className="grid gap-4 panel-body sm:grid-cols-2">
          <TextField
            label="Key"
            keyField
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
          <div className="sm:col-span-2">
            <CheckList
              legend="Oracle shortcuts"
              variant="pills"
              options={options}
              value={draft.oracles}
              error={answer.fields.oracles}
              onChange={(oracles) => {
                setDraft({ ...draft, oracles })
              }}
            />
          </div>
        </div>
      </section>
      <section aria-labelledby={`${id}-steps`} className="panel">
        <div className="panel-head">
          <IconTile size="sm">
            <List />
          </IconTile>
          <h2 id={`${id}-steps`} className="font-heading text-panel-title font-semibold">
            Steps
          </h2>
        </div>
        <div className="space-y-6 panel-body">
          {scenePartNames.map((part) => (
            <section
              key={`${part}-${String(generation)}`}
              aria-label={partTitles[part]}
              aria-describedby={`${id}-${part}-count`}
              className="space-y-3"
            >
              <div className="flex items-center gap-2">
                <h3 className="group-label">{partTitles[part]}</h3>
                <span id={`${id}-${part}-count`} className="count-pill">
                  {draft.steps[part].length}
                </span>
              </div>
              <StepListEditor
                steps={draft.steps[part]}
                context={context}
                setup={part === 'setup'}
                errors={answer.lists[part]}
                onChange={(steps) => {
                  setDraft((now) => ({ ...now, steps: { ...now.steps, [part]: steps } }))
                }}
              />
            </section>
          ))}
        </div>
      </section>
      {answer.page !== null && <Alert role="alert">{answer.page}</Alert>}
      {reloadNote !== null && <Alert role="alert">{reloadNote}</Alert>}
      <div className="panel">
        <div className="action-bar">
          {answer.conflict && (
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                void reload()
              }}
            >
              <RotateCcw aria-hidden="true" />
              Reload the latest version
            </Button>
          )}
          <Button type="submit" disabled={save.isPending}>
            Save
          </Button>
        </div>
      </div>
    </form>
  )
}
