import { useId, useState, type SubmitEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { StudioError } from '../../useGameSystems'
import { TextField } from '../TextField'
import { Area, CheckList } from '../steps/fields'
import { stepContext } from '../steps/stepContext'
import {
  useGameSystemNarrative,
  useSaveNarrativeItem,
  type GameSystemNarrative,
} from '../useGameSystemNarrative'
import { flowToDraft, flowToItem, flowViews, type FlowDraft } from './flowDraft'
import { flowAnswer, flowChecks, noAnswer, type FlowAnswer } from './flowError'
import { PhaseOutline } from './PhaseOutline'

/**
 * The form of one flow. It is filled once, from `initial` read at `version`; a save sends that
 * version, and a reload replaces the draft and the version together or neither.
 */
export function FlowEditor({
  gameSystemKey,
  narrative,
  original,
  initial,
  version,
}: {
  gameSystemKey: string
  narrative: GameSystemNarrative
  /** The key the flow is stored under. */
  original: string
  initial: FlowDraft
  version: number
}) {
  const [draft, setDraft] = useState(initial)
  const [readAt, setReadAt] = useState(version)
  const [current, setCurrent] = useState(original)
  const [answer, setAnswer] = useState<FlowAnswer>(noAnswer)
  // Why a reload did not replace the draft; the old version is kept then, so a save still conflicts.
  const [reloadNote, setReloadNote] = useState<string | null>(null)
  // Remounts the phases on a reload: their hook lists keep no state of the draft they replace.
  const [generation, setGeneration] = useState(0)
  const viewId = useId()
  const save = useSaveNarrativeItem(gameSystemKey)
  const latest = useGameSystemNarrative(gameSystemKey)
  const navigate = useNavigate()
  const context = stepContext(narrative)
  const options = [...context.tables, ...context.likelihood]

  async function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = flowToItem(draft)
    const refused = flowChecks(draft, 'errors' in result ? result.errors : {})
    if (refused !== null || 'errors' in result) {
      setAnswer(refused ?? noAnswer)
      return
    }
    setAnswer(noAnswer)
    setReloadNote(null)
    const { item } = result
    const key = String(item.key)
    try {
      const saved = await save.mutateAsync({
        expectedVersion: readAt,
        section: 'flows',
        original: current,
        item,
      })
      setReadAt(saved.version)
      setCurrent(key)
    } catch (error) {
      const index = narrative.flows.findIndex((stored) => stored.key === current)
      const message = error instanceof Error ? error.message : 'Saving the item failed.'
      setAnswer({
        ...flowAnswer(message, index, draft.phases.length),
        conflict: error instanceof StudioError && error.status === 409,
      })
      return
    }
    if (key !== current) {
      try {
        await navigate({
          to: '/studio/game-systems/$key/flows/$flow',
          params: { key: gameSystemKey, flow: key },
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
    // The flow and its version come from the same read, and the version is only taken with a
    // replaced draft: otherwise a later save would overwrite what was not seen.
    const stored = result.data.flows.find((item) => item.key === current)
    const reloaded = stored === undefined ? null : flowToDraft(stored.definition)
    if (reloaded === null) {
      setReloadNote(
        stored === undefined
          ? `The flow ${current} no longer exists. Your changes are kept; go back to the list.`
          : 'The flow changed to a shape this editor cannot read. Your changes are kept; the next save still conflicts.',
      )
      return
    }
    setDraft(reloaded)
    setReadAt(result.data.version)
    setReloadNote(null)
    setAnswer(noAnswer)
    setGeneration((count) => count + 1)
  }

  const field = (name: string) => answer.fields[name]

  return (
    <form
      aria-label="Flow"
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
          error={field('key')}
          onChange={(key) => {
            setDraft({ ...draft, key })
          }}
        />
        <TextField
          label="Name"
          value={draft.name}
          error={field('name')}
          onChange={(name) => {
            setDraft({ ...draft, name })
          }}
        />
      </div>
      <Area
        label="Description"
        value={draft.description}
        error={field('description')}
        onChange={(description) => {
          setDraft({ ...draft, description })
        }}
      />
      <Area
        label="Introduction"
        value={draft.introduction}
        error={field('introduction')}
        onChange={(introduction) => {
          setDraft({ ...draft, introduction })
        }}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor={viewId}>Default view</Label>
          <NativeSelect
            id={viewId}
            value={draft.defaultView}
            onChange={(event) => {
              setDraft({
                ...draft,
                defaultView: flowViews.find((view) => view === event.target.value) ?? 'focus',
              })
            }}
          >
            <option value="focus">Focus</option>
            <option value="journal">Journal</option>
          </NativeSelect>
          {field('defaultView') && (
            <p className="text-sm text-destructive">{field('defaultView')}</p>
          )}
        </div>
        <div className="space-y-1">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={draft.default}
              onChange={(event) => {
                setDraft({ ...draft, default: event.target.checked })
              }}
            />
            Default flow
          </label>
          <p className="text-sm text-muted-foreground">
            Saving it as the default clears the flag on the other flows.
          </p>
          {field('default') && <p className="text-sm text-destructive">{field('default')}</p>}
        </div>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1">
          <CheckList
            legend="Oracle shortcuts"
            options={options}
            value={draft.oracles}
            onChange={(oracles) => {
              setDraft({ ...draft, oracles })
            }}
          />
          {field('oracles') && <p className="text-sm text-destructive">{field('oracles')}</p>}
        </div>
        <div className="space-y-1">
          <CheckList
            legend="Trackers"
            options={context.trackers}
            value={draft.trackers}
            onChange={(trackers) => {
              setDraft({ ...draft, trackers })
            }}
          />
          {field('trackers') && <p className="text-sm text-destructive">{field('trackers')}</p>}
        </div>
      </div>
      <section aria-label="Phases" className="space-y-3">
        <h2 className="font-heading text-lg font-semibold">Phases</h2>
        <PhaseOutline
          key={generation}
          phases={draft.phases}
          answers={answer.phases}
          sceneType={narrative.sceneTypes[0]?.key ?? null}
          context={context}
          locked={save.isPending}
          onChange={(phases, phaseAnswers) => {
            setDraft({ ...draft, phases })
            setAnswer({ ...answer, phases: phaseAnswers })
          }}
        />
      </section>
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
