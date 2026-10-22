import { ArrowDown, ArrowUp, Trash2 } from 'lucide-react'
import { useId } from 'react'

import { IconButton } from '@/shared/ui/icon-button'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import { TextField } from '../TextField'
import { Area } from '../steps/fields'
import { phaseModes, type PhaseDraft } from './flowDraft'
import type { PhaseAnswer } from './flowError'

/** The fields of one phase; `answer` holds what the checks and the server said about it. */
export function PhasePanel({
  phase,
  answer,
  onChange,
}: {
  phase: PhaseDraft
  answer: PhaseAnswer | undefined
  onChange: (phase: PhaseDraft) => void
}) {
  const modeId = useId()
  const fields = answer?.fields ?? {}

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="Key"
          keyField
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
          <Select
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
          </Select>
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
      {answer?.messages.map((message) => (
        <p key={message} className="text-sm text-destructive">
          {message}
        </p>
      ))}
    </div>
  )
}

/**
 * Move up, Move down and Delete of the phase at `index`, for the head of its panel. The last phase
 * cannot be deleted. While `locked` (a save is on its way) the phases cannot move or go.
 */
export function PhaseActions({
  index,
  total,
  locked,
  onMove,
  onDelete,
}: {
  index: number
  total: number
  locked: boolean
  onMove: (by: number) => void
  onDelete: () => void
}) {
  const number = String(index + 1)

  return (
    <div className="ml-auto flex gap-1">
      <IconButton
        label={`Move phase ${number} up`}
        disabled={locked || index === 0}
        onClick={() => {
          onMove(-1)
        }}
      >
        <ArrowUp />
      </IconButton>
      <IconButton
        label={`Move phase ${number} down`}
        disabled={locked || index === total - 1}
        onClick={() => {
          onMove(1)
        }}
      >
        <ArrowDown />
      </IconButton>
      <IconButton
        label={`Delete phase ${number}`}
        destructive
        disabled={locked || total === 1}
        onClick={onDelete}
      >
        <Trash2 />
      </IconButton>
    </div>
  )
}
