import { useId } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { TextField } from './TextField'
import {
  factSlotTypes,
  type FieldErrors,
  type LabelledDraft,
  type LevelDraft,
  type TrackerDraft,
  type TrackerKind,
} from './narrativeDraft'

interface FieldsProps<Draft> {
  draft: Draft
  onChange: (draft: Draft) => void
  errors: FieldErrors
}

const keyHelp = 'Lower case letters, digits and hyphens.'

function LevelsEditor({
  levels,
  errors,
  onChange,
}: {
  levels: LevelDraft[]
  errors: Record<string, string>
  onChange: (levels: LevelDraft[]) => void
}) {
  const replace = (index: number, level: LevelDraft) => {
    onChange(levels.map((current, at) => (at === index ? level : current)))
  }
  const move = (index: number, by: number) => {
    const next = [...levels]
    const [level] = next.splice(index, 1)
    if (level) {
      next.splice(index + by, 0, level)
    }
    onChange(next)
  }

  return (
    <fieldset className="space-y-3">
      <legend className="text-sm font-medium">Levels</legend>
      <p className="text-sm text-muted-foreground">
        Named stretches of the counter. Each ends at its limit; the last one has none.
      </p>
      {levels.map((level, index) => {
        const number = String(index + 1)
        const last = index === levels.length - 1
        return (
          // The list is edited in place and has no ids: the position is the identity.
          <div key={index} className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10">
            <TextField
              label={`Level ${number} label`}
              value={level.label}
              onChange={(label) => {
                replace(index, { ...level, label })
              }}
            />
            {last ? (
              <p className="text-sm text-muted-foreground">No limit: it catches the rest.</p>
            ) : (
              <TextField
                label={`Level ${number} up to`}
                value={level.upTo}
                error={errors[`level-${String(index)}`]}
                onChange={(upTo) => {
                  replace(index, { ...level, upTo })
                }}
              />
            )}
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={index === 0}
                aria-label={`Move level ${number} up`}
                onClick={() => {
                  move(index, -1)
                }}
              >
                Up
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={last}
                aria-label={`Move level ${number} down`}
                onClick={() => {
                  move(index, 1)
                }}
              >
                Down
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label={`Delete level ${number}`}
                onClick={() => {
                  onChange(levels.filter((_, at) => at !== index))
                }}
              >
                Delete
              </Button>
            </div>
          </div>
        )
      })}
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => {
          onChange([...levels, { upTo: '', label: '', extra: {} }])
        }}
      >
        Add level
      </Button>
    </fieldset>
  )
}

export function TrackerFields({ draft, onChange, errors }: FieldsProps<TrackerDraft>) {
  const kindId = useId()
  const set = (change: Partial<TrackerDraft>) => {
    onChange({ ...draft, ...change })
  }

  return (
    <>
      <TextField
        label="Key"
        value={draft.key}
        help={keyHelp}
        onChange={(key) => {
          set({ key })
        }}
      />
      <TextField
        label="Name"
        value={draft.name}
        onChange={(name) => {
          set({ name })
        }}
      />
      <TextField
        label="Hint"
        value={draft.hint}
        onChange={(hint) => {
          set({ hint })
        }}
      />
      <div className="space-y-2">
        <Label htmlFor={kindId}>Kind</Label>
        <NativeSelect
          id={kindId}
          value={draft.kind}
          onChange={(event) => {
            set({ kind: event.target.value as TrackerKind })
          }}
        >
          <option value="counter">Counter (a number in a range)</option>
          <option value="clock">Clock (fills segment by segment)</option>
        </NativeSelect>
      </div>
      {draft.kind === 'clock' ? (
        <TextField
          label="Segments"
          value={draft.segments}
          error={errors.segments}
          onChange={(segments) => {
            set({ segments })
          }}
        />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3">
            <TextField
              label="Min"
              value={draft.min}
              error={errors.min}
              onChange={(min) => {
                set({ min })
              }}
            />
            <TextField
              label="Max"
              value={draft.max}
              error={errors.max}
              onChange={(max) => {
                set({ max })
              }}
            />
            <TextField
              label="Initial"
              value={draft.initial}
              error={errors.initial}
              onChange={(initial) => {
                set({ initial })
              }}
            />
          </div>
          <LevelsEditor
            levels={draft.levels}
            errors={errors}
            onChange={(levels) => {
              set({ levels })
            }}
          />
        </>
      )}
    </>
  )
}

function LabelledFields({
  draft,
  onChange,
  withType,
}: {
  draft: LabelledDraft
  onChange: (draft: LabelledDraft) => void
  withType: boolean
}) {
  const typeId = useId()

  return (
    <>
      <TextField
        label="Key"
        value={draft.key}
        help={keyHelp}
        onChange={(key) => {
          onChange({ ...draft, key })
        }}
      />
      <TextField
        label="Label"
        value={draft.label}
        onChange={(label) => {
          onChange({ ...draft, label })
        }}
      />
      {withType && (
        <div className="space-y-2">
          <Label htmlFor={typeId}>Type</Label>
          <NativeSelect
            id={typeId}
            value={draft.type}
            onChange={(event) => {
              onChange({ ...draft, type: event.target.value })
            }}
          >
            {factSlotTypes.map((type) => (
              <option key={type} value={type}>
                {type}
              </option>
            ))}
          </NativeSelect>
        </div>
      )}
      <TextField
        label="Hint"
        value={draft.hint}
        onChange={(hint) => {
          onChange({ ...draft, hint })
        }}
      />
    </>
  )
}

export function TagFields({ draft, onChange }: FieldsProps<LabelledDraft>) {
  return <LabelledFields draft={draft} onChange={onChange} withType={false} />
}

export function FactSlotFields({ draft, onChange }: FieldsProps<LabelledDraft>) {
  return <LabelledFields draft={draft} onChange={onChange} withType />
}
