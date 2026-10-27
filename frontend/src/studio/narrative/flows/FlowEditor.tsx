import { ChevronRight, Clapperboard, Layers, Route, Zap } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode, type SubmitEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'

import { Alert } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import { IconTile } from '@/shared/ui/icon-tile'
import { SaveState, type SaveStateValue } from '@/shared/ui/save-state'

import { StudioError } from '../../useGameSystems'
import { moveItem } from '../steps/draftParts'
import { useGameSystemChecks } from '../../checks/useGameSystemChecks'
import { stepContext } from '../steps/stepContext'
import {
  useGameSystemNarrative,
  useSaveNarrativeItem,
  type GameSystemNarrative,
} from '../useGameSystemNarrative'
import { flowToDraft, flowToItem, newPhase, type FlowDraft, type PhaseDraft } from './flowDraft'
import { EnableOnFlow } from './EnableOnFlow'
import { flowAnswer, answerCount, flowChecks, noAnswer, type FlowAnswer } from './flowError'
import {
  enableOnFlow,
  flowScope,
  missingForFlow,
  missingForSelection,
  missingForStep,
  nothingMissing,
  type Missing,
} from './flowEnable'
import { answerNode, hookLabels, nodePath, nodeTrail, resolveNode, type FlowNode } from './flowNode'
import { FlowOutline } from './FlowOutline'
import { FlowSettings } from './FlowSettings'
import { HookPanel } from './HookPanel'
import { PhaseActions, PhasePanel } from './PhasePanel'
import { SelectionEditor } from './SelectionEditor'

/**
 * The editor of one flow: its outline, and the form of the selected node. It is filled once, from
 * `initial` read at `version`; a save sends that version, and a reload replaces the draft and the
 * version together or neither. `selected` is the `node` search param as the URL has it.
 */
export function FlowEditor({
  gameSystemKey,
  narrative,
  original,
  initial,
  version,
  selected,
}: {
  gameSystemKey: string
  narrative: GameSystemNarrative
  /** The key the flow is stored under. */
  original: string
  initial: FlowDraft
  version: number
  selected: string | undefined
}) {
  const [draft, setDraft] = useState(initial)
  const [readAt, setReadAt] = useState(version)
  const [current, setCurrent] = useState(original)
  const [answer, setAnswer] = useState<FlowAnswer>(noAnswer)
  // Why a reload did not replace the draft; the old version is kept then, so a save still conflicts.
  const [reloadNote, setReloadNote] = useState<string | null>(null)
  // The draft as it was last loaded or saved: the form is unsaved while it differs.
  const [baseline, setBaseline] = useState(() => JSON.stringify(initial))
  const [savedAt, setSavedAt] = useState<Date | null>(null)
  // The node whose first invalid field takes focus once it is shown ("Go to the first").
  const [focusAt, setFocusAt] = useState<{ path: string; id: number } | null>(null)
  const focused = useRef(0)
  const body = useRef<HTMLDivElement>(null)
  // Remounts the phases on a reload: their hook lists keep no state of the draft they replace.
  const [generation, setGeneration] = useState(0)
  const save = useSaveNarrativeItem(gameSystemKey)
  const latest = useGameSystemNarrative(gameSystemKey)
  const navigate = useNavigate()
  // Kept while the narrative is the same, so the step cards below can skip renders.
  // The checks say which special results a check step may branch on; until they load, none is offered.
  const checks = useGameSystemChecks(gameSystemKey)
  const context = useMemo(() => stepContext(narrative, checks.data), [narrative, checks.data])
  const node = resolveNode(draft, selected)
  const path = nodePath(node)
  useEffect(() => {
    // Once per request: coming back to the node later must not take the focus again.
    if (focusAt?.path === path && focusAt.id !== focused.current) {
      focused.current = focusAt.id
      body.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
    }
  }, [focusAt, path])

  /** Selects a node; `replace` for the changes the editor makes itself, so back keeps to what the person chose. */
  function select(next: FlowNode, replace = false) {
    void navigate({
      to: '/studio/game-systems/$key/flows/$flow',
      params: { key: gameSystemKey, flow: current },
      search: { node: next.kind === 'settings' ? undefined : nodePath(next) },
      replace,
    })
  }

  /** Shows what a refused save is about: the answer on its fields, the first node it names selected. */
  function refuse(next: FlowAnswer) {
    setAnswer(next)
    const target = answerNode(next)
    if (target !== null) {
      select(target, true)
    }
  }

  /** Selects the node of the first error and puts the focus on its first invalid field. */
  function goToFirst() {
    const target = answerNode(answer)
    if (target === null) {
      return
    }
    setFocusAt({ path: nodePath(target), id: (focusAt?.id ?? 0) + 1 })
    select(target, true)
  }

  async function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = flowToItem(draft)
    const refused = flowChecks(draft, 'errors' in result ? result.errors : {})
    if (refused !== null || 'errors' in result) {
      refuse(refused ?? noAnswer)
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
      setBaseline(JSON.stringify(draft))
      setSavedAt(new Date())
    } catch (error) {
      const index = narrative.flows.findIndex((stored) => stored.key === current)
      const message = error instanceof Error ? error.message : 'Saving the item failed.'
      refuse({
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
          search: { node: selected },
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
    setBaseline(JSON.stringify(reloaded))
    setReadAt(result.data.version)
    setReloadNote(null)
    setAnswer(noAnswer)
    setGeneration((count) => count + 1)
  }

  const phases = draft.phases
  const scope = flowScope(narrative, context)
  const count = answerCount(answer)
  const saveState: SaveStateValue | null = save.isPending
    ? { kind: 'saving' }
    : count > 0
      ? {
          kind: 'failed',
          errors: count,
          onGoToFirst: answerNode(answer) === null ? undefined : goToFirst,
        }
      : JSON.stringify(draft) !== baseline
        ? { kind: 'unsaved' }
        : savedAt === null
          ? null
          : { kind: 'saved', at: savedAt }
  /** Adds what a node uses to the flow's lists; the rest of the draft stays as it is. */
  const enable = (missing: Missing) => () => {
    setDraft(enableOnFlow(draft, missing))
  }
  // `answer.phases` has holes: spread to the phases' length so that moving one keeps the others in place.
  const aligned = phases.map((_, index) => answer.phases[index])
  const locked = save.isPending

  function changePhases(next: PhaseDraft[], answers = aligned) {
    setDraft({ ...draft, phases: next })
    setAnswer({ ...answer, phases: answers })
  }

  /** The panel of the selected node: its icon, title, actions and form. */
  function panel(): { icon: ReactNode; title: string; actions?: ReactNode; body: ReactNode } {
    const [title = ''] = nodeTrail(draft, node).slice(-1)
    if (node.kind === 'settings') {
      return {
        icon: <Route />,
        title,
        body: (
          <FlowSettings
            draft={draft}
            context={context}
            errors={answer.fields}
            missing={missingForFlow(draft, scope)}
            onEnable={enable(missingForFlow(draft, scope))}
            onChange={setDraft}
          />
        ),
      }
    }
    const index = node.phase
    const phase = phases[index]
    if (phase === undefined) {
      return { icon: <Route />, title, body: null }
    }
    const change = (changed: PhaseDraft) => {
      changePhases(phases.map((current, at) => (at === index ? changed : current)))
    }
    switch (node.kind) {
      case 'phase':
        return {
          icon: <Layers />,
          title,
          actions: (
            <PhaseActions
              index={index}
              total={phases.length}
              locked={locked}
              onMove={(by) => {
                changePhases(moveItem(phases, index, by), moveItem(aligned, index, by))
                select({ kind: 'phase', phase: index + by }, true)
              }}
              onDelete={() => {
                changePhases(
                  phases.filter((_, at) => at !== index),
                  aligned.filter((_, at) => at !== index),
                )
                select({ kind: 'phase', phase: Math.max(0, index - 1) }, true)
              }}
            />
          ),
          body: <PhasePanel phase={phase} answer={aligned[index]} onChange={change} />,
        }
      case 'selection':
        return {
          icon: <Clapperboard />,
          title,
          body: (
            <div className="space-y-4">
              <EnableOnFlow
                missing={missingForSelection(draft, phase.selection, scope)}
                context={context}
                intro="These Scene Types use oracle shortcuts the flow does not enable:"
                onEnable={enable(missingForSelection(draft, phase.selection, scope))}
              />
              <SelectionEditor
                selection={phase.selection}
                sceneTypes={context.sceneTypes ?? []}
                tables={context.tables}
                messages={aligned[index]?.selection ?? []}
                onChange={(selection) => {
                  change({ ...phase, selection })
                }}
              />
            </div>
          ),
        }
      case 'hook':
      case 'step': {
        const selectedStep = node.kind === 'step' ? phase.hooks[node.hook]?.[node.step] : undefined
        const missing =
          selectedStep === undefined ? nothingMissing : missingForStep(draft, selectedStep, scope)
        return {
          icon: <Zap />,
          title: hookLabels[node.hook],
          body: (
            <div className="space-y-4">
              <EnableOnFlow
                missing={missing}
                context={context}
                intro="This step uses what the flow does not enable:"
                onEnable={enable(missing)}
              />
              <HookPanel
                phase={phase}
                hook={node.hook}
                step={node.kind === 'step' ? node.step : undefined}
                context={context}
                answer={aligned[index]}
                locked={locked}
                onChange={change}
              />
            </div>
          ),
        }
      }
    }
  }

  const shown = panel()
  const trail = nodeTrail(draft, node)
  // A hook and its steps share one list, so moving between them keeps its state.
  const listKey =
    node.kind === 'hook' || node.kind === 'step'
      ? nodePath({ ...node, kind: 'hook' })
      : nodePath(node)

  return (
    <div className="flex flex-wrap gap-6">
      <FlowOutline
        draft={draft}
        node={node}
        flowErrored={Object.keys(answer.fields).length > 0}
        answers={aligned}
        sceneTypes={context.sceneTypes ?? []}
        tables={context.tables}
        locked={locked}
        onSelect={select}
        onAdd={() => {
          changePhases([...phases, newPhase(phases)])
          select({ kind: 'phase', phase: phases.length }, true)
        }}
      />
      <form
        aria-label="Flow"
        className="min-w-0 flex-[999_1_560px] space-y-4"
        noValidate
        onSubmit={(event) => {
          void submit(event)
        }}
      >
        <nav aria-label="Breadcrumb">
          <ol className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
            {trail.map((label, at) => (
              <li key={at} className="flex items-center gap-1">
                {at > 0 && <ChevronRight aria-hidden="true" className="size-3.5" />}
                {label}
              </li>
            ))}
          </ol>
        </nav>
        <section aria-labelledby="flow-node-title" className="panel">
          <div className="panel-head">
            <IconTile size="sm">{shown.icon}</IconTile>
            <h2 id="flow-node-title" className="font-heading text-panel-title">
              {shown.title}
            </h2>
            {shown.actions}
          </div>
          <div ref={body} key={`${String(generation)}:${listKey}`} className="panel-body">
            {shown.body}
          </div>
        </section>
        {answer.page !== null && <Alert>{answer.page}</Alert>}
        {reloadNote !== null && <Alert>{reloadNote}</Alert>}
        <div className="sticky bottom-4 panel">
          <div className="action-bar">
            {saveState && <SaveState state={saveState} />}
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
            <Button type="submit" disabled={save.isPending}>
              Save
            </Button>
          </div>
        </div>
      </form>
    </div>
  )
}
