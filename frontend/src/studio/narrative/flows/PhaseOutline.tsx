import { useId } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { TextField } from '../TextField'
import { Area } from '../steps/fields'
import { moveItem } from '../steps/draftParts'
import type { StepContext } from '../steps/stepContext'
import { newPhase, phaseModes, type PhaseDraft } from './flowDraft'
import type { PhaseAnswer } from './flowError'
import { PhaseHooks } from './PhaseHooks'
import { SelectionEditor } from './SelectionEditor'

/** The most phases a flow may have (`ReleaseVersion2::MAX_PHASES`). */
export const MAX_PHASES = 20

function PhaseCard({
  phase,
  index,
  total,
  locked,
  context,
  answer,
  onChange,
  onMove,
  onDelete,
}: {
  phase: PhaseDraft
  index: number
  total: number
  locked: boolean
  context: StepContext
  answer: PhaseAnswer | undefined
  onChange: (phase: PhaseDraft) => void
  onMove: (by: number) => void
  onDelete: () => void
}) {
  const modeId = useId()
  const number = String(index + 1)
  const fields = answer?.fields ?? {}

  return (
    <li
      role="group"
      aria-label={`Phase ${number}`}
      className="space-y-3 rounded-xl p-3 ring-1 ring-foreground/10"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium">
          {number}. {phase.name.trim() === '' ? phase.key : phase.name}
        </p>
        <div className="flex gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={locked || index === 0}
            aria-label={`Move phase ${number} up`}
            onClick={() => {
              onMove(-1)
            }}
          >
            Up
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={locked || index === total - 1}
            aria-label={`Move phase ${number} down`}
            onClick={() => {
              onMove(1)
            }}
          >
            Down
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={locked || total === 1}
            aria-label={`Delete phase ${number}`}
            onClick={onDelete}
          >
            Delete
          </Button>
        </div>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="Key"
          value={phase.key}
          error={fields.key}
          onChange={(key) => {
            onChange({ ...phase, key })
          }}
        />
        <TextField
          label="Name"
          value={phase.name}
          error={fields.name}
          onChange={(name) => {
            onChange({ ...phase, name })
          }}
        />
        <TextField
          label="Act"
          value={phase.act}
          error={fields.act}
          onChange={(act) => {
            onChange({ ...phase, act })
          }}
        />
        <div className="space-y-2">
          <Label htmlFor={modeId}>Mode</Label>
          <NativeSelect
            id={modeId}
            value={phase.mode}
            onChange={(event) => {
              onChange({
                ...phase,
                mode: phaseModes.find((mode) => mode === event.target.value) ?? phase.mode,
              })
            }}
          >
            <option value="once">Once</option>
            <option value="loop">Loop</option>
          </NativeSelect>
          {fields.mode && <p className="text-sm text-destructive">{fields.mode}</p>}
        </div>
      </div>
      <Area
        label="Description"
        value={phase.description}
        error={fields.description}
        onChange={(description) => {
          onChange({ ...phase, description })
        }}
      />
      <SelectionEditor
        selection={phase.selection}
        sceneTypes={context.sceneTypes ?? []}
        tables={context.tables}
        messages={answer?.selection ?? []}
        onChange={(selection) => {
          onChange({ ...phase, selection })
        }}
      />
      <fieldset disabled={locked}>
        <PhaseHooks phase={phase} context={context} answer={answer} onChange={onChange} />
      </fieldset>
      {answer?.messages.map((message) => (
        <p key={message} className="text-sm text-destructive">
          {message}
        </p>
      ))}
    </li>
  )
}

/**
 * The phases of a flow as an ordered list of cards: add, move, delete. A message about a phase
 * moves, or goes, with it, so it stays on the card it is about. While `locked` (a save is on its
 * way) the phases cannot be added, moved or deleted: the server's messages are about the phases
 * that were sent, so they must still be the phases on the page when they arrive.
 */
export function PhaseOutline({
  phases,
  answers,
  sceneType,
  context,
  locked = false,
  onChange,
}: {
  phases: PhaseDraft[]
  answers: (PhaseAnswer | undefined)[]
  /** The Scene Type a new phase starts with; null when the draft has none. */
  sceneType: string | null
  /** What the selection and the hooks' steps pick from. */
  context: StepContext
  locked?: boolean
  onChange: (phases: PhaseDraft[], answers: (PhaseAnswer | undefined)[]) => void
}) {
  const reasonId = useId()
  // `answers` has holes: spread to the phases' length so that moving one keeps the others in place.
  const aligned = phases.map((_, index) => answers[index])
  const addDisabled = locked || sceneType === null || phases.length >= MAX_PHASES

  return (
    <div className="space-y-3">
      <ol className="space-y-3">
        {phases.map((phase, index) => (
          <PhaseCard
            // The cards keep no state of their own, so the position is a fine identity.
            key={index}
            phase={phase}
            index={index}
            total={phases.length}
            locked={locked}
            context={context}
            answer={aligned[index]}
            onChange={(changed) => {
              onChange(
                phases.map((current, at) => (at === index ? changed : current)),
                aligned,
              )
            }}
            onMove={(by) => {
              onChange(moveItem(phases, index, by), moveItem(aligned, index, by))
            }}
            onDelete={() => {
              onChange(
                phases.filter((_, at) => at !== index),
                aligned.filter((_, at) => at !== index),
              )
            }}
          />
        ))}
      </ol>
      <div className="flex flex-wrap items-center gap-3">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={addDisabled}
          aria-describedby={addDisabled && !locked ? reasonId : undefined}
          onClick={() => {
            if (sceneType !== null) {
              onChange([...phases, newPhase(phases, sceneType)], aligned)
            }
          }}
        >
          Add phase
        </Button>
        {addDisabled && !locked && (
          <p id={reasonId} className="text-sm text-muted-foreground">
            {sceneType === null
              ? 'Add a Scene Type first.'
              : `A flow has at most ${String(MAX_PHASES)} phases.`}
          </p>
        )}
      </div>
    </div>
  )
}
