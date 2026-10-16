import { useState } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
import { SegmentedControl, SegmentedControlItem } from '@/shared/ui/segmented-control'

import { TextField } from '../TextField'
import { BandsEditor, NextPicker, type BandProblems } from './BandsEditor'
import { ChoiceFields, OracleFields, TableFields } from './BranchingFields'
import { moveItem } from './draftParts'
import { CheckFields, PickFields } from './PickCheckFields'
import { EffectsEditor } from './EffectsEditor'
import { Area, CheckList } from './fields'
import type { StepContext } from './stepContext'
import {
  bandForms,
  checkDifficulty,
  countTargets,
  newStep,
  oracleAnswers,
  pickOutcomes,
  stepGuides,
  stepKey,
  stepKinds,
  type BandDraft,
  type CountTarget,
  type EditedStep,
  type StepDraft,
  type StepGuide,
  type StepKind,
} from './stepDraft'
import { aboutEffects, type StepError } from './stepError'

/** The bands' problems: what the guides and the server say about each band's limit and next. */
function bandProblems(guide: StepGuide, mine: StepError[]): BandProblems {
  const problems: BandProblems = { upTo: {}, next: {} }
  for (const [at, flags] of Object.entries(guide.bands)) {
    Object.assign(problems.upTo, flags.upTo === undefined ? {} : { [at]: flags.upTo })
    Object.assign(problems.next, flags.next === undefined ? {} : { [at]: flags.next })
  }
  for (const { band, effect, field, message } of mine) {
    if (band !== null && effect === null && (field === 'upTo' || field === 'next')) {
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
  onInnerRestructure,
  onItems,
}: {
  step: EditedStep
  guide: StepGuide
  mine: StepError[]
  context: StepContext
  laterKeys: string[]
  onChange: (step: StepDraft) => void
  onInnerRestructure: () => void
  onItems: (count: number, change: (origin: (number | null)[]) => (number | null)[]) => void
}) {
  const fieldError = (field: string) =>
    mine.find(
      (error) =>
        error.band === null &&
        error.effect === null &&
        error.slot === undefined &&
        error.item === undefined &&
        error.field === field,
    )?.message
  const setBands = (bands: BandDraft[]) => {
    onChange({ ...step, bands } as StepDraft)
  }
  // Everything the form shows next to a field for this kind of step; the rest is listed on the card.
  const ownFields: Partial<Record<StepKind, string[]>> = {
    roll: ['dice'],
    oracle: ['oracle', 'likelihood'],
    table: ['table'],
    choice: ['skip'],
    pick: ['of', 'mode', 'count.min', 'count.max'],
    check:
      step.kind === 'check'
        ? ['check', ...(checkDifficulty(step, context).show ? ['difficulty'] : [])]
        : [],
  }
  const inline = ['key', 'title', 'prompt', 'tip', 'next', ...(ownFields[step.kind] ?? [])]
  const bands = 'bands' in step ? step.bands.length : 0
  const items =
    'options' in step
      ? step.options.length
      : 'outcomes' in step
        ? step.outcomes.length
        : 'branches' in step && Array.isArray(step.branches)
          ? step.branches.length
          : 0
  // A message about a part the step does not have (a band, a branch, an option) is listed here, not lost.
  const shown = (error: StepError) => {
    if (error.band !== null) {
      return (
        error.band < bands && (aboutEffects(error) || ['upTo', 'next'].includes(error.field ?? ''))
      )
    }
    if (error.slot !== undefined) {
      const slots = { table: ['otherwise'], pick: pickOutcomes, oracle: oracleAnswers }
      return (
        (slots[step.kind as keyof typeof slots] as readonly string[] | undefined)?.includes(
          error.slot,
        ) ?? false
      )
    }
    if (error.item !== undefined) {
      return error.item < items
    }
    return aboutEffects(error) || inline.includes(error.field ?? '')
  }
  const loose = mine.filter((error) => error.index === null || !shown(error))
  const kindProps = {
    context,
    laterKeys,
    guide,
    mine,
    fieldError,
    onChange,
    onRestructure: onInnerRestructure,
    onItems,
  }

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
      {step.kind === 'oracle' && <OracleFields step={step} {...kindProps} />}
      {step.kind === 'table' && <TableFields step={step} {...kindProps} />}
      {step.kind === 'choice' && <ChoiceFields step={step} {...kindProps} />}
      {step.kind === 'pick' && <PickFields step={step} {...kindProps} />}
      {step.kind === 'check' && <CheckFields step={step} {...kindProps} />}
      {(step.kind === 'roll' || step.kind === 'condition') && (
        <BandsEditor
          bands={step.bands}
          forms={bandForms}
          context={context}
          laterKeys={laterKeys}
          problems={bandProblems(guide, mine)}
          effectErrors={mine.filter((error) => error.band !== null && aboutEffects(error))}
          onChange={setBands}
          onRestructure={onInnerRestructure}
        />
      )}
      <EffectsEditor
        label="Effects"
        effects={step.effects}
        context={context}
        allowSheet={step.kind === 'character'}
        errors={mine.filter(
          (error) =>
            error.band === null &&
            error.slot === undefined &&
            error.item === undefined &&
            aboutEffects(error),
        )}
        onChange={(effects) => {
          onChange({ ...step, effects })
        }}
        onRestructure={onInnerRestructure}
      />
      {loose.map((error, at) => (
        <p key={at} role="alert" className="text-sm text-destructive">
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
  onInnerRestructure,
  onItems,
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
  onInnerRestructure: () => void
  onItems: (count: number, change: (origin: (number | null)[]) => (number | null)[]) => void
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
              onInnerRestructure={onInnerRestructure}
              onItems={onItems}
            />
          </CollapsibleContent>
        </Collapsible>
      )}
    </li>
  )
}

const noErrors: StepError[] = []

/**
 * An ordered list of step cards, edited in place: add (the kind is chosen first), delete, move.
 * Controlled: `steps` and `onChange`. `errors` are the server's messages for this list, already
 * mapped by `mapStepError`; the cards flag a backward `next` before any save. A new `errors` array
 * is a new answer from the server (keep it in state, do not rebuild it on every render): its
 * positions are the positions the steps have now, and the editor follows each step from there
 * through moves and deletes, so a message never lands on another card.
 */
export function StepListEditor({
  steps,
  context,
  errors = noErrors,
  onChange,
}: {
  steps: StepDraft[]
  context: StepContext
  errors?: StepError[]
  onChange: (steps: StepDraft[]) => void
}) {
  const [kind, setKind] = useState<StepKind>('prompt')
  // The position each step had when `errors` arrived (null: added since), and the steps whose band
  // messages about bands or effects no longer line up because those moved or went.
  const [answered, setAnswered] = useState(errors)
  const [origin, setOrigin] = useState<(number | null)[]>(() => steps.map((_, at) => at))
  const [stale, setStale] = useState<number[]>([])
  // Where each step's branches or options (by the step's origin) came from, once any moved or went.
  const [itemOrigin, setItemOrigin] = useState<Record<number, (number | null)[]>>({})
  if (answered !== errors) {
    setAnswered(errors)
    setOrigin(steps.map((_, at) => at))
    setStale([])
    setItemOrigin({})
  }
  // A list replaced from outside has no known origins: its server messages are not shown on cards.
  const origins = origin.length === steps.length ? origin : steps.map(() => null)
  const guides = stepGuides(steps, context)
  const messagesOf = (index: number) => {
    const from = origins[index]
    const moved = from === null || from === undefined ? undefined : itemOrigin[from]
    return errors
      .filter(
        (error) =>
          error.index !== null &&
          error.index === from &&
          !((error.band !== null || error.effect !== null) && stale.includes(from)),
      )
      .flatMap((error) => {
        // A message about a branch or option goes where that one is now, and goes when it does.
        const at =
          error.item === undefined || moved === undefined ? error.item : moved.indexOf(error.item)
        return at === -1 ? [] : [at === error.item ? error : { ...error, item: at }]
      })
  }

  return (
    <div className="space-y-3">
      {errors
        .filter((error) => error.index === null)
        .map((error, at) => (
          <p key={at} role="alert" className="text-sm text-destructive">
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
              guide={guides[index] ?? { bands: {}, nexts: {}, items: {} }}
              mine={messagesOf(index)}
              context={context}
              laterKeys={steps
                .slice(index + 1)
                .map(stepKey)
                .filter((key) => key !== '')}
              onChange={(changed) => {
                onChange(steps.map((current, at) => (at === index ? changed : current)))
              }}
              onMove={(by) => {
                setOrigin(moveItem(origins, index, by))
                onChange(moveItem(steps, index, by))
              }}
              onDelete={() => {
                setOrigin(origins.filter((_, at) => at !== index))
                onChange(steps.filter((_, at) => at !== index))
              }}
              onItems={(count, change) => {
                const from = origins[index]
                if (from !== null && from !== undefined) {
                  setItemOrigin((current) => ({
                    ...current,
                    [from]: change(current[from] ?? Array.from({ length: count }, (_, at) => at)),
                  }))
                }
              }}
              onInnerRestructure={() => {
                const from = origins[index]
                if (from !== null && from !== undefined) {
                  setStale((current) => [...current, from])
                }
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
            setOrigin([...origins, null])
            onChange([...steps, newStep(kind, steps)])
          }}
        >
          Add step
        </Button>
      </div>
    </div>
  )
}
