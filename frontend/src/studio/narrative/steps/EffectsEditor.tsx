import { useState } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { NativeSelect } from '@/shared/ui/native-select'

import { TextField } from '../TextField'
import { ValueInput } from './BandsEditor'
import { moveItem } from './draftParts'
import {
  effectFields,
  effectGuides,
  effectKinds,
  MAX_EFFECTS,
  newEffect,
  sheetForms,
  sheetOps,
  trackerForms,
  trackerOps,
  type EditedEffect,
  type EffectDraft,
  type EffectKind,
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
    <NativeSelect
      aria-label="Operation"
      value={value}
      onChange={(event) => {
        onChange(event.target.value)
      }}
    >
      {ops.map((op) => (
        <option key={op} value={op}>
          {op}
        </option>
      ))}
    </NativeSelect>
  )
}

/** The fields of one effect kind; `set` changes it, `problem` is what the server said about a field. */
function EffectFields({
  effect,
  context,
  problem,
  set,
}: {
  effect: EditedEffect
  context: StepContext
  problem: (field: string) => string | undefined
  set: (patch: Partial<EditedEffect>) => void
}) {
  const { sceneTypes = [], factSlots = [], sheetFields = [] } = context
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
            onChange={(name) => {
              set({ name })
            }}
          />
          {effect.kind === 'createNpc' && (
            <Area
              label="Agenda"
              value={effect.agenda}
              error={problem('agenda')}
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
              help={type === 'text' ? undefined : 'Only a text slot takes text.'}
              onChange={(text) => {
                set({ text })
              }}
            />
          )}
        </>
      )
    }
    case 'sheet':
      return (
        <>
          <PickField
            label="Sheet field"
            value={effect.field}
            options={sheetFields}
            placeholder="Pick a number or track field"
            error={problem('field')}
            onChange={(field) => {
              set({ field })
            }}
          />
          <OpField
            value={effect.op}
            ops={sheetOps}
            onChange={(op) => {
              set({ op } as Partial<EditedEffect>)
            }}
          />
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
        </>
      )
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
          problem={(field) => errors.find((error) => error.field === field)?.message}
          set={(patch) => {
            onChange({ ...effect, ...patch } as EffectDraft)
          }}
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
 * flagged. `errors` are the server's messages about these effects (`effect` is their position).
 * `onRestructure` tells the owner of the messages that effects moved or went.
 */
export function EffectsEditor({
  label,
  effects,
  context,
  allowSheet,
  errors,
  onChange,
  onRestructure,
}: {
  label: string
  effects: EffectDraft[]
  context: StepContext
  allowSheet: boolean
  errors: StepError[]
  onChange: (effects: EffectDraft[]) => void
  onRestructure?: () => void
}) {
  const [kind, setKind] = useState<EffectKind>('tracker')
  const offered = effectKinds.filter((option) => allowSheet || option !== 'sheet')
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
        <NativeSelect
          aria-label="New effect kind"
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
        </NativeSelect>
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
