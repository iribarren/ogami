import { ArrowDown, ArrowUp, ChevronDown, Plus, X } from 'lucide-react'
import { memo, useCallback, useId, useLayoutEffect, useMemo, useRef } from 'react'

import { cn } from '@/shared/lib/utils'
import { FieldError } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/shared/ui/dropdown-menu'
import { IconButton } from '@/shared/ui/icon-button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import { KeyHint } from '../KeyHint'
import { FormulaInput } from '../sheet/FormulaInput'
import {
  addBand,
  bandPresets,
  editBandKey,
  editBandLabel,
  maxBands,
  type BandDraft,
  type BandErrorMap,
  type BandErrors,
  type DifficultyDraft,
  type UpToDraft,
} from './checkDraft'
import {
  checkFormulaDiagnostics,
  type Catalog,
  checkReferences,
  declaredDifficulty,
} from './checkFormulaDiagnostics'

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

/** A band the "Copy from a check…" submenu offers: a check's bands, keys, labels and bounds. */
export interface BandSource {
  key: string
  label: string
  bands: BandDraft[]
}

/**
 * The bands of a check, in order: the outcome is the first band whose bound is at least the
 * total. Every band but the last has a bound (a number, a tracker's value or a formula); the last
 * catches the rest, so its bound is not sent. A band rename is a drop plus an add: nothing that
 * names the old key follows it. A preset replaces all the bands at once.
 */
export function BandsEditor({
  bands,
  onChange,
  catalog,
  difficulty,
  copyFrom,
  error,
  errors,
}: {
  bands: BandDraft[]
  /**
   * `order` comes with a rearrangement (a move, a removal, an added band): for each band now, the
   * position it had before, null for the new one, so what is known by position follows the bands.
   */
  onChange: (bands: BandDraft[], order?: (number | null)[]) => void
  catalog: Catalog
  difficulty: DifficultyDraft | null
  /** The other checks whose bands can be copied. */
  copyFrom: BandSource[]
  /** The publisher's message about the bands as a whole. */
  error?: string
  errors: BandErrorMap
}) {
  const declared = useMemo(() => declaredDifficulty(difficulty), [difficulty])
  const references = useMemo(
    () => checkReferences(catalog, 'band', difficulty !== null),
    [catalog, difficulty],
  )
  const keyHintId = useId()

  // The rows are memoised, so what they call is stable and reads the latest bands from here.
  // For event handlers only: a child's layout effect runs before this one in the same commit,
  // so a callback called from there would still see the previous render's props.
  const latest = useRef({ bands, onChange })
  useLayoutEffect(() => {
    latest.current = { bands, onChange }
  })
  const change = useCallback((index: number, next: (band: BandDraft) => BandDraft) => {
    const { bands: now, onChange: emit } = latest.current
    emit(now.map((band, at) => (at === index ? next(band) : band)))
  }, [])
  const move = useCallback((index: number, to: number) => {
    const { bands: now, onChange: emit } = latest.current
    const next = [...now]
    const [band] = next.splice(index, 1)
    if (band !== undefined) {
      next.splice(to, 0, band)
    }
    const order: (number | null)[] = now.map((_, at) => at)
    order.splice(to, 0, ...order.splice(index, 1))
    emit(next, order)
  }, [])
  const remove = useCallback((index: number) => {
    const { bands: now, onChange: emit } = latest.current
    emit(
      now.filter((_, at) => at !== index),
      now.flatMap((_, at) => (at === index ? [] : [at])),
    )
  }, [])
  // A preset or a copy replaces every band: no message about the old ones stays.
  const replace = (next: BandDraft[]) => {
    onChange(
      next,
      next.map(() => null),
    )
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <h4 className="mr-auto text-sm font-medium">Bands</h4>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button type="button" variant="ghost" size="sm">
              Use a preset
              <ChevronDown aria-hidden="true" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            {bandPresets.map((preset) => (
              <DropdownMenuItem
                key={preset.id}
                onSelect={() => {
                  replace(preset.bands())
                }}
              >
                {preset.label}
              </DropdownMenuItem>
            ))}
            <DropdownMenuSub>
              <DropdownMenuSubTrigger disabled={copyFrom.length === 0}>
                Copy from a check…
              </DropdownMenuSubTrigger>
              <DropdownMenuSubContent>
                {copyFrom.map((source) => (
                  <DropdownMenuItem
                    key={source.key}
                    onSelect={() => {
                      replace(source.bands.map((band) => ({ ...band, upTo: { ...band.upTo } })))
                    }}
                  >
                    {source.label}
                  </DropdownMenuItem>
                ))}
              </DropdownMenuSubContent>
            </DropdownMenuSub>
          </DropdownMenuContent>
        </DropdownMenu>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={bands.length >= maxBands}
          onClick={() => {
            const added = addBand(bands)
            onChange(added.bands, added.order)
          }}
        >
          <Plus aria-hidden="true" />
          Add band
        </Button>
      </div>
      <ol className="space-y-2">
        {bands.map((band, index) => (
          // The bands have no identity of their own, so the position is the key.
          <BandRow
            key={index}
            index={index}
            band={band}
            last={index === bands.length - 1}
            removable={bands.length > 1}
            found={errors[index] ?? noFound}
            keyHintId={keyHintId}
            catalog={catalog}
            references={references}
            declared={declared}
            onChange={change}
            onMove={move}
            onRemove={remove}
          />
        ))}
      </ol>
      <KeyHint id={keyHintId}>Every band key follows this rule.</KeyHint>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

const noFound: BandErrors = {}

/** One band of the list: its key, label and bound, and the buttons that move or remove it. */
const BandRow = memo(function BandRow({
  index,
  band,
  last,
  removable,
  found,
  keyHintId,
  catalog,
  references,
  declared,
  onChange,
  onMove,
  onRemove,
}: {
  index: number
  band: BandDraft
  last: boolean
  removable: boolean
  found: BandErrors
  keyHintId: string
  catalog: Catalog
  references: ReturnType<typeof checkReferences>
  declared: ReturnType<typeof declaredDifficulty>
  onChange: (index: number, next: (band: BandDraft) => BandDraft) => void
  onMove: (index: number, to: number) => void
  onRemove: (index: number) => void
}) {
  const name = `Band ${String(index + 1)}`

  return (
    <li className="space-y-2 well-row">
      <div className="flex flex-wrap items-start gap-2">
        <span aria-hidden="true" className="mt-1.5 count-pill">
          {index + 1}
        </span>
        <BandInput
          label={`${name} key`}
          placeholder="Key"
          className="w-32 flex-1"
          mono
          describedBy={keyHintId}
          value={band.key}
          onChange={(key) => {
            onChange(index, (held) => editBandKey(held, key))
          }}
          error={found.key}
        />
        <BandInput
          label={`${name} label`}
          placeholder="Label"
          className="w-40 flex-[2]"
          value={band.label}
          onChange={(label) => {
            onChange(index, (held) => editBandLabel(held, label))
          }}
          error={found.label}
        />
        {!last && (
          <UpToInput
            name={name}
            upTo={band.upTo}
            onChange={(upTo) => {
              onChange(index, (held) => ({ ...held, upTo }))
            }}
            catalog={catalog}
            references={references}
            declared={declared}
            error={found.upTo}
          />
        )}
        <div className="ml-auto flex">
          <IconButton
            label={`Move up ${name.toLowerCase()}`}
            dense
            disabled={index === 0}
            onClick={() => {
              onMove(index, index - 1)
            }}
          >
            <ArrowUp />
          </IconButton>
          <IconButton
            label={`Move down ${name.toLowerCase()}`}
            dense
            disabled={last}
            onClick={() => {
              onMove(index, index + 1)
            }}
          >
            <ArrowDown />
          </IconButton>
          <IconButton
            label={`Remove ${name.toLowerCase()}`}
            dense
            destructive
            disabled={!removable}
            onClick={() => {
              onRemove(index)
            }}
          >
            <X />
          </IconButton>
        </div>
      </div>
      {last && (
        <>
          <p className="hint">
            The last band catches the rest: it has no bound.
            {droppedBound(band.upTo) && ' Its bound is dropped when you save.'}
          </p>
          {found.upTo && <FieldError>{found.upTo}</FieldError>}
        </>
      )}
    </li>
  )
})

/** Whether saving would drop the bound: an empty number says nothing, so nothing is lost. */
function droppedBound(upTo: UpToDraft): boolean {
  return upTo.kind !== 'none' && !(upTo.kind === 'number' && upTo.value.trim() === '')
}

/** A compact text input of a band row: its label is for assistive technology only. */
function BandInput({
  label,
  placeholder,
  value,
  onChange,
  error,
  describedBy,
  mono = false,
  className,
}: {
  label: string
  placeholder: string
  value: string
  onChange: (value: string) => void
  error?: string
  describedBy?: string
  mono?: boolean
  className?: string
}) {
  const id = useId()
  const errorId = `${id}-error`
  const described = [describedBy, error ? errorId : undefined].filter(Boolean).join(' ')

  return (
    <div className={cn('min-w-0 space-y-1', className)}>
      <Label htmlFor={id} className="sr-only">
        {label}
      </Label>
      <Input
        id={id}
        className={mono ? 'font-mono' : undefined}
        placeholder={placeholder}
        value={value}
        autoComplete="off"
        aria-invalid={error !== undefined}
        aria-describedby={described === '' ? undefined : described}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {error && <FieldError id={errorId}>{error}</FieldError>}
    </div>
  )
}

/**
 * The bound of a band that is not the last, in the band's row: a kind selector and the input of
 * that kind (a formula wraps onto its own line; the others sit beside the selector).
 */
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
  catalog: Catalog
  references: ReturnType<typeof checkReferences>
  declared: ReturnType<typeof declaredDifficulty>
  error?: string
}) {
  const diagnostics = useMemo(
    () =>
      upTo.kind !== 'formula' || upTo.value.trim() === ''
        ? null
        : checkFormulaDiagnostics({
            formula: upTo.value,
            scope: 'band',
            catalog,
            difficulty: declared,
          }),
    [upTo, catalog, declared],
  )

  return (
    <>
      <div className="w-36 space-y-1">
        <Select
          size="sm"
          aria-label={`${name} bound`}
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
        </Select>
        {upTo.kind === 'none' && error && <FieldError>{error}</FieldError>}
      </div>
      {upTo.kind === 'number' && (
        <BandInput
          label={`${name} number`}
          placeholder="Up to"
          className="w-[70px] flex-none"
          value={upTo.value}
          onChange={(value) => {
            onChange({ kind: 'number', value })
          }}
          error={error}
        />
      )}
      {upTo.kind === 'tracker' && (
        <div className="w-48 space-y-1">
          <Select
            size="sm"
            aria-label={`${name} tracker`}
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
          </Select>
          {error && <FieldError>{error}</FieldError>}
        </div>
      )}
      {upTo.kind === 'formula' && (
        <div className="basis-full">
          <FormulaInput
            label={`${name} formula`}
            value={upTo.value}
            onChange={(value) => {
              onChange({ kind: 'formula', value })
            }}
            diagnostics={diagnostics}
            references={references}
            error={error}
          />
        </div>
      )}
      {upTo.kind === 'unknown' && (
        <div className="basis-full space-y-1">
          <p className="font-mono text-sm break-all text-muted-foreground">
            {JSON.stringify(upTo.raw)}
          </p>
          <p className="text-sm text-muted-foreground">
            The editor has no input for this bound: it is kept as stored. Choose another kind to
            replace it.
          </p>
          {error && <FieldError>{error}</FieldError>}
        </div>
      )}
    </>
  )
}
