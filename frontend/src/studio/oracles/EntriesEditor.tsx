import { useId, useState } from 'react'
import { ChevronDown, Plus, X } from 'lucide-react'

import { cn } from '@/shared/lib/utils'
import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { IconButton } from '@/shared/ui/icon-button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import { KeyHint } from '../KeyHint'
import { EffectsEditor } from '../narrative/steps/EffectsEditor'
import { aboutEffects, type StepError } from '../narrative/steps/stepError'
import type { StepContext } from '../narrative/steps/stepContext'
import {
  effectsEditable,
  entrySummary,
  parseInteger,
  sceneTypeEditable,
  type Coverage,
  type DiceRange,
  type EntryDraft,
  type Span,
} from './tableDraft'

const span = ({ from, to }: Span) => (from === to ? String(from) : `${String(from)}–${String(to)}`)

/**
 * What the entries' ranges leave uncovered or cover twice, as the dice would roll them; a badge
 * when every total is covered exactly once.
 */
export function CoverageSummary({ coverage, range }: { coverage: Coverage; range: DiceRange }) {
  const problems = [
    coverage.gaps.length > 0 && `No entry covers ${coverage.gaps.map(span).join(', ')}.`,
    coverage.overlaps.length > 0 &&
      `More than one entry covers ${coverage.overlaps.map(span).join(', ')}.`,
  ].filter((problem) => problem !== false)
  return problems.length === 0 ? (
    <Badge variant="success">Covers {span({ from: range.min, to: range.max })}</Badge>
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

  // Details open by hand, and open by themselves when an error points at one of their fields.
  const [open, setOpen] = useState(false)
  const [seenErrors, setSeenErrors] = useState(0)
  if (errors.length !== seenErrors) {
    setSeenErrors(errors.length)
    if (errors.length > seenErrors) {
      setOpen(true)
    }
  }
  const detailsId = `${problemId}-details`
  const summary = entrySummary(entry)

  return (
    <li aria-label={label} className="space-y-2 py-2">
      <div className="flex items-center gap-2">
        {ranged ? (
          <>
            <Input
              inputMode="numeric"
              autoComplete="off"
              className="h-8 w-[52px] shrink-0 px-1 text-center"
              aria-label={`${label} min`}
              aria-invalid={parseInteger(entry.min) === null}
              {...field('min')}
            />
            <Input
              inputMode="numeric"
              autoComplete="off"
              className="h-8 w-[52px] shrink-0 px-1 text-center"
              aria-label={`${label} max`}
              aria-invalid={parseInteger(entry.max) === null}
              {...field('max')}
            />
          </>
        ) : (
          <Input
            inputMode="numeric"
            autoComplete="off"
            placeholder="1"
            className="h-8 w-[52px] shrink-0 px-1 text-center"
            aria-label={`${label} weight`}
            aria-invalid={entry.weight !== '' && parseInteger(entry.weight) === null}
            {...field('weight')}
          />
        )}
        <Input
          autoComplete="off"
          className="h-8 min-w-0 flex-1"
          aria-label={`${label} text`}
          aria-invalid={entry.text.trim() === ''}
          {...field('text')}
        />
        {summary !== null && <Badge variant="secondary">{summary}</Badge>}
        <IconButton
          label={`${label} details`}
          dense
          aria-expanded={open}
          aria-controls={detailsId}
          onClick={() => {
            setOpen(!open)
          }}
        >
          <ChevronDown className={cn(!open && '-rotate-90')} />
        </IconButton>
        <IconButton
          label={`Remove ${label.toLowerCase()}`}
          dense
          destructive
          disabled={!canRemove}
          onClick={onRemove}
        >
          <X />
        </IconButton>
      </div>
      <div id={detailsId} hidden={!open} className="space-y-3 well">
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
                errors.find((error) => error.effect === null && error.field === 'sceneType')
                  ?.message
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
      </div>
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
  ranged,
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
      <div aria-hidden="true" className="flex gap-2 group-label">
        {ranged ? (
          <>
            <span className="w-[52px] text-center">Min</span>
            <span className="w-[52px] text-center">Max</span>
          </>
        ) : (
          <span className="w-[52px] text-center">Weight</span>
        )}
        <span>Text</span>
      </div>
      <ol aria-label="Entries" className="divide-y">
        {entries.map((entry, index) => (
          <EntryRow
            key={entry.id}
            {...row}
            ranged={ranged}
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
        <Plus aria-hidden="true" />
        Add entry
      </Button>
    </div>
  )
}
