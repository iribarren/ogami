import { Clapperboard, Layers, Plus, Route, Zap } from 'lucide-react'
import { useId, type CSSProperties, type ReactNode } from 'react'

import { Button } from '@/shared/ui/button'

import { stepKey, type StepDraft } from '../steps/stepDraft'
import { phaseHooks, type FlowDraft, type PhaseDraft, type SelectionDraft } from './flowDraft'
import type { PhaseAnswer } from './flowError'
import { hookLabels, nodePath, phaseLabel, settingsNode, type FlowNode } from './flowNode'

/** The most phases a flow may have (`ReleaseVersion2::MAX_PHASES`). */
export const MAX_PHASES = 20

interface Option {
  key: string
  name: string
}

const count = (steps: number) => (steps === 1 ? '1 step' : `${String(steps)} steps`)

/** What a step is called in the outline: its title, else its key, else its position. */
function stepName(step: StepDraft[], at: number) {
  const item = step[at]
  const stored = item?.kind === 'stored' ? item.stored : null
  const record =
    typeof stored === 'object' && stored !== null ? (stored as Record<string, unknown>) : {}
  const title = item === undefined ? '' : item.kind === 'stored' ? record.title : item.title
  return typeof title === 'string' && title.trim() !== ''
    ? title
    : (item === undefined ? '' : stepKey(item)) || `Step ${String(at + 1)}`
}

/** What a phase's selection plays: its Scene Types, or its oracle table. */
function selectionName(selection: SelectionDraft, sceneTypes: Option[], tables: Option[]) {
  const nameIn = (options: Option[], key: string) =>
    options.find((option) => option.key === key)?.name ?? key
  if (selection.rule === 'oracle') {
    return selection.table === '' ? 'No table' : nameIn(tables, selection.table)
  }
  return selection.sceneTypes.length === 0
    ? 'No Scene Type'
    : selection.sceneTypes.map((key) => nameIn(sceneTypes, key)).join(', ')
}

function Item({
  level,
  selected,
  dim = false,
  label,
  icon,
  meta,
  onSelect,
  children,
}: {
  level: 0 | 1 | 2
  selected: boolean
  dim?: boolean
  /** The accessible name, when it differs from the text. */
  label?: string
  icon: ReactNode
  meta?: ReactNode
  onSelect: () => void
  children: ReactNode
}) {
  return (
    <li>
      <button
        type="button"
        aria-current={selected ? 'true' : undefined}
        aria-label={label}
        data-dim={dim ? 'true' : undefined}
        style={{ '--level': level } as CSSProperties}
        className="outline-item outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        onClick={onSelect}
      >
        {icon}
        <span className="min-w-0 flex-1 truncate">{children}</span>
        {/* The space keeps the accessible name from running the label into the meta. */}
        {meta !== undefined && ' '}
        {meta}
      </button>
    </li>
  )
}

/** How an item selects its node. */
interface Place {
  level: 0 | 1 | 2
  selected: boolean
  onSelect: () => void
}

const dot = <span aria-hidden="true" className="size-1.5 shrink-0 rounded-full bg-current" />

/**
 * The outline of a flow: its settings, then each phase with its selection, its seven hooks and
 * their steps. Selecting an item selects that node; the selected one is `aria-current`. Adding a
 * phase is here too. While `locked` (a save is on its way) phases cannot be added.
 */
export function FlowOutline({
  draft,
  node,
  answers,
  sceneTypes,
  tables,
  locked,
  onSelect,
  onAdd,
}: {
  draft: FlowDraft
  node: FlowNode
  answers: (PhaseAnswer | undefined)[]
  sceneTypes: Option[]
  tables: Option[]
  locked: boolean
  onSelect: (node: FlowNode) => void
  onAdd: () => void
}) {
  const reasonId = useId()
  const path = nodePath(node)
  const reason =
    sceneTypes.length === 0
      ? 'Add a Scene Type first.'
      : draft.phases.length >= MAX_PHASES
        ? `A flow has at most ${String(MAX_PHASES)} phases.`
        : null
  const addDisabled = locked || reason !== null
  const at = (next: FlowNode, level: 0 | 1 | 2) => ({
    level,
    selected: nodePath(next) === path,
    onSelect: () => {
      onSelect(next)
    },
  })

  return (
    <section
      aria-labelledby={`${reasonId}-title`}
      className="min-w-0 flex-[1_1_260px] self-start panel md:max-w-[300px]"
    >
      <div className="panel-head">
        <h2 id={`${reasonId}-title`} className="font-heading text-panel-title">
          Outline
        </h2>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="ml-auto"
          disabled={addDisabled}
          aria-describedby={addDisabled && !locked ? reasonId : undefined}
          onClick={onAdd}
        >
          <Plus aria-hidden="true" />
          Add phase
        </Button>
      </div>
      <nav aria-label="Flow outline" className="p-2">
        <ul className="space-y-0.5">
          <Item {...at(settingsNode, 0)} icon={<Route aria-hidden="true" />}>
            Flow settings
          </Item>
          {draft.phases.map((phase, index) => (
            <PhaseItems
              // The outline keeps no state of its own: the position is the identity.
              key={index}
              phase={phase}
              index={index}
              label={phaseLabel(draft, index)}
              answer={answers[index]}
              selection={selectionName(phase.selection, sceneTypes, tables)}
              at={at}
            />
          ))}
        </ul>
      </nav>
      {addDisabled && !locked && (
        <p id={reasonId} className="px-4 pb-3 hint">
          {reason}
        </p>
      )}
    </section>
  )
}

function PhaseItems({
  phase,
  index,
  label,
  answer,
  selection,
  at,
}: {
  phase: PhaseDraft
  index: number
  label: string
  answer: PhaseAnswer | undefined
  selection: string
  at: (node: FlowNode, level: 0 | 1 | 2) => Place
}) {
  return (
    <>
      <Item
        {...at({ kind: 'phase', phase: index }, 0)}
        icon={<Layers aria-hidden="true" />}
        meta={<span className="hint">{phase.mode === 'loop' ? 'Loop' : 'Once'}</span>}
      >
        {label}
      </Item>
      <Item
        {...at({ kind: 'selection', phase: index }, 1)}
        icon={<Clapperboard aria-hidden="true" />}
        label={`Selection: ${selection}`}
      >
        {selection}
      </Item>
      {phaseHooks.map((hook) => {
        const steps = phase.hooks[hook] ?? []
        const errors = answer?.hooks[hook] ?? []
        return (
          <HookItems
            key={hook}
            steps={steps}
            errored={errors.flatMap(({ index: step }) => (step === null ? [] : [step]))}
            hookLabel={hookLabels[hook]}
            hook={at({ kind: 'hook', phase: index, hook }, 1)}
            step={(position) => at({ kind: 'step', phase: index, hook, step: position }, 2)}
          />
        )
      })}
    </>
  )
}

function HookItems({
  steps,
  errored,
  hookLabel,
  hook,
  step,
}: {
  steps: StepDraft[]
  /** The positions of the steps the last answer is about. */
  errored: number[]
  hookLabel: string
  hook: Place
  step: (position: number) => Place
}) {
  return (
    <>
      <Item
        {...hook}
        dim={steps.length === 0}
        label={`${hookLabel} (${count(steps.length)})`}
        icon={<Zap aria-hidden="true" />}
        meta={<span className="hint">{steps.length}</span>}
      >
        {hookLabel}
      </Item>
      {steps.map((_, position) => (
        <Item
          // Steps have no ids: the position is the identity.
          key={position}
          {...step(position)}
          icon={dot}
          meta={
            errored.includes(position) ? (
              <span className="count-pill count-pill-error">Error</span>
            ) : undefined
          }
        >
          {stepName(steps, position)}
        </Item>
      ))}
    </>
  )
}
