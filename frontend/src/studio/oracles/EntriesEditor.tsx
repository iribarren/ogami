import { useId } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import { KeyHint } from '../KeyHint'
import { EffectsEditor } from '../narrative/steps/EffectsEditor'
import { aboutEffects, type StepError } from '../narrative/steps/stepError'
import type { StepContext } from '../narrative/steps/stepContext'
import {
  effectsEditable,
  parseInteger,
  sceneTypeEditable,
  type Coverage,
  type EntryDraft,
  type Span,
} from './tableDraft'

const span = ({ from, to }: Span) => (from === to ? String(from) : `${String(from)}–${String(to)}`)

/** What the entries' ranges leave uncovered or cover twice, as the dice would roll them. */
export function CoverageSummary({ coverage }: { coverage: Coverage }) {
  const problems = [
    coverage.gaps.length > 0 && `No entry covers ${coverage.gaps.map(span).join(', ')}.`,
    coverage.overlaps.length > 0 &&
      `More than one entry covers ${coverage.overlaps.map(span).join(', ')}.`,
  ].filter((problem) => problem !== false)
  return problems.length === 0 ? (
    <p className="text-sm text-muted-foreground">Every total is covered exactly once.</p>
  ) : (
    <ul aria-label="Coverage problems" className="text-sm text-destructive">
      {problems.map((problem) => (
        <li key={problem}>{problem}</li>
      ))}
    </ul>
  )
}

/**
 * The Scene Type an entry starts, picked from the draft's. A stored key the draft no longer has
 * stays selected and is flagged; a stored value that is not text is shown, never replaced.
 */
function SceneTypeField({
  label,
  entry,
  context,
  error,
  onChange,
}: {
  label: string
  entry: EntryDraft
  context: StepContext
  error: string | undefined
  onChange: (sceneType: string) => void
}) {
  const problemId = useId()
  const options = context.sceneTypes ?? []
  const unknown = entry.sceneType !== '' && !options.some(({ key }) => key === entry.sceneType)
  if (!sceneTypeEditable(entry.original)) {
    return (
      <p className="text-sm text-destructive">
        {label} Scene Type is not text; it is kept as stored.
      </p>
    )
  }
  return (
    <div className="min-w-48 space-y-1">
      <Label>
        Scene Type
        <Select
          aria-label={`${label} Scene Type`}
          aria-invalid={unknown || error !== undefined}
          aria-describedby={error === undefined ? undefined : problemId}
          value={entry.sceneType}
          onChange={(event) => {
            onChange(event.target.value)
          }}
        >
          <option value="">None</option>
          {unknown && (
            <option value={entry.sceneType}>{entry.sceneType} (not in this draft)</option>
          )}
          {options.map(({ key, name }) => (
            <option key={key} value={key}>
              {name}
            </option>
          ))}
        </Select>
      </Label>
      {unknown && (
        <p className="text-sm text-destructive">
          The draft has no Scene Type &ldquo;{entry.sceneType}&rdquo;.
        </p>
      )}
      {error !== undefined && (
        <p id={problemId} role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}

interface EntryRowProps {
  entry: EntryDraft
  index: number
  ranged: boolean
  /** Whether the draft's schema version has entry keys. */
  withKey: boolean
  /** The keys of the other tables an entry can nest. */
  tables: string[]
  /** What the Scene Type and effects pick from; null for a draft whose schema has neither. */
  context: StepContext | null
  /** What the server or the editor says about this entry's Scene Type and effects. */
  errors: StepError[]
  /** What is wrong with this entry, from the editor or from the server. */
  problem: string | null
  canRemove: boolean
  onChange: (entry: EntryDraft) => void
  onRemove: () => void
}

function EntryRow({
  entry,
  index,
  ranged,
  withKey,
  tables,
  context,
  errors,
  problem,
  canRemove,
  onChange,
  onRemove,
}: EntryRowProps) {
  const problemId = useId()
  const label = `Entry ${String(index + 1)}`
  const options =
    entry.table === '' || tables.includes(entry.table) ? tables : [...tables, entry.table]
  const field = (name: keyof EntryDraft) => ({
    value: entry[name] as string,
    onChange: (event: { target: { value: string } }) => {
      onChange({ ...entry, [name]: event.target.value })
    },
    'aria-describedby': problem === null ? undefined : problemId,
  })

  return (
    <li aria-label={label} className="space-y-2 px-4 py-3">
      <div className="flex flex-wrap items-end gap-3">
        {ranged ? (
          <>
            <div className="w-20 space-y-1">
              <Label>
                Min
                <Input
                  inputMode="numeric"
                  autoComplete="off"
                  aria-label={`${label} min`}
                  aria-invalid={parseInteger(entry.min) === null}
                  {...field('min')}
                />
              </Label>
            </div>
            <div className="w-20 space-y-1">
              <Label>
                Max
                <Input
                  inputMode="numeric"
                  autoComplete="off"
                  aria-label={`${label} max`}
                  aria-invalid={parseInteger(entry.max) === null}
                  {...field('max')}
                />
              </Label>
            </div>
          </>
        ) : (
          <div className="w-24 space-y-1">
            <Label>
              Weight
              <Input
                inputMode="numeric"
                autoComplete="off"
                placeholder="1"
                aria-label={`${label} weight`}
                aria-invalid={entry.weight !== '' && parseInteger(entry.weight) === null}
                {...field('weight')}
              />
            </Label>
          </div>
        )}
        <div className="min-w-48 flex-1 space-y-1">
          <Label>
            Text
            <Input
              autoComplete="off"
              aria-label={`${label} text`}
              aria-invalid={entry.text.trim() === ''}
              {...field('text')}
            />
          </Label>
        </div>
        <Button type="button" variant="outline" size="sm" disabled={!canRemove} onClick={onRemove}>
          Remove {label.toLowerCase()}
        </Button>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 space-y-1">
          <Label>
            Nested table
            <Select aria-label={`${label} nested table`} {...field('table')}>
              <option value="">None</option>
              {options.map((key) => (
                <option key={key} value={key}>
                  {key}
                </option>
              ))}
            </Select>
          </Label>
        </div>
        {withKey && (
          <div className="min-w-40 space-y-1">
            <Label>
              Entry key
              <Input
                autoComplete="off"
                aria-label={`${label} key`}
                {...field('key')}
                aria-describedby={[`${problemId}-key`, problem === null ? null : problemId]
                  .filter((id) => id !== null)
                  .join(' ')}
              />
            </Label>
            <KeyHint id={`${problemId}-key`} />
          </div>
        )}
        {context !== null && (
          <SceneTypeField
            label={label}
            entry={entry}
            context={context}
            error={
              errors.find((error) => error.effect === null && error.field === 'sceneType')?.message
            }
            onChange={(sceneType) => {
              onChange({ ...entry, sceneType })
            }}
          />
        )}
      </div>
      {context !== null &&
        (effectsEditable(entry.original) ? (
          <EffectsEditor
            label={`${label} effects`}
            effects={entry.effects}
            context={context}
            allowSheet={false}
            errors={errors.filter(aboutEffects)}
            onChange={(effects) => {
              onChange({ ...entry, effects })
            }}
          />
        ) : (
          <p className="text-sm text-destructive">
            {label} effects are not a list; they are kept as stored.
          </p>
        ))}
      {problem !== null && (
        <p id={problemId} role="alert" className="text-sm text-destructive">
          {problem}
        </p>
      )}
    </li>
  )
}

/** The rows of a table's entries; the editor owns the draft and says which rows have a problem. */
export function EntriesEditor({
  entries,
  problems,
  errors,
  onChange,
  onAdd,
  ...row
}: {
  entries: EntryDraft[]
  problems: Record<number, string>
  ranged: boolean
  withKey: boolean
  tables: string[]
  context: StepContext | null
  errors: Record<number, StepError[]>
  onChange: (entries: EntryDraft[]) => void
  onAdd: () => void
}) {
  return (
    <div className="space-y-3">
      <ol aria-label="Entries" className="divide-y rounded-xl ring-1 ring-foreground/10">
        {entries.map((entry, index) => (
          <EntryRow
            key={entry.id}
            {...row}
            entry={entry}
            index={index}
            problem={problems[index] ?? null}
            errors={errors[index] ?? []}
            canRemove={entries.length > 1}
            onChange={(changed) => {
              onChange(entries.map((other) => (other === entry ? changed : other)))
            }}
            onRemove={() => {
              onChange(entries.filter((other) => other !== entry))
            }}
          />
        ))}
      </ol>
      <Button type="button" variant="outline" size="sm" onClick={onAdd}>
        Add entry
      </Button>
    </div>
  )
}
