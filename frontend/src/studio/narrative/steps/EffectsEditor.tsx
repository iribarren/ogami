import { useContext, useState } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { Select } from '@/shared/ui/select'

import { TextField } from '../TextField'
import { answerHint, AnswerHint } from './answerHint'
import { ValueInput } from './BandsEditor'
import { moveItem } from './draftParts'
import {
  conditionOps,
  effectFields,
  effectGuides,
  effectKinds,
  MAX_EFFECTS,
  newEffect,
  sheetForms,
  sheetOps,
  sheetWithField,
  sheetWithOp,
  trackerForms,
  trackerOps,
  type EditedEffect,
  type EffectDraft,
  type EffectKind,
  type SheetOp,
} from './effectDraft'
import { Area, CheckList, PickField } from './fields'
import type { StepContext } from './stepContext'
import type { StepError } from './stepError'

function OpField({
  value,
  ops,
  onChange,
}: {
  value: string
  ops: readonly string[]
  onChange: (op: string) => void
}) {
  return (
    <Select
      aria-label="Operation"
      value={value}
      onChange={(event) => {
        // The select reports an empty value while its options are swapped (the field changed family).
        if (ops.includes(event.target.value)) {
          onChange(event.target.value)
        }
      }}
    >
      {ops.map((op) => (
        <option key={op} value={op}>
          {op}
        </option>
      ))}
    </Select>
  )
}

/** The fields of one effect kind; `set` changes it, `problem` is what the server said about a field. */
function EffectFields({
  effect,
  context,
  allowPick,
  problem,
  set,
  replace,
}: {
  effect: EditedEffect
  context: StepContext
  allowPick: boolean
  problem: (field: string) => string | undefined
  set: (patch: Partial<EditedEffect>) => void
  replace: (effect: EditedEffect) => void
}) {
  const { sceneTypes = [], factSlots = [], sheetFields = [] } = context
  // Where the step has an answer, the text of an effect can use it.
  const hint = useContext(AnswerHint) ? answerHint : undefined
  switch (effect.kind) {
    case 'tracker':
      return (
        <>
          <PickField
            label="Tracker"
            value={effect.tracker}
            options={context.trackers}
            placeholder="Pick a tracker"
            error={problem('tracker')}
            onChange={(tracker) => {
              set({ tracker })
            }}
          />
          <OpField
            value={effect.op}
            ops={trackerOps}
            onChange={(op) => {
              set({ op } as Partial<EditedEffect>)
            }}
          />
          <ValueInput
            label="Value"
            value={effect.value}
            forms={trackerForms}
            trackers={context.trackers}
            error={problem('value')}
            onChange={(value) => {
              set({ value })
            }}
          />
        </>
      )
    case 'nextScene':
    case 'switchSceneType':
      return (
        <PickField
          label="Scene Type"
          value={effect.sceneType}
          options={sceneTypes}
          placeholder="Pick a Scene Type"
          error={problem('sceneType')}
          onChange={(sceneType) => {
            set({ sceneType })
          }}
        />
      )
    case 'sceneTitle':
      return (
        <TextField
          label="Title"
          value={effect.title}
          error={problem('title')}
          help={hint}
          onChange={(title) => {
            set({ title })
          }}
        />
      )
    case 'createNpc':
    case 'createThread':
      return (
        <>
          <TextField
            label="Name"
            value={effect.name}
            error={problem('name')}
            help={hint}
            onChange={(name) => {
              set({ name })
            }}
          />
          {effect.kind === 'createNpc' && (
            <Area
              label="Agenda"
              value={effect.agenda}
              error={problem('agenda')}
              help={hint}
              onChange={(agenda) => {
                set({ agenda })
              }}
            />
          )}
          <CheckList
            legend="Tags"
            options={context.tags}
            value={effect.tags}
            onChange={(tags) => {
              set({ tags })
            }}
          />
          {problem('tags') && <p className="text-sm text-destructive">{problem('tags')}</p>}
        </>
      )
    case 'fillFact': {
      const type = factSlots.find((slot) => slot.key === effect.slot)?.type
      return (
        <>
          <PickField
            label="Fact slot"
            value={effect.slot}
            options={factSlots}
            placeholder="Pick a fact slot"
            error={problem('slot')}
            onChange={(slot) => {
              set({ slot })
            }}
          />
          {(type === 'text' || effect.text !== '') && (
            <TextField
              label="Text"
              value={effect.text}
              error={problem('text')}
              help={type === 'text' ? hint : 'Only a text slot takes text.'}
              onChange={(text) => {
                set({ text })
              }}
            />
          )}
        </>
      )
    }
    case 'sheet': {
      const picked = sheetFields.find((field) => field.key === effect.field)
      const conditions = effect.op === 'mark' || effect.op === 'clear'
      const onConditions = picked?.options !== undefined || conditions
      return (
        <>
          <PickField
            label="Sheet field"
            value={effect.field}
            options={sheetFields}
            placeholder={
              sheetFields.some((field) => field.options !== undefined)
                ? 'Pick a sheet field'
                : 'Pick a number or track field'
            }
            error={problem('field')}
            onChange={(field) => {
              const options = sheetFields.find((candidate) => candidate.key === field)?.options
              replace(sheetWithField(effect, field, options !== undefined))
            }}
          />
          <OpField
            value={effect.op}
            ops={onConditions ? conditionOps : sheetOps}
            onChange={(op) => {
              replace(sheetWithOp(effect, op as SheetOp))
            }}
          />
          {'option' in effect ? (
            <PickField
              label="Option"
              value={effect.option}
              options={picked?.options ?? []}
              placeholder={
                effect.op === 'clear'
                  ? 'All options'
                  : allowPick
                    ? 'The player picks'
                    : 'Pick an option'
              }
              error={problem('value')}
              onChange={(option) => {
                replace({ ...effect, option })
              }}
            />
          ) : (
            <ValueInput
              label="Value"
              value={effect.value}
              forms={sheetForms}
              trackers={[]}
              error={problem('value')}
              onChange={(value) => {
                set({ value })
              }}
            />
          )}
        </>
      )
    }
    case 'endPhase':
    case 'closeThread':
      return <p className="text-sm text-muted-foreground">This effect has no fields.</p>
  }
}

function EffectCard({
  effect,
  index,
  total,
  guide,
  errors,
  context,
  allowPick,
  onChange,
  onMove,
  onDelete,
}: {
  effect: EffectDraft
  index: number
  total: number
  guide?: string
  errors: StepError[]
  context: StepContext
  allowPick: boolean
  onChange: (effect: EffectDraft) => void
  onMove: (by: number) => void
  onDelete: () => void
}) {
  const number = String(index + 1)
  const stored = effect.kind === 'stored' ? effect.stored : null
  const kind =
    effect.kind !== 'stored'
      ? effect.kind
      : typeof stored === 'object' &&
          stored !== null &&
          'kind' in stored &&
          typeof stored.kind === 'string'
        ? stored.kind
        : 'unknown'
  const inline = effect.kind === 'stored' ? [] : effectFields[effect.kind]
  const loose = errors.filter((error) => error.field === null || !inline.includes(error.field))

  return (
    <li
      role="group"
      aria-label={`Effect ${number}`}
      className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Badge variant="outline">{kind}</Badge>
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={index === 0}
            aria-label={`Move effect ${number} up`}
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
            aria-label={`Move effect ${number} down`}
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
            aria-label={`Delete effect ${number}`}
            onClick={onDelete}
          >
            Delete
          </Button>
        </div>
      </div>
      {effect.kind === 'stored' ? (
        <p className="text-sm text-muted-foreground">
          This effect has a kind or shape this editor cannot edit; it is kept as it is.
        </p>
      ) : (
        <EffectFields
          effect={effect}
          context={context}
          allowPick={allowPick}
          problem={(field) => errors.find((error) => error.field === field)?.message}
          set={(patch) => {
            onChange({ ...effect, ...patch } as EffectDraft)
          }}
          replace={onChange}
        />
      )}
      {guide && <p className="text-sm text-destructive">{guide}</p>}
      {loose.map((error, at) => (
        <p key={at} role="alert" className="text-sm text-destructive">
          {error.field === null ? error.message : `${error.field}: ${error.message}`}
        </p>
      ))}
    </li>
  )
}

/**
 * An ordered list of effect cards, edited in place: add (the kind is chosen first), delete, move,
 * at most ten. A sheet effect is offered only where `allowSheet`; one stored elsewhere stays,
 * flagged. `kinds` narrows the kinds offered (the cost of a push takes sheet and tracker effects
 * only); `allowPick` lets a mark on a conditions field leave its option to the player, which only
 * the cost of a push does. `errors` are the server's messages about these effects (`effect` is their position).
 * `onRestructure` tells the owner of the messages that effects moved or went.
 */
export function EffectsEditor({
  label,
  effects,
  context,
  allowSheet,
  kinds,
  allowPick = false,
  errors,
  onChange,
  onRestructure,
}: {
  label: string
  effects: EffectDraft[]
  context: StepContext
  allowSheet: boolean
  kinds?: readonly EffectKind[]
  allowPick?: boolean
  errors: StepError[]
  onChange: (effects: EffectDraft[]) => void
  onRestructure?: () => void
}) {
  const [kind, setKind] = useState<EffectKind>('tracker')
  const offered = effectKinds.filter(
    (option) =>
      (allowSheet || option !== 'sheet') && (kinds === undefined || kinds.includes(option)),
  )
  const guides = effectGuides(effects, allowSheet)
  const full = effects.length >= MAX_EFFECTS

  return (
    <fieldset className="space-y-3">
      <legend className="text-sm font-medium">{label}</legend>
      {errors
        .filter((error) => error.effect === null || error.effect >= effects.length)
        .map((error, at) => (
          <p key={at} role="alert" className="text-sm text-destructive">
            {error.message}
          </p>
        ))}
      {effects.length === 0 ? (
        <p className="text-sm text-muted-foreground">No effects.</p>
      ) : (
        <ol className="space-y-3">
          {effects.map((effect, index) => (
            // The list is edited in place and has no ids: the position is the identity.
            <EffectCard
              key={index}
              effect={effect}
              index={index}
              total={effects.length}
              guide={guides[index]}
              errors={errors.filter((error) => error.effect === index)}
              context={context}
              allowPick={allowPick}
              onChange={(changed) => {
                onChange(effects.map((current, at) => (at === index ? changed : current)))
              }}
              onMove={(by) => {
                onRestructure?.()
                onChange(moveItem(effects, index, by))
              }}
              onDelete={() => {
                onRestructure?.()
                onChange(effects.filter((_, at) => at !== index))
              }}
            />
          ))}
        </ol>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <Select
          aria-label="New effect kind"
          // Ten kinds are taller than the default panel: the last would hide behind a scroll.
          contentClassName="max-h-[min(24rem,var(--radix-select-content-available-height))]"
          value={kind}
          onChange={(event) => {
            setKind(event.target.value as EffectKind)
          }}
        >
          {offered.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </Select>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={full}
          onClick={() => {
            onChange([...effects, newEffect(offered.includes(kind) ? kind : 'tracker')])
          }}
        >
          Add effect
        </Button>
        {full && (
          <p className="text-sm text-muted-foreground">
            At most {String(MAX_EFFECTS)} effects in a list.
          </p>
        )}
      </div>
    </fieldset>
  )
}
