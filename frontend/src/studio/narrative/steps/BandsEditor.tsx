import { useId } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { TextField } from '../TextField'
import type { ValueDraft, ValueForm } from './draftParts'
import { EffectsEditor } from './EffectsEditor'
import { END, newBand, type BandDraft } from './stepDraft'
import type { StepContext } from './stepContext'
import type { StepError } from './stepError'

const formNames: Record<ValueForm, string> = {
  integer: 'Number',
  tracker: 'Tracker',
  formula: 'Formula',
  dice: 'Dice',
}

/** A `next` picker: the following step, a later key of the list, or `end`. */
export function NextPicker({
  label,
  value,
  laterKeys,
  error,
  onChange,
}: {
  label: string
  value: string
  laterKeys: string[]
  error?: string
  onChange: (next: string) => void
}) {
  const id = useId()
  // A value the list no longer offers (after a move) stays visible, so it can be seen and fixed.
  // Repeated keys (or a step keyed `end`) must not repeat an option: they are one choice.
  const offered = [...new Set([...laterKeys.filter((key) => key !== END), END])]
  const options = offered.includes(value) || value === '' ? [] : [value]

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <NativeSelect
        id={id}
        value={value}
        aria-invalid={error !== undefined}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      >
        <option value="">The following step</option>
        {[...options, ...offered].map((key) => (
          <option key={key} value={key}>
            {key === END ? 'End of the list' : key}
          </option>
        ))}
      </NativeSelect>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

/**
 * A value as the author picks its form (a whole number, a tracker or a formula) and fills it. The
 * allowed forms are a prop: the effects editor adds `{dice}` where it applies.
 */
export function ValueInput({
  label,
  value,
  forms,
  trackers,
  error,
  onChange,
}: {
  label: string
  value: ValueDraft
  forms: readonly ValueForm[]
  trackers: { key: string; name: string }[]
  error?: string
  onChange: (value: ValueDraft) => void
}) {
  const id = useId()
  const keys = trackers.map((tracker) => tracker.key)

  return (
    <div className="flex flex-wrap items-start gap-2">
      <NativeSelect
        aria-label={`${label} type`}
        value={value.form}
        onChange={(event) => {
          onChange({ form: event.target.value as ValueForm, text: '' })
        }}
      >
        {forms.map((form) => (
          <option key={form} value={form}>
            {formNames[form]}
          </option>
        ))}
      </NativeSelect>
      {value.form === 'tracker' ? (
        <div className="space-y-2">
          <Label htmlFor={id}>{label}</Label>
          <NativeSelect
            id={id}
            value={value.text}
            onChange={(event) => {
              onChange({ ...value, text: event.target.value })
            }}
          >
            <option value="">Pick a tracker</option>
            {(keys.includes(value.text) || value.text === '' ? [] : [value.text])
              .map((key) => ({ key, name: key }))
              .concat(trackers)
              .map((tracker) => (
                <option key={tracker.key} value={tracker.key}>
                  {tracker.name}
                </option>
              ))}
          </NativeSelect>
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
      ) : (
        <TextField
          label={label}
          value={value.text}
          error={error}
          onChange={(text) => {
            onChange({ ...value, text })
          }}
        />
      )}
    </div>
  )
}

export interface BandProblems {
  /** Per band: the guide or server message for each field. */
  upTo: Record<number, string>
  next: Record<number, string>
}

/**
 * Ordered bands: every band but the last has an upper limit, the last catches the rest. Each band
 * has its own effects (no sheet effects: no Character is known there). `effectErrors` are the
 * server's messages about effects of these bands.
 */
export function BandsEditor({
  bands,
  forms,
  context,
  laterKeys,
  problems,
  effectErrors,
  onChange,
  onRestructure,
}: {
  bands: BandDraft[]
  forms: readonly ValueForm[]
  context: StepContext
  laterKeys: string[]
  problems: BandProblems
  effectErrors: StepError[]
  onChange: (bands: BandDraft[]) => void
  /** Called when bands move or go, so messages keyed by position can be dropped. */
  onRestructure?: () => void
}) {
  const replace = (index: number, band: BandDraft) => {
    onChange(bands.map((current, at) => (at === index ? band : current)))
  }
  const move = (index: number, by: number) => {
    const next = [...bands]
    const [band] = next.splice(index, 1)
    if (band) {
      next.splice(index + by, 0, band)
    }
    onRestructure?.()
    onChange(next)
  }

  return (
    <fieldset className="space-y-3">
      <legend className="text-sm font-medium">Bands</legend>
      {bands.map((band, index) => {
        const number = String(index + 1)
        const last = index === bands.length - 1
        return (
          // The list is edited in place and has no ids: the position is the identity.
          <div
            key={index}
            role="group"
            aria-label={`Band ${number}`}
            className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10"
          >
            {last && band.upTo === null ? (
              <p className="text-sm text-muted-foreground">Otherwise: the rest.</p>
            ) : (
              <ValueInput
                label="Up to"
                value={band.upTo ?? { form: 'integer', text: '' }}
                forms={forms}
                trackers={context.trackers}
                error={problems.upTo[index]}
                onChange={(upTo) => {
                  replace(index, { ...band, upTo })
                }}
              />
            )}
            {last && band.upTo !== null && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => {
                  replace(index, { ...band, upTo: null })
                }}
              >
                Remove the limit of band {number}
              </Button>
            )}
            <NextPicker
              label="Next"
              value={band.next}
              laterKeys={laterKeys}
              error={problems.next[index]}
              onChange={(next) => {
                replace(index, { ...band, next })
              }}
            />
            <EffectsEditor
              label={`Band ${number} effects`}
              effects={band.effects}
              context={context}
              allowSheet={false}
              errors={effectErrors.filter((error) => error.band === index)}
              onChange={(effects) => {
                replace(index, { ...band, effects })
              }}
              onRestructure={onRestructure}
            />
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={index === 0}
                aria-label={`Move band ${number} up`}
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
                aria-label={`Move band ${number} down`}
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
                aria-label={`Delete band ${number}`}
                onClick={() => {
                  onRestructure?.()
                  onChange(bands.filter((_, at) => at !== index))
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
          onChange([...bands, { ...newBand(), upTo: null }])
        }}
      >
        Add band
      </Button>
    </fieldset>
  )
}
