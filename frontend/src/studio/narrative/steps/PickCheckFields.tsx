import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'
import { SegmentedControl, SegmentedControlItem } from '@/shared/ui/segmented-control'

import { TextField } from '../TextField'
import { ValueInput } from './BandsEditor'
import type { KindProps, Of } from './BranchingFields'
import { CheckList, PickField, SelectField } from './fields'
import { OutcomeEditor } from './OutcomeEditor'
import {
  bandForms,
  checkDifficulty,
  DISJOINT_DIFFICULTY,
  checkKeysOf,
  checkSpecials,
  countTargets,
  pickModes,
  isSpecialOutcome,
  NO_SUCH_SPECIAL,
  pickOutcomes,
  producibleSpecials,
  rangeOf,
  sameBands,
  setBandOutcome,
  setCheckOutcome,
  type CheckOutcomeDraft,
  type CheckSpecial,
  type OutcomeDraft,
  type PickOutcome,
} from './stepDraft'
import type { StepError } from './stepError'

const outcomeLabels: Record<PickOutcome, string> = { found: 'Found', none: 'None' }

/** As Play words them. */
const specialLabels: Record<CheckSpecial, string> = {
  critical: 'Critical',
  messyCritical: 'Messy critical',
  bestialFailure: 'Bestial failure',
}

const blankOutcome: OutcomeDraft = { next: '', effects: [], extra: {} }

const named = (keys: readonly string[], names: Record<string, string> = {}) =>
  keys.map((key) => ({ key, name: names[key] ?? key }))

export function PickFields({ step, ...p }: KindProps & { step: Of<'pick'> }) {
  return (
    <>
      <SelectField
        label="Pick of"
        value={step.of}
        options={named(countTargets)}
        error={p.fieldError('of')}
        onChange={(of) => {
          p.onChange({ ...step, of: of as typeof step.of })
        }}
      />
      <SelectField
        label="Mode"
        value={step.mode}
        options={named(pickModes, { random: 'At random', choose: 'The player chooses' })}
        error={p.fieldError('mode')}
        onChange={(mode) => {
          p.onChange({ ...step, mode: mode as typeof step.mode })
        }}
      />
      <CheckList
        legend="Tags"
        options={p.context.tags}
        value={step.tags}
        onChange={(tags) => {
          p.onChange({ ...step, tags })
        }}
      />
      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Count</legend>
        <p className="text-sm text-muted-foreground">
          How many are picked, from 1 to 10. Leave 1 and 1 for a single one.
        </p>
        <TextField
          label="Count min"
          value={step.min}
          error={p.fieldError('count.min')}
          onChange={(min) => {
            p.onChange({ ...step, min })
          }}
        />
        <TextField
          label="Count max"
          value={step.max}
          error={p.fieldError('count.max') ?? p.guide.count}
          onChange={(max) => {
            p.onChange({ ...step, max })
          }}
        />
      </fieldset>
      {pickOutcomes.map((slot) => (
        <OutcomeEditor
          key={slot}
          label={outcomeLabels[slot]}
          outcome={step.branches[slot]}
          context={p.context}
          laterKeys={p.laterKeys}
          nextGuide={p.guide.nexts[slot]}
          errors={p.mine.filter((error) => error.slot === slot)}
          onChange={(next: OutcomeDraft) => {
            p.onChange({ ...step, branches: { ...step.branches, [slot]: next } })
          }}
          onRestructure={p.onRestructure}
        />
      ))}
    </>
  )
}

/** One band of the chosen check: collapsed until it has something to show. */
function BandRow({
  band,
  outcome,
  errors,
  startOpen = false,
  ...p
}: Pick<KindProps, 'context' | 'laterKeys' | 'onRestructure'> & {
  band: string
  outcome: OutcomeDraft
  nextGuide?: string
  errors: StepError[]
  /** Open on mount, for an outcome the author has just added. */
  startOpen?: boolean
  onChange: (outcome: OutcomeDraft) => void
}) {
  const [open, setOpen] = useState(startOpen || outcome.next !== '' || outcome.effects.length > 0)

  return (
    <Collapsible open={open || errors.length > 0} onOpenChange={setOpen}>
      <CollapsibleTrigger className="text-sm font-medium">{band}</CollapsibleTrigger>
      <CollapsibleContent className="pt-2">
        <OutcomeEditor
          label={`Outcome ${band}`}
          outcome={outcome}
          errors={errors}
          allowSheet
          {...p}
        />
      </CollapsibleContent>
    </Collapsible>
  )
}

export function CheckFields({ step, ...p }: KindProps & { step: Of<'check'> }) {
  const keys = checkKeysOf(step)
  // The outcomes are keyed by the bands every check of a set shares: those of the first one.
  const chosen = p.context.checks.find((check) => check.key === keys[0])
  const bands = chosen?.bands ?? []
  const { range, disjoint, set, show: showDifficulty } = checkDifficulty(step, p.context)
  // A set offers the checks with the band keys of the first one picked, and every check it
  // already holds, so a stored one that does not fit can still be unpicked. A key the game
  // system lacks is listed by its key and flagged.
  const offered = [
    ...p.context.checks.filter(
      (check) =>
        chosen === undefined || sameBands(check.bands, chosen.bands) || keys.includes(check.key),
    ),
    ...(step.checks ?? [])
      .filter((key) => !p.context.checks.some((check) => check.key === key))
      .map((key) => ({ key, name: `${key} (not in this game system)` })),
  ]
  const at = (band: string) =>
    step.outcomes.findIndex((outcome) => !isSpecialOutcome(outcome) && outcome.band === band)
  const atSpecial = (name: CheckSpecial) =>
    step.outcomes.findIndex((outcome) => isSpecialOutcome(outcome) && outcome.special === name)
  // The special results every check can produce; null when the pools are not known, so the
  // stored ones are shown as they are and none is offered.
  const producible = producibleSpecials(step, p.context)
  const strays = step.outcomes.flatMap((outcome, position) => {
    if (isSpecialOutcome(outcome)) {
      const fits = producible === null || producible.includes(outcome.special)
      return fits && atSpecial(outcome.special) === position ? [] : [position]
    }
    return bands.includes(outcome.band) && at(outcome.band) === position ? [] : [position]
  })
  // A special result shows a row when the checks can produce it, or when it is stored and unknown.
  const specials = checkSpecials.filter((name) =>
    producible === null ? atSpecial(name) !== -1 : producible.includes(name),
  )
  const outcomeLabel = (outcome: CheckOutcomeDraft | undefined) =>
    outcome === undefined
      ? ''
      : isSpecialOutcome(outcome)
        ? specialLabels[outcome.special]
        : outcome.band
  const remove = (position: number) => {
    p.onItems(step.outcomes.length, (before) => before.filter((_, row) => row !== position))
    setOutcomes(step.outcomes.filter((_, row) => row !== position))
  }
  const messages = (position: number) => p.mine.filter((error) => error.item === position)
  const setOutcomes = (outcomes: CheckOutcomeDraft[]) => {
    p.onChange({ ...step, outcomes })
  }

  return (
    <>
      <SegmentedControl
        aria-label="Check mode"
        value={set ? 'set' : 'single'}
        onValueChange={(mode) => {
          if (mode === 'set' && !set) {
            p.onChange({ ...step, check: '', checks: keys })
          } else if (mode === 'single' && set) {
            p.onChange({ ...step, check: keys[0] ?? '', checks: null })
          }
        }}
      >
        <SegmentedControlItem value="single">One check</SegmentedControlItem>
        <SegmentedControlItem value="set">A set of checks</SegmentedControlItem>
      </SegmentedControl>
      {step.checks === null ? (
        <PickField
          label="Check"
          value={step.check}
          options={p.context.checks}
          placeholder="Pick a check"
          error={p.fieldError('check') ?? p.guide.check}
          onChange={(check) => {
            p.onChange({ ...step, check })
          }}
        />
      ) : (
        <div className="space-y-1">
          <CheckList
            legend="Checks of the set"
            options={offered}
            value={step.checks}
            error={p.fieldError('checks') ?? p.guide.checks}
            onChange={(checks) => {
              p.onChange({ ...step, checks })
            }}
          />
          <p className="text-sm text-muted-foreground">
            The player picks one when rolling. Pick 2 to 10 checks with the same bands: once the
            first is picked, only checks with its bands are offered, since the set shares one
            difficulty and one outcome per band.
          </p>
        </div>
      )}
      {showDifficulty && (
        <div className="space-y-1">
          <ValueInput
            label="Difficulty"
            value={step.difficulty}
            forms={bandForms}
            trackers={p.context.trackers}
            error={p.fieldError('difficulty') ?? p.guide.difficulty}
            onChange={(difficulty) => {
              p.onChange({ ...step, difficulty })
            }}
          />
          {disjoint && <p className="text-sm text-muted-foreground">{DISJOINT_DIFFICULTY}</p>}
          {range && (
            <p className="text-sm text-muted-foreground">
              {set
                ? `Must suit every check of the set, from ${rangeOf(range)}. Empty uses the default of each.`
                : `Default ${String(range.default)}, from ${rangeOf(range)}. Empty uses the default.`}
            </p>
          )}
        </div>
      )}
      <fieldset className="space-y-3">
        <legend className="text-sm font-medium">Outcomes</legend>
        {bands.length === 0 && (
          <p className="text-sm text-muted-foreground">
            Pick {set ? 'the checks' : 'a check'} to set what each band does.
          </p>
        )}
        {bands.map((band) => {
          const position = at(band)
          const outcome = step.outcomes[position]
          return (
            <BandRow
              key={`${keys[0] ?? ''}/${band}`}
              band={band}
              outcome={outcome ?? { next: '', effects: [], extra: {} }}
              context={p.context}
              laterKeys={p.laterKeys}
              nextGuide={p.guide.nexts[position]}
              errors={messages(position)}
              onRestructure={p.onRestructure}
              onChange={(next) => {
                const { outcomes, origin } = setBandOutcome(step.outcomes, bands, band, next)
                p.onItems(step.outcomes.length, (before) =>
                  origin.map((from) => (from === null ? null : (before[from] ?? null))),
                )
                setOutcomes(outcomes)
              }}
            />
          )
        })}
        {specials.length > 0 && (
          <div role="group" aria-label="Special results" className="space-y-3">
            <p className="text-sm font-medium">Special results</p>
            <p className="text-sm text-muted-foreground">
              A special result&apos;s outcome replaces the band&apos;s. Messy critical does not fall
              back to Critical.
            </p>
            {specials.map((name) => {
              const position = atSpecial(name)
              const label = specialLabels[name]
              const set = (next: OutcomeDraft) => {
                const { outcomes, origin } = setCheckOutcome(
                  step.outcomes,
                  bands,
                  { special: name },
                  next,
                )
                p.onItems(step.outcomes.length, (before) =>
                  origin.map((from) => (from === null ? null : (before[from] ?? null))),
                )
                setOutcomes(outcomes)
              }
              return position === -1 ? (
                <Button
                  key={name}
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    set(blankOutcome)
                  }}
                >
                  Add outcome for {label}
                </Button>
              ) : (
                <div key={name} className="space-y-2">
                  <BandRow
                    band={label}
                    outcome={step.outcomes[position] ?? blankOutcome}
                    context={p.context}
                    laterKeys={p.laterKeys}
                    nextGuide={p.guide.nexts[position]}
                    errors={messages(position)}
                    onRestructure={p.onRestructure}
                    startOpen
                    onChange={set}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-label={`Remove outcome for ${label}`}
                    onClick={() => {
                      remove(position)
                    }}
                  >
                    Remove
                  </Button>
                </div>
              )
            })}
          </div>
        )}
        {strays.map((position) => {
          const number = String(position + 1)
          const stray = step.outcomes[position]
          return (
            // The list is edited in place and has no ids: the position is the identity.
            <div
              key={position}
              role="group"
              aria-label={`Outcome ${number}`}
              className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10"
            >
              <p className="text-sm font-medium">{outcomeLabel(stray)}</p>
              <p className="text-sm text-destructive">
                {p.guide.items[position] ??
                  (stray !== undefined && isSpecialOutcome(stray)
                    ? NO_SUCH_SPECIAL
                    : 'Not a band of this check.')}
              </p>
              {messages(position).map((error, row) => (
                <p key={row} role="alert" className="text-sm text-destructive">
                  {error.field === null ? error.message : `${error.field}: ${error.message}`}
                </p>
              ))}
              <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label={`Remove outcome ${number}`}
                onClick={() => {
                  remove(position)
                }}
              >
                Remove
              </Button>
            </div>
          )
        })}
      </fieldset>
    </>
  )
}
