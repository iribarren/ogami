import { useId } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { parseInteger, type Coverage, type EntryDraft, type Span } from './tableDraft'

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

/** The part of an entry the editor shows but does not edit; it is sent back as stored. */
function StoredBadges({ entry }: { entry: EntryDraft }) {
  const { sceneType, effects } = entry.original
  return (
    <>
      {typeof sceneType === 'string' && <Badge variant="secondary">Scene type: {sceneType}</Badge>}
      {Array.isArray(effects) && effects.length > 0 && (
        <Badge variant="secondary">
          {effects.length === 1 ? '1 effect' : `${String(effects.length)} effects`}
        </Badge>
      )}
    </>
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
            <NativeSelect aria-label={`${label} nested table`} {...field('table')}>
              <option value="">None</option>
              {options.map((key) => (
                <option key={key} value={key}>
                  {key}
                </option>
              ))}
            </NativeSelect>
          </Label>
        </div>
        {withKey && (
          <div className="min-w-40 space-y-1">
            <Label>
              Entry key
              <Input autoComplete="off" aria-label={`${label} key`} {...field('key')} />
            </Label>
          </div>
        )}
        <StoredBadges entry={entry} />
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
  onChange,
  onAdd,
  ...row
}: {
  entries: EntryDraft[]
  problems: Record<number, string>
  ranged: boolean
  withKey: boolean
  tables: string[]
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
