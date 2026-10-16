import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'

import { TextField } from '../TextField'
import { ValueInput } from './BandsEditor'
import type { KindProps, Of } from './BranchingFields'
import { CheckList, PickField, SelectField } from './fields'
import { OutcomeEditor } from './OutcomeEditor'
import {
  bandForms,
  checkDifficulty,
  countTargets,
  pickModes,
  pickOutcomes,
  rangeOf,
  setBandOutcome,
  type CheckOutcomeDraft,
  type OutcomeDraft,
  type PickOutcome,
} from './stepDraft'
import type { StepError } from './stepError'

const outcomeLabels: Record<PickOutcome, string> = { found: 'Found', none: 'None' }

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
  ...p
}: Pick<KindProps, 'context' | 'laterKeys' | 'onRestructure'> & {
  band: string
  outcome: OutcomeDraft
  nextGuide?: string
  errors: StepError[]
  onChange: (outcome: OutcomeDraft) => void
}) {
  const [open, setOpen] = useState(outcome.next !== '' || outcome.effects.length > 0)

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
  const chosen = p.context.checks.find((check) => check.key === step.check)
  const bands = chosen?.bands ?? []
  const { range, show: showDifficulty } = checkDifficulty(step, p.context)
  const at = (band: string) => step.outcomes.findIndex((outcome) => outcome.band === band)
  const strays = step.outcomes.flatMap((outcome, position) =>
    bands.includes(outcome.band) && at(outcome.band) === position ? [] : [position],
  )
  const messages = (position: number) => p.mine.filter((error) => error.item === position)
  const setOutcomes = (outcomes: CheckOutcomeDraft[]) => {
    p.onChange({ ...step, outcomes })
  }

  return (
    <>
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
          {range && (
            <p className="text-sm text-muted-foreground">
              Default {String(range.default)}, from {rangeOf(range)}. Empty uses the default.
            </p>
          )}
        </div>
      )}
      <fieldset className="space-y-3">
        <legend className="text-sm font-medium">Outcomes</legend>
        {bands.length === 0 && (
          <p className="text-sm text-muted-foreground">Pick a check to set what each band does.</p>
        )}
        {bands.map((band) => {
          const position = at(band)
          const outcome = step.outcomes[position]
          return (
            <BandRow
              key={`${step.check}/${band}`}
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
        {strays.map((position) => {
          const number = String(position + 1)
          return (
            // The list is edited in place and has no ids: the position is the identity.
            <div
              key={position}
              role="group"
              aria-label={`Outcome ${number}`}
              className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10"
            >
              <p className="text-sm font-medium">{step.outcomes[position]?.band}</p>
              <p className="text-sm text-destructive">
                {p.guide.items[position] ?? 'Not a band of this check.'}
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
                  p.onItems(step.outcomes.length, (before) =>
                    before.filter((_, row) => row !== position),
                  )
                  setOutcomes(step.outcomes.filter((_, row) => row !== position))
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
