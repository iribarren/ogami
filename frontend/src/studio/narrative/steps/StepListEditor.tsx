import { useId, useState } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { SegmentedControl, SegmentedControlItem } from '@/shared/ui/segmented-control'
import { Textarea } from '@/shared/ui/textarea'

import { TextField } from '../TextField'
import { BandsEditor, NextPicker, type BandProblems } from './BandsEditor'
import type { StepContext } from './stepContext'
import {
  countTargets,
  newStep,
  stepGuides,
  stepKey,
  stepKinds,
  summariseEffects,
  type BandDraft,
  type CountTarget,
  type EditedStep,
  type StepDraft,
  type StepGuide,
  type StepKind,
} from './stepDraft'
import type { StepError } from './stepError'

const bandForms = ['integer', 'tracker', 'formula'] as const

/** Checkboxes over `options`; a selected key the options no longer have stays listed. */
function CheckList({
  legend,
  options,
  value,
  onChange,
}: {
  legend: string
  options: { key: string; name: string }[]
  value: string[]
  onChange: (value: string[]) => void
}) {
  const known = options.map((option) => option.key)
  const all = [
    ...options,
    ...value.filter((key) => !known.includes(key)).map((key) => ({ key, name: key })),
  ]

  return (
    <fieldset className="space-y-1">
      <legend className="text-sm font-medium">{legend}</legend>
      {all.length === 0 && <p className="text-sm text-muted-foreground">Nothing to pick yet.</p>}
      {all.map((option) => (
        <label key={option.key} className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={value.includes(option.key)}
            onChange={(event) => {
              onChange(
                event.target.checked
                  ? [...value, option.key]
                  : value.filter((key) => key !== option.key),
              )
            }}
          />
          {option.name}
        </label>
      ))}
    </fieldset>
  )
}

function Area({
  label,
  value,
  error,
  onChange,
}: {
  label: string
  value: string
  error?: string
  onChange: (value: string) => void
}) {
  const id = useId()

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <Textarea
        id={id}
        value={value}
        aria-invalid={error !== undefined}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

/** The bands' problems: what the guides and the server say about each band's limit and next. */
function bandProblems(guide: StepGuide, mine: StepError[]): BandProblems {
  const problems: BandProblems = { upTo: {}, next: {} }
  for (const [at, flags] of Object.entries(guide.bands)) {
    Object.assign(problems.upTo, flags.upTo === undefined ? {} : { [at]: flags.upTo })
    Object.assign(problems.next, flags.next === undefined ? {} : { [at]: flags.next })
  }
  for (const { band, field, message } of mine) {
    if (band !== null && (field === 'upTo' || field === 'next')) {
      problems[field][band] = message
    }
  }
  return problems
}

function StepFields({
  step,
  guide,
  mine,
  context,
  laterKeys,
  onChange,
}: {
  step: EditedStep
  guide: StepGuide
  mine: StepError[]
  context: StepContext
  laterKeys: string[]
  onChange: (step: StepDraft) => void
}) {
  const fieldError = (field: string) =>
    mine.find((error) => error.band === null && error.field === field)?.message
  const setBands = (bands: BandDraft[]) => {
    onChange({ ...step, bands } as StepDraft)
  }
  const effects = summariseEffects(step.effects)
  // Everything the form shows next to a field; the rest is listed on the card.
  const inline = ['key', 'title', 'prompt', 'tip', 'dice', 'next']
  const loose = mine.filter(
    (error) =>
      error.index === null ||
      (error.band === null
        ? !inline.includes(error.field ?? '')
        : error.field !== 'upTo' && error.field !== 'next'),
  )

  return (
    <div className="space-y-3 pt-3">
      <TextField
        label="Key"
        value={step.key}
        error={fieldError('key') ?? guide.key}
        onChange={(key) => {
          onChange({ ...step, key })
        }}
      />
      <TextField
        label="Title"
        value={step.title}
        error={fieldError('title')}
        onChange={(title) => {
          onChange({ ...step, title })
        }}
      />
      <Area
        label="Prompt"
        value={step.prompt}
        error={fieldError('prompt')}
        onChange={(prompt) => {
          onChange({ ...step, prompt })
        }}
      />
      <Area
        label="Tip"
        value={step.tip}
        error={fieldError('tip')}
        onChange={(tip) => {
          onChange({ ...step, tip })
        }}
      />
      {step.kind !== 'condition' && (
        <SegmentedControl
          aria-label="Requirement"
          value={step.mandatory ? 'mandatory' : 'suggested'}
          onValueChange={(value) => {
            onChange({ ...step, mandatory: value === 'mandatory' })
          }}
        >
          <SegmentedControlItem value="mandatory">Mandatory</SegmentedControlItem>
          <SegmentedControlItem value="suggested">Suggested</SegmentedControlItem>
        </SegmentedControl>
      )}
      <NextPicker
        label="Next"
        value={step.next}
        laterKeys={laterKeys}
        error={fieldError('next') ?? guide.next}
        onChange={(next) => {
          onChange({ ...step, next })
        }}
      />
      {step.kind === 'prompt' && (
        <CheckList
          legend="Inspiration tables"
          options={context.tables}
          value={step.inspiration}
          onChange={(inspiration) => {
            onChange({ ...step, inspiration })
          }}
        />
      )}
      {step.kind === 'roll' && (
        <TextField
          label="Dice"
          value={step.dice}
          error={fieldError('dice')}
          onChange={(dice) => {
            onChange({ ...step, dice })
          }}
        />
      )}
      {step.kind === 'condition' && (
        <>
          <div className="space-y-2">
            <Label>Reads</Label>
            <NativeSelect
              aria-label="Reads"
              value={step.source}
              onChange={(event) => {
                onChange({ ...step, source: event.target.value as 'tracker' | 'count' })
              }}
            >
              <option value="tracker">A tracker</option>
              <option value="count">A count of open items</option>
            </NativeSelect>
          </div>
          {step.source === 'tracker' ? (
            <NativeSelect
              aria-label="Tracker"
              value={step.tracker}
              onChange={(event) => {
                onChange({ ...step, tracker: event.target.value })
              }}
            >
              <option value="">Pick a tracker</option>
              {[
                ...context.trackers,
                ...(context.trackers.some((tracker) => tracker.key === step.tracker) ||
                step.tracker === ''
                  ? []
                  : [{ key: step.tracker, name: step.tracker }]),
              ].map((tracker) => (
                <option key={tracker.key} value={tracker.key}>
                  {tracker.name}
                </option>
              ))}
            </NativeSelect>
          ) : (
            <>
              <NativeSelect
                aria-label="Count of"
                value={step.of}
                onChange={(event) => {
                  onChange({ ...step, of: event.target.value as CountTarget })
                }}
              >
                {countTargets.map((target) => (
                  <option key={target} value={target}>
                    {target}
                  </option>
                ))}
              </NativeSelect>
              <CheckList
                legend="Tags"
                options={context.tags}
                value={step.tags}
                onChange={(tags) => {
                  onChange({ ...step, tags })
                }}
              />
            </>
          )}
        </>
      )}
      {(step.kind === 'roll' || step.kind === 'condition') && (
        <BandsEditor
          bands={step.bands}
          forms={bandForms}
          trackers={context.trackers}
          laterKeys={laterKeys}
          problems={bandProblems(guide, mine)}
          onChange={setBands}
        />
      )}
      {effects && <p className="text-sm text-muted-foreground">{effects}</p>}
      {loose.map((error) => (
        <p
          key={`${error.field ?? ''}${String(error.band)}${error.message}`}
          role="alert"
          className="text-sm text-destructive"
        >
          {error.field === null ? error.message : `${error.field}: ${error.message}`}
        </p>
      ))}
    </div>
  )
}

function StepCard({
  step,
  index,
  total,
  guide,
  mine,
  context,
  laterKeys,
  onChange,
  onMove,
  onDelete,
}: {
  step: StepDraft
  index: number
  total: number
  guide: StepGuide
  mine: StepError[]
  context: StepContext
  laterKeys: string[]
  onChange: (step: StepDraft) => void
  onMove: (by: number) => void
  onDelete: () => void
}) {
  const number = String(index + 1)
  const stored = step.kind === 'stored' ? step.stored : null
  const record =
    typeof stored === 'object' && stored !== null ? (stored as Record<string, unknown>) : {}
  const kind =
    step.kind === 'stored' ? (typeof record.kind === 'string' ? record.kind : 'unknown') : step.kind
  const title = step.kind === 'stored' ? record.title : step.title
  const header = (
    <>
      <span className="font-mono">{stepKey(step) || '(no key)'}</span>
      <Badge variant="outline">{kind}</Badge>
      <span>{typeof title === 'string' ? title : ''}</span>
    </>
  )
  const controls = (
    <div className="flex flex-wrap gap-2">
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={index === 0}
        aria-label={`Move step ${number} up`}
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
        disabled={index === total - 1}
        aria-label={`Move step ${number} down`}
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
        aria-label={`Delete step ${number}`}
        onClick={onDelete}
      >
        Delete
      </Button>
    </div>
  )

  return (
    <li
      role="group"
      aria-label={`Step ${number}`}
      className="rounded-xl p-3 ring-1 ring-foreground/10"
    >
      {step.kind === 'stored' ? (
        <div className="space-y-2">
          <p className="flex flex-wrap items-center gap-2 text-sm">{header}</p>
          <p className="text-sm text-muted-foreground">
            This step has a kind or shape this editor cannot edit yet; it is kept as it is.
          </p>
          {controls}
        </div>
      ) : (
        <Collapsible defaultOpen>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <CollapsibleTrigger className="flex flex-wrap items-center gap-2 text-left text-sm font-medium">
              {header}
            </CollapsibleTrigger>
            {controls}
          </div>
          <CollapsibleContent>
            <StepFields
              step={step}
              guide={guide}
              mine={mine}
              context={context}
              laterKeys={laterKeys}
              onChange={onChange}
            />
          </CollapsibleContent>
        </Collapsible>
      )}
    </li>
  )
}

/**
 * An ordered list of step cards, edited in place: add (the kind is chosen first), delete, move.
 * Controlled: `steps` and `onChange`. `errors` are the server's messages for this list, already
 * mapped by `mapStepError`; the cards flag a backward `next` before any save.
 */
export function StepListEditor({
  steps,
  context,
  errors = [],
  onChange,
}: {
  steps: StepDraft[]
  context: StepContext
  errors?: StepError[]
  onChange: (steps: StepDraft[]) => void
}) {
  const [kind, setKind] = useState<StepKind>('prompt')
  const guides = stepGuides(steps)
  const move = (index: number, by: number) => {
    const next = [...steps]
    const [step] = next.splice(index, 1)
    if (step) {
      next.splice(index + by, 0, step)
    }
    onChange(next)
  }

  return (
    <div className="space-y-3">
      {errors
        .filter((error) => error.index === null)
        .map((error) => (
          <p key={error.message} role="alert" className="text-sm text-destructive">
            {error.message}
          </p>
        ))}
      {steps.length === 0 ? (
        <p className="text-muted-foreground">No steps yet.</p>
      ) : (
        <ol className="space-y-3">
          {steps.map((step, index) => (
            // The list is edited in place and has no ids: the position is the identity.
            <StepCard
              key={index}
              step={step}
              index={index}
              total={steps.length}
              guide={guides[index] ?? { bands: {} }}
              mine={errors.filter((error) => error.index === index)}
              context={context}
              laterKeys={steps
                .slice(index + 1)
                .map(stepKey)
                .filter((key) => key !== '')}
              onChange={(changed) => {
                onChange(steps.map((current, at) => (at === index ? changed : current)))
              }}
              onMove={(by) => {
                move(index, by)
              }}
              onDelete={() => {
                onChange(steps.filter((_, at) => at !== index))
              }}
            />
          ))}
        </ol>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <NativeSelect
          aria-label="New step kind"
          value={kind}
          onChange={(event) => {
            setKind(event.target.value as StepKind)
          }}
        >
          {stepKinds.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </NativeSelect>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => {
            onChange([...steps, newStep(kind, steps)])
          }}
        >
          Add step
        </Button>
      </div>
    </div>
  )
}
