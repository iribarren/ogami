import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible'

import { TextField } from '../TextField'
import { moveItem } from './draftParts'
import { PickField } from './fields'
import { OutcomeEditor, RowControls } from './OutcomeEditor'
import type { StepContext } from './stepContext'
import {
  MAX_BRANCHES,
  MAX_OPTIONS,
  MIN_OPTIONS,
  newBranch,
  newOption,
  oracleAnswers,
  type EditedStep,
  type OracleAnswer,
  type OutcomeDraft,
  type StepDraft,
  type StepGuide,
} from './stepDraft'
import type { StepError } from './stepError'

export type Of<K extends EditedStep['kind']> = Extract<EditedStep, { kind: K }>

/** What the fields of a branching kind share with the card around them. */
export interface KindProps {
  context: StepContext
  laterKeys: string[]
  guide: StepGuide
  /** The server's messages about this step. */
  mine: StepError[]
  /** What the server said about one of the step's own fields. */
  fieldError: (field: string) => string | undefined
  onChange: (step: StepDraft) => void
  /** Effects inside an outcome moved or went: messages keyed by their position no longer line up. */
  onRestructure: () => void
  /** Branches or options moved or went; `change` maps where each had been, so messages follow them. */
  onItems: (count: number, change: (origin: (number | null)[]) => (number | null)[]) => void
}

const answerLabels: Record<OracleAnswer, string> = {
  yes: 'Yes',
  no: 'No',
  exceptionalYes: 'Exceptional yes',
  exceptionalNo: 'Exceptional no',
}

/**
 * The first message about each field the row really has (`fields`) is shown next to its input;
 * every other message of the row (a second one on the field, one about a field this kind of row
 * lacks) goes to its outcome, listed, never hidden or shown under another field.
 */
function splitRow(mine: StepError[], fields: string[]) {
  const onFields: Record<string, StepError> = {}
  for (const error of mine) {
    const { field } = error
    if (error.effect === null && field !== null && fields.includes(field) && !(field in onFields)) {
      onFields[field] = error
    }
  }
  return { onFields, rest: mine.filter((error) => !Object.values(onFields).includes(error)) }
}

const keysOf = (keys: string[]) =>
  [...new Set(keys.filter((key) => key !== ''))].map((key) => ({ key, name: key }))

export function OracleFields({ step, ...p }: KindProps & { step: Of<'oracle'> }) {
  const levels = p.context.likelihood.find((oracle) => oracle.key === step.oracle)?.levels ?? []
  const exceptional = (['exceptionalYes', 'exceptionalNo'] as const).filter(
    (answer) =>
      step.branches[answer].next !== '' ||
      step.branches[answer].effects.length > 0 ||
      p.mine.some((error) => error.slot === answer),
  )
  const [open, setOpen] = useState(false)
  const outcome = (answer: OracleAnswer) => (
    <OutcomeEditor
      label={answerLabels[answer]}
      outcome={step.branches[answer]}
      context={p.context}
      laterKeys={p.laterKeys}
      nextGuide={p.guide.nexts[answer]}
      errors={p.mine.filter((error) => error.slot === answer)}
      onChange={(next: OutcomeDraft) => {
        p.onChange({ ...step, branches: { ...step.branches, [answer]: next } })
      }}
      onRestructure={p.onRestructure}
    />
  )

  return (
    <>
      <PickField
        label="Oracle"
        value={step.oracle}
        options={p.context.likelihood}
        placeholder="Pick a likelihood oracle"
        error={p.fieldError('oracle')}
        onChange={(oracle) => {
          p.onChange({ ...step, oracle })
        }}
      />
      <PickField
        label="Likelihood level"
        value={step.level}
        options={keysOf(levels)}
        placeholder="No level"
        error={p.fieldError('likelihood') ?? p.guide.level}
        onChange={(level) => {
          p.onChange({ ...step, level })
        }}
      />
      {outcome('yes')}
      {outcome('no')}
      <Collapsible open={open || exceptional.length > 0} onOpenChange={setOpen}>
        <CollapsibleTrigger className="text-sm font-medium">Exceptional answers</CollapsibleTrigger>
        <p className="text-sm text-muted-foreground">
          An exceptional answer without its branch uses yes / no.
        </p>
        <CollapsibleContent className="space-y-3 pt-2">
          {oracleAnswers.slice(2).map((answer) => (
            <div key={answer}>{outcome(answer)}</div>
          ))}
        </CollapsibleContent>
      </Collapsible>
    </>
  )
}

export function TableFields({ step, ...p }: KindProps & { step: Of<'table'> }) {
  const entries = p.context.tables.find((table) => table.key === step.table)?.entries ?? []
  const setBranches = (branches: Of<'table'>['branches']) => {
    p.onChange({ ...step, branches })
  }

  return (
    <>
      <PickField
        label="Table"
        value={step.table}
        options={p.context.tables}
        placeholder="Pick a table"
        error={p.fieldError('table')}
        onChange={(table) => {
          p.onChange({ ...step, table })
        }}
      />
      <fieldset className="space-y-3">
        <legend className="text-sm font-medium">Branches</legend>
        {step.branches.map((branch, at) => {
          const number = String(at + 1)
          const { onFields, rest } = splitRow(
            p.mine.filter((error) => error.item === at),
            ['entry'],
          )
          return (
            // The list is edited in place and has no ids: the position is the identity.
            <div
              key={at}
              role="group"
              aria-label={`Branch ${number}`}
              className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10"
            >
              <PickField
                label="Entry"
                value={branch.entry}
                options={keysOf(entries)}
                placeholder="Pick an entry"
                error={onFields.entry?.message ?? p.guide.items[at]}
                onChange={(entry) => {
                  setBranches(
                    step.branches.map((current, to) =>
                      to === at ? { ...current, entry } : current,
                    ),
                  )
                }}
              />
              <OutcomeEditor
                label={`Branch ${number} outcome`}
                outcome={branch}
                context={p.context}
                laterKeys={p.laterKeys}
                nextGuide={p.guide.nexts[at]}
                errors={rest}
                onChange={(outcome) => {
                  setBranches(
                    step.branches.map((current, to) =>
                      to === at ? { ...current, ...outcome } : current,
                    ),
                  )
                }}
                onRestructure={p.onRestructure}
              />
              <RowControls
                noun="branch"
                index={at}
                total={step.branches.length}
                onMove={(by) => {
                  p.onItems(step.branches.length, (origin) => moveItem(origin, at, by))
                  setBranches(moveItem(step.branches, at, by))
                }}
                onDelete={() => {
                  p.onItems(step.branches.length, (origin) => origin.filter((_, to) => to !== at))
                  setBranches(step.branches.filter((_, to) => to !== at))
                }}
              />
            </div>
          )
        })}
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={step.branches.length >= MAX_BRANCHES}
          onClick={() => {
            p.onItems(step.branches.length, (origin) => [...origin, null])
            setBranches([...step.branches, newBranch()])
          }}
        >
          Add branch
        </Button>
        {step.branches.length >= MAX_BRANCHES && (
          <p className="text-sm text-muted-foreground">At most {String(MAX_BRANCHES)} branches.</p>
        )}
      </fieldset>
      <OutcomeEditor
        label="Otherwise"
        outcome={step.otherwise}
        context={p.context}
        laterKeys={p.laterKeys}
        nextGuide={p.guide.nexts.otherwise}
        errors={p.mine.filter((error) => error.slot === 'otherwise')}
        onChange={(otherwise) => {
          p.onChange({ ...step, otherwise })
        }}
        onRestructure={p.onRestructure}
      />
    </>
  )
}

export function ChoiceFields({ step, ...p }: KindProps & { step: Of<'choice'> }) {
  const count = step.options.length
  const setOptions = (options: Of<'choice'>['options'], skip = step.skip) => {
    p.onChange({ ...step, options, skip })
  }

  return (
    <>
      <fieldset className="space-y-3">
        <legend className="text-sm font-medium">Options</legend>
        {step.options.map((option, at) => {
          const number = String(at + 1)
          const { onFields, rest } = splitRow(
            p.mine.filter((error) => error.item === at),
            ['key', 'label'],
          )
          const replace = (patch: Partial<typeof option>, skip = step.skip) => {
            setOptions(
              step.options.map((current, to) => (to === at ? { ...current, ...patch } : current)),
              skip,
            )
          }
          return (
            // The list is edited in place and has no ids: the position is the identity.
            <div
              key={at}
              role="group"
              aria-label={`Option ${number}`}
              className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10"
            >
              <TextField
                label="Option key"
                keyField
                value={option.key}
                error={onFields.key?.message ?? p.guide.items[at]}
                onChange={(key) => {
                  // The option that is skipped keeps being the skipped one when it is renamed.
                  replace({ key }, step.skip === option.key ? key : step.skip)
                }}
              />
              <TextField
                label="Label"
                value={option.label}
                error={onFields.label?.message}
                onChange={(label) => {
                  replace({ label })
                }}
              />
              <OutcomeEditor
                label={`Option ${number} outcome`}
                outcome={option}
                context={p.context}
                laterKeys={p.laterKeys}
                nextGuide={p.guide.nexts[at]}
                errors={rest}
                onChange={(outcome) => {
                  replace(outcome)
                }}
                onRestructure={p.onRestructure}
              />
              <RowControls
                noun="option"
                index={at}
                total={count}
                canDelete={count > MIN_OPTIONS}
                onMove={(by) => {
                  p.onItems(count, (origin) => moveItem(origin, at, by))
                  setOptions(moveItem(step.options, at, by))
                }}
                onDelete={() => {
                  p.onItems(count, (origin) => origin.filter((_, to) => to !== at))
                  setOptions(step.options.filter((_, to) => to !== at))
                }}
              />
            </div>
          )
        })}
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={count >= MAX_OPTIONS}
          onClick={() => {
            p.onItems(count, (origin) => [...origin, null])
            setOptions([...step.options, newOption(step.options)])
          }}
        >
          Add option
        </Button>
        {count >= MAX_OPTIONS && (
          <p className="text-sm text-muted-foreground">At most {String(MAX_OPTIONS)} options.</p>
        )}
        {count <= MIN_OPTIONS && (
          <p className="text-sm text-muted-foreground">
            A choice needs at least {String(MIN_OPTIONS)} options.
          </p>
        )}
      </fieldset>
      <PickField
        label="Skip"
        value={step.skip}
        options={keysOf(step.options.map((option) => option.key))}
        placeholder="No option"
        error={p.fieldError('skip') ?? p.guide.skip}
        onChange={(skip) => {
          p.onChange({ ...step, skip })
        }}
      />
    </>
  )
}
