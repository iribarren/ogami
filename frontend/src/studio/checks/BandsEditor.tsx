import { useId, useMemo } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { TextField } from '../narrative/TextField'
import { FormulaInput } from '../sheet/FormulaInput'
import {
  blankBand,
  maxBands,
  type BandDraft,
  type BandErrorMap,
  type DifficultyDraft,
  type UpToDraft,
} from './checkDraft'
import {
  checkFormulaDiagnostics,
  checkReferences,
  declaredDifficulty,
} from './checkFormulaDiagnostics'
import type { GameSystemChecks } from './useGameSystemChecks'

type Kind = UpToDraft['kind']

const kinds: { kind: Exclude<Kind, 'unknown'>; label: string }[] = [
  { kind: 'none', label: 'None' },
  { kind: 'number', label: 'A number' },
  { kind: 'tracker', label: 'A tracker' },
  { kind: 'formula', label: 'A formula' },
]

/** The bound of a new kind starts empty, but a bound that already has text keeps it. */
function withKind(kind: Kind, current: UpToDraft): UpToDraft {
  if (kind === 'none') {
    return { kind: 'none' }
  }
  if (kind === 'unknown') {
    return current
  }
  return { kind, value: 'value' in current ? current.value : '' }
}

/**
 * The bands of a check, in order: the outcome is the first band whose bound is at least the
 * total. Every band but the last has a bound (a number, a tracker's value or a formula); the last
 * catches the rest, so its bound is not sent. A band rename is a drop plus an add: nothing that
 * names the old key follows it.
 */
export function BandsEditor({
  bands,
  onChange,
  catalog,
  difficulty,
  error,
  errors,
}: {
  bands: BandDraft[]
  /**
   * `order` comes with a rearrangement (a move, a removal, an added band): for each band now, the
   * position it had before, null for the new one, so what is known by position follows the bands.
   */
  onChange: (bands: BandDraft[], order?: (number | null)[]) => void
  catalog: Pick<GameSystemChecks, 'fields' | 'trackers'>
  difficulty: DifficultyDraft | null
  /** The publisher's message about the bands as a whole. */
  error?: string
  errors: BandErrorMap
}) {
  const declared = useMemo(() => declaredDifficulty(difficulty), [difficulty])
  const references = useMemo(
    () => checkReferences(catalog, 'band', difficulty !== null),
    [catalog, difficulty],
  )

  const change = (index: number, changes: Partial<BandDraft>) => {
    onChange(bands.map((band, at) => (at === index ? { ...band, ...changes } : band)))
  }
  const move = (index: number, to: number) => {
    const next = [...bands]
    const [band] = next.splice(index, 1)
    if (band !== undefined) {
      next.splice(to, 0, band)
    }
    const order: (number | null)[] = bands.map((_, at) => at)
    order.splice(to, 0, ...order.splice(index, 1))
    onChange(next, order)
  }

  return (
    <div className="space-y-3">
      <h4 className="text-sm font-medium">Bands</h4>
      <ol className="space-y-3">
        {bands.map((band, index) => {
          const last = index === bands.length - 1
          const found = errors[index] ?? {}
          const name = `Band ${String(index + 1)}`
          return (
            // The bands have no identity of their own, so the position is the key.
            <li key={index} className="space-y-3 rounded-lg p-3 ring-1 ring-foreground/10">
              <div className="grid gap-3 sm:grid-cols-2">
                <TextField
                  label={`${name} key`}
                  keyField
                  value={band.key}
                  onChange={(key) => {
                    change(index, { key })
                  }}
                  error={found.key}
                />
                <TextField
                  label={`${name} label`}
                  value={band.label}
                  onChange={(label) => {
                    change(index, { label })
                  }}
                  error={found.label}
                />
              </div>
              {last ? (
                <>
                  <p className="text-sm text-muted-foreground">
                    The last band catches the rest: it has no bound.
                    {band.upTo.kind !== 'none' && ' Its bound is dropped when you save.'}
                  </p>
                  {found.upTo && <p className="text-sm text-destructive">{found.upTo}</p>}
                </>
              ) : (
                <UpToInput
                  name={name}
                  upTo={band.upTo}
                  onChange={(upTo) => {
                    change(index, { upTo })
                  }}
                  catalog={catalog}
                  references={references}
                  declared={declared}
                  error={found.upTo}
                />
              )}
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  aria-label={`Move up ${name.toLowerCase()}`}
                  disabled={index === 0}
                  onClick={() => {
                    move(index, index - 1)
                  }}
                >
                  Move up
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  aria-label={`Move down ${name.toLowerCase()}`}
                  disabled={last}
                  onClick={() => {
                    move(index, index + 1)
                  }}
                >
                  Move down
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  aria-label={`Remove ${name.toLowerCase()}`}
                  disabled={bands.length <= 1}
                  onClick={() => {
                    onChange(
                      bands.filter((_, at) => at !== index),
                      bands.flatMap((_, at) => (at === index ? [] : [at])),
                    )
                  }}
                >
                  Remove
                </Button>
              </div>
            </li>
          )
        })}
      </ol>
      {error && <p className="text-sm text-destructive">{error}</p>}
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={bands.length >= maxBands}
        onClick={() => {
          onChange([...bands, blankBand()], [...bands.keys(), null])
        }}
      >
        Add band
      </Button>
    </div>
  )
}

/** The bound of a band that is not the last: a kind selector and the input of that kind. */
function UpToInput({
  name,
  upTo,
  onChange,
  catalog,
  references,
  declared,
  error,
}: {
  name: string
  upTo: UpToDraft
  onChange: (upTo: UpToDraft) => void
  catalog: Pick<GameSystemChecks, 'fields' | 'trackers'>
  references: ReturnType<typeof checkReferences>
  declared: ReturnType<typeof declaredDifficulty>
  error?: string
}) {
  const kindId = useId()
  const trackerId = useId()

  return (
    <div className="space-y-2">
      <Label htmlFor={kindId}>{name} bound</Label>
      <NativeSelect
        id={kindId}
        value={upTo.kind}
        onChange={(event) => {
          onChange(withKind(event.target.value as Kind, upTo))
        }}
      >
        {upTo.kind === 'unknown' && <option value="unknown">Kept as stored</option>}
        {kinds.map(({ kind, label }) => (
          <option key={kind} value={kind}>
            {label}
          </option>
        ))}
      </NativeSelect>
      {upTo.kind === 'none' && error && <p className="text-sm text-destructive">{error}</p>}
      {upTo.kind === 'number' && (
        <TextField
          label={`${name} number`}
          value={upTo.value}
          onChange={(value) => {
            onChange({ kind: 'number', value })
          }}
          error={error}
        />
      )}
      {upTo.kind === 'tracker' && (
        <div className="space-y-2">
          <Label htmlFor={trackerId}>{name} tracker</Label>
          <NativeSelect
            id={trackerId}
            value={upTo.value}
            aria-invalid={error !== undefined}
            onChange={(event) => {
              onChange({ kind: 'tracker', value: event.target.value })
            }}
          >
            <option value="">Choose a tracker…</option>
            {/* A stored tracker the draft no longer has stays selectable, so it is shown, not swapped. */}
            {upTo.value !== '' && !catalog.trackers.some(({ key }) => key === upTo.value) && (
              <option value={upTo.value}>{upTo.value} (not in this draft)</option>
            )}
            {catalog.trackers.map((tracker) => (
              <option key={tracker.key} value={tracker.key}>
                {tracker.label} ({tracker.key})
              </option>
            ))}
          </NativeSelect>
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
      )}
      {upTo.kind === 'formula' && (
        <FormulaInput
          label={`${name} formula`}
          value={upTo.value}
          onChange={(value) => {
            onChange({ kind: 'formula', value })
          }}
          diagnostics={
            upTo.value.trim() === ''
              ? null
              : checkFormulaDiagnostics({
                  formula: upTo.value,
                  scope: 'band',
                  catalog,
                  difficulty: declared,
                })
          }
          references={references}
          error={error}
        />
      )}
      {upTo.kind === 'unknown' && (
        <div className="space-y-1">
          <p className="font-mono text-sm break-all text-muted-foreground">
            {JSON.stringify(upTo.raw)}
          </p>
          <p className="text-sm text-muted-foreground">
            The editor has no input for this bound: it is kept as stored. Choose another kind to
            replace it.
          </p>
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
      )}
    </div>
  )
}
