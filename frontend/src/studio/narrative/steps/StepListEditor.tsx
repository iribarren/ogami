import { memo, useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/shared/ui/dropdown-menu'
import { IconButton } from '@/shared/ui/icon-button'
import { Label } from '@/shared/ui/label'
import { useStableItems } from '@/shared/lib/useStableItems'
import { Select } from '@/shared/ui/select'
import { SegmentedControl, SegmentedControlItem } from '@/shared/ui/segmented-control'

import { TextField } from '../TextField'
import { AnswerHint, answerHint } from './answerHint'
import { BandsEditor, NextPicker, type BandProblems } from './BandsEditor'
import { ChoiceFields, OracleFields, TableFields } from './BranchingFields'
import { moveItem } from './draftParts'
import { CheckFields, PickFields } from './PickCheckFields'
import { EffectsEditor } from './EffectsEditor'
import { Area, CheckList } from './fields'
import type { StepContext } from './stepContext'
import { StepKindTile } from './stepKindTile'
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
  const problems: BandProblems = { upTo: {}, label: {}, next: {} }
  for (const [at, flags] of Object.entries(guide.bands)) {
    Object.assign(problems.upTo, flags.upTo === undefined ? {} : { [at]: flags.upTo })
    Object.assign(problems.next, flags.next === undefined ? {} : { [at]: flags.next })
  }
  for (const { band, effect, field, message } of mine) {
    if (
      band !== null &&
      effect === null &&
      (field === 'upTo' || field === 'label' || field === 'next')
    ) {
      problems[field][band] = message
    }
  }
  return problems
}

/** The simple fields of an open step sit two to a row; areas and nested editors span both. */
const wide = 'sm:col-span-2'

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
    <div className="grid gap-3 pt-3 sm:grid-cols-2">
      <TextField
        label="Key"
        keyField
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
      <div className={wide}>
        <Area
          label="Prompt"
          value={step.prompt}
          error={fieldError('prompt')}
          help={step.kind === 'prompt' || step.kind === 'choice' ? answerHint : undefined}
          onChange={(prompt) => {
            onChange({ ...step, prompt })
          }}
        />
      </div>
      <div className={wide}>
        <Area
          label="Tip"
          value={step.tip}
          error={fieldError('tip')}
          onChange={(tip) => {
            onChange({ ...step, tip })
          }}
        />
      </div>
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
        <div className={wide}>
          <CheckList
            legend="Inspiration tables"
            options={context.tables}
            value={step.inspiration}
            onChange={(inspiration) => {
              onChange({ ...step, inspiration })
            }}
          />
        </div>
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
            <Select
              aria-label="Reads"
              value={step.source}
              onChange={(event) => {
                onChange({ ...step, source: event.target.value as 'tracker' | 'count' })
              }}
            >
              <option value="tracker">A tracker</option>
              <option value="count">A count of open items</option>
            </Select>
          </div>
          {step.source === 'tracker' ? (
            <Select
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
            </Select>
          ) : (
            <>
              <Select
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
              </Select>
              <div className={wide}>
                <CheckList
                  legend="Tags"
                  options={context.tags}
                  value={step.tags}
                  onChange={(tags) => {
                    onChange({ ...step, tags })
                  }}
                />
              </div>
            </>
          )}
        </>
      )}
      {step.kind === 'oracle' && (
        <div className={wide}>
          <OracleFields step={step} {...kindProps} />
        </div>
      )}
      {step.kind === 'table' && (
        <div className={wide}>
          <TableFields step={step} {...kindProps} />
        </div>
      )}
      {step.kind === 'choice' && (
        <div className={wide}>
          <ChoiceFields step={step} {...kindProps} />
        </div>
      )}
      {step.kind === 'pick' && (
        <div className={wide}>
          <PickFields step={step} {...kindProps} />
        </div>
      )}
      {step.kind === 'check' && (
        <div className={wide}>
          <CheckFields step={step} {...kindProps} />
        </div>
      )}
      {(step.kind === 'roll' || step.kind === 'condition') && (
        <div className={wide}>
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
        </div>
      )}
      <div className={wide}>
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
      </div>
      {loose.map((error, at) => (
        <p key={at} role="alert" className={`${wide} text-sm text-destructive`}>
          {error.field === null ? error.message : `${error.field}: ${error.message}`}
        </p>
      ))}
    </div>
  )
}

/** The name of the check, table or oracle a step is bound to; the key when it is gone, '' when none is picked. */
function boundName(step: EditedStep, context: StepContext): string {
  const named = (items: { key: string; name: string }[], key: string) =>
    key === '' ? '' : (items.find((item) => item.key === key)?.name ?? key)
  switch (step.kind) {
    case 'check':
      return named(context.checks, step.check)
    case 'table':
      return named(context.tables, step.table)
    case 'oracle':
      return named([...context.likelihood, ...context.tables], step.oracle)
    default:
      return ''
  }
}

/**
 * One step of the list. Memoised: the callbacks are the list's own, stable ones and take the
 * position, so a card renders again only when its own step, messages or neighbours change.
 */
const StepCard = memo(function StepCard({
  step,
  index,
  total,
  current,
  answer,
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
  current: boolean
  /** The list's `errors` array: a new one is a new answer from the server. */
  answer: StepError[]
  guide: StepGuide
  mine: StepError[]
  context: StepContext
  laterKeys: string[]
  onChange: (index: number, step: StepDraft) => void
  onMove: (index: number, by: number) => void
  onDelete: (index: number) => void
  onInnerRestructure: (index: number) => void
  onItems: (
    index: number,
    count: number,
    change: (origin: (number | null)[]) => (number | null)[],
  ) => void
}) {
  const number = String(index + 1)
  const card = useRef<HTMLLIElement>(null)
  // A card the person closed opens again when the step becomes the current one, or when a new
  // answer from the server has messages for it, so what the outline or "Go to the first" points
  // at is never hidden. The same answer on a later render does not reopen it.
  const [open, setOpen] = useState(true)
  const [wasCurrent, setWasCurrent] = useState(current)
  const [seenAnswer, setSeenAnswer] = useState(answer)
  if (wasCurrent !== current) {
    setWasCurrent(current)
    if (current) {
      setOpen(true)
    }
  }
  if (seenAnswer !== answer) {
    setSeenAnswer(answer)
    if (mine.length > 0) {
      setOpen(true)
    }
  }
  // The step the outline selected comes into view.
  useEffect(() => {
    if (current) {
      card.current?.scrollIntoView({ block: 'nearest' })
    }
  }, [current])
  const stored = step.kind === 'stored' ? step.stored : null
  const record =
    typeof stored === 'object' && stored !== null ? (stored as Record<string, unknown>) : {}
  const kind =
    step.kind === 'stored' ? (typeof record.kind === 'string' ? record.kind : 'unknown') : step.kind
  const title = step.kind === 'stored' ? record.title : step.title
  const bound = step.kind === 'stored' ? '' : boundName(step, context)
  const header = (
    <>
      <StepKindTile kind={kind} />
      <span className="min-w-0 flex-1">
        <span className="block list-row-title">{typeof title === 'string' ? title : ''}</span>
        <span className="block list-row-key">{stepKey(step) || '(no key)'}</span>
      </span>
      <Badge variant="secondary">{kind}</Badge>
      {bound !== '' && <Badge variant="outline">{bound}</Badge>}
    </>
  )
  const controls = (
    <div className="flex shrink-0 gap-1">
      <IconButton
        label={`Move step ${number} up`}
        disabled={index === 0}
        onClick={() => {
          onMove(index, -1)
        }}
      >
        <ChevronUp />
      </IconButton>
      <IconButton
        label={`Move step ${number} down`}
        disabled={index === total - 1}
        onClick={() => {
          onMove(index, 1)
        }}
      >
        <ChevronDown />
      </IconButton>
      <IconButton
        label={`Delete step ${number}`}
        destructive
        onClick={() => {
          onDelete(index)
        }}
      >
        <Trash2 />
      </IconButton>
    </div>
  )

  return (
    <li
      ref={card}
      role="group"
      aria-label={`Step ${number}`}
      aria-current={current ? 'true' : undefined}
      className="rounded-xl ring-1 ring-foreground/10 aria-[current=true]:ring-2 aria-[current=true]:ring-primary/55"
    >
      {step.kind === 'stored' ? (
        <div className="space-y-2 p-2">
          <div className="flex items-center gap-2">
            <div className="flex min-w-0 flex-1 items-center gap-2 py-1 pl-2 text-sm">{header}</div>
            {controls}
          </div>
          <p className="px-2 text-sm text-muted-foreground">
            This step has a kind or shape this editor cannot edit yet; it is kept as it is.
          </p>
        </div>
      ) : (
        <Collapsible open={open} onOpenChange={setOpen}>
          <div className="flex items-center gap-2 p-2">
            <CollapsibleTrigger className="group/disclosure flex min-w-0 flex-1 items-center gap-2 rounded-lg py-1 pl-2 text-left text-sm outline-none focus-visible:ring-3 focus-visible:ring-ring/50">
              <ChevronDown
                aria-hidden="true"
                className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=closed]/disclosure:-rotate-90"
              />
              {header}
            </CollapsibleTrigger>
            {controls}
          </div>
          <CollapsibleContent className="border-t border-border px-3 pb-3">
            <AnswerHint value={step.kind === 'prompt' || step.kind === 'choice'}>
              <StepFields
                step={step}
                guide={guide}
                mine={mine}
                context={context}
                laterKeys={laterKeys}
                onChange={(changed) => {
                  onChange(index, changed)
                }}
                onInnerRestructure={() => {
                  onInnerRestructure(index)
                }}
                onItems={(count, change) => {
                  onItems(index, count, change)
                }}
              />
            </AnswerHint>
          </CollapsibleContent>
        </Collapsible>
      )}
    </li>
  )
})

const noErrors: StepError[] = []

/**
 * An ordered list of step items, edited in place: add (from a menu of the kinds), delete, move.
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
  current,
  onChange,
}: {
  steps: StepDraft[]
  context: StepContext
  errors?: StepError[]
  /** The position of the step to scroll to and mark, when the list is shown for one step. */
  current?: number
  onChange: (steps: StepDraft[]) => void
}) {
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
  // The cards are memoised: what the list derives for each keeps its reference while its content
  // is the same, and the callbacks below are stable and read the latest list through `latest`.
  const guides = useStableItems(stepGuides(steps, context))
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
  const mine = useStableItems(steps.map((_, index) => messagesOf(index)))
  const laterKeys = useStableItems(
    steps.map((_, index) =>
      steps
        .slice(index + 1)
        .map(stepKey)
        .filter((key) => key !== ''),
    ),
  )
  const latest = useRef({ steps, origins, onChange })
  useLayoutEffect(() => {
    latest.current = { steps, origins, onChange }
  })
  const change = useCallback((index: number, changed: StepDraft) => {
    const { steps: now, onChange: emit } = latest.current
    emit(now.map((current, at) => (at === index ? changed : current)))
  }, [])
  const move = useCallback((index: number, by: number) => {
    const { steps: now, origins: from, onChange: emit } = latest.current
    setOrigin(moveItem(from, index, by))
    emit(moveItem(now, index, by))
  }, [])
  const remove = useCallback((index: number) => {
    const { steps: now, origins: from, onChange: emit } = latest.current
    setOrigin(from.filter((_, at) => at !== index))
    emit(now.filter((_, at) => at !== index))
  }, [])
  const items = useCallback(
    (
      index: number,
      count: number,
      changeOrigin: (origin: (number | null)[]) => (number | null)[],
    ) => {
      const from = latest.current.origins[index]
      if (from !== null && from !== undefined) {
        setItemOrigin((current) => ({
          ...current,
          [from]: changeOrigin(current[from] ?? Array.from({ length: count }, (_, at) => at)),
        }))
      }
    },
    [],
  )
  const restructure = useCallback((index: number) => {
    const from = latest.current.origins[index]
    if (from !== null && from !== undefined) {
      setStale((current) => [...current, from])
    }
  }, [])

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
              current={current === index}
              answer={errors}
              guide={guides[index] ?? { bands: {}, nexts: {}, items: {} }}
              mine={mine[index] ?? noErrors}
              context={context}
              laterKeys={laterKeys[index] ?? []}
              onChange={change}
              onMove={move}
              onDelete={remove}
              onItems={items}
              onInnerRestructure={restructure}
            />
          ))}
        </ol>
      )}
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button type="button" variant="ghost" size="sm">
            <Plus aria-hidden="true" />
            Add step
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          {stepKinds.map((option) => (
            <DropdownMenuItem
              key={option}
              onSelect={() => {
                setOrigin([...origins, null])
                onChange([...steps, newStep(option, steps)])
              }}
            >
              <StepKindTile kind={option} />
              {option}
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
