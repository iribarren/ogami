import { useId, useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { slotsUsed, stackGear, stackName, stackSlots } from './sheetValues'
import type { SheetFieldView, SheetGear, SheetStack } from './types'

const defaultMaxStacks = 50
const maxQuantity = 9999
const maxSlots = 100
const maxNameLength = 200

function slotsText(slots: number): string {
  return `${String(slots)} ${slots === 1 ? 'slot' : 'slots'}`
}

/** The catalog entries the field accepts: its categories, or every entry when it names none. */
function accepted(field: SheetFieldView, gear: SheetGear[]): SheetGear[] {
  const { categories } = field
  return categories == null
    ? gear
    : gear.filter(({ category }) => category !== null && categories.includes(category))
}

/** The accepted entries grouped by category, in catalog order; entries with none go last. */
function byCategory(entries: SheetGear[]): { category: string | null; entries: SheetGear[] }[] {
  const groups: { category: string | null; entries: SheetGear[] }[] = []
  for (const entry of entries) {
    const group = groups.find(({ category }) => category === entry.category)
    if (group === undefined) {
      groups.push({ category: entry.category, entries: [entry] })
    } else {
      group.entries.push(entry)
    }
  }
  return groups.sort((a, b) => Number(a.category === null) - Number(b.category === null))
}

/**
 * An inventory field's stacks (ADR 0045): each with its quantity, the slots it takes and a remove
 * button; the slots used against the capacity; and where to add one, a catalog stack by picking
 * gear (gear already held gains a unit) or a free stack by name and slots. The server stays the
 * authority: going over the capacity only warns here, and its refusal shows as the form's error.
 */
export function InventoryControl({
  field,
  stacks,
  gear,
  hint,
  describedBy,
  onChange,
}: {
  field: SheetFieldView
  stacks: SheetStack[]
  /** The release's whole catalog; the field offers the part it accepts. */
  gear: SheetGear[]
  hint: React.ReactNode
  describedBy: string | undefined
  onChange: (stacks: SheetStack[]) => void
}) {
  const id = useId()
  const [picked, setPicked] = useState('')
  const [freeName, setFreeName] = useState('')
  const [freeSlots, setFreeSlots] = useState('0')
  const offered = accepted(field, gear)
  const used = slotsUsed(stacks, gear)
  const over = field.capacity != null && used > field.capacity
  const held = (key: string) => stacks.some((stack) => 'gear' in stack && stack.gear === key)
  const full = stacks.length >= (field.maxStacks ?? defaultMaxStacks)
  const slots = Number(freeSlots)
  const freeReady =
    freeName.trim() !== '' && Number.isInteger(slots) && slots >= 0 && slots <= maxSlots

  function addGear() {
    if (picked === '') return
    onChange(
      held(picked)
        ? stacks.map((stack) =>
            'gear' in stack && stack.gear === picked
              ? { ...stack, quantity: Math.min(maxQuantity, stack.quantity + 1) }
              : stack,
          )
        : [...stacks, { gear: picked, quantity: 1 }],
    )
    setPicked('')
  }

  function addFree() {
    onChange([...stacks, { name: freeName.trim(), quantity: 1, slots }])
    setFreeName('')
    setFreeSlots('0')
  }

  return (
    <fieldset className="space-y-2" aria-describedby={describedBy}>
      <legend className="text-sm font-medium">{field.label}</legend>
      {hint}
      <p aria-live="polite" className={over ? 'text-sm font-medium text-destructive' : 'text-sm'}>
        Slots used: {used}
        {field.capacity != null && ` / ${String(field.capacity)}`}
      </p>
      {over && (
        <p role="alert" className="text-sm font-medium text-destructive">
          {field.label} holds {field.capacity} slots, {used} used. Remove something before saving.
        </p>
      )}
      {stacks.length === 0 && <p className="text-sm text-muted-foreground">Nothing held.</p>}
      {stacks.map((stack, index) => {
        const name = stackName(stack, gear)
        const entry = stackGear(stack, gear)
        return (
          <div
            key={'gear' in stack ? stack.gear : `${String(index)}-${stack.name}`}
            className="flex flex-wrap items-center gap-2"
          >
            <span className="min-w-0 flex-1 break-words">
              {name}
              {entry?.category != null && (
                <span className="text-xs text-muted-foreground"> · {entry.category}</span>
              )}
            </span>
            <Input
              type="number"
              className="w-20"
              aria-label={`Quantity of ${name}`}
              min={1}
              max={maxQuantity}
              value={stack.quantity}
              onChange={(event) => {
                const quantity = Math.min(
                  maxQuantity,
                  Math.max(1, Math.trunc(Number(event.target.value)) || 1),
                )
                onChange(stacks.map((held, at) => (at === index ? { ...held, quantity } : held)))
              }}
            />
            <span className="w-16 text-xs text-muted-foreground">
              {slotsText(stackSlots(stack, gear))}
            </span>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              aria-label={`Remove ${name}`}
              onClick={() => {
                onChange(stacks.filter((_, at) => at !== index))
              }}
            >
              Remove
            </Button>
          </div>
        )
      })}
      {offered.length > 0 && (
        <div className="flex items-end gap-2">
          <div className="min-w-0 flex-1 space-y-1">
            <Label htmlFor={`${id}-gear`}>Gear to add to {field.label}</Label>
            <NativeSelect
              id={`${id}-gear`}
              value={picked}
              onChange={(event) => {
                setPicked(event.target.value)
              }}
            >
              <option value="">Choose gear…</option>
              {byCategory(offered).map(({ category, entries }) => (
                <optgroup key={category ?? ''} label={category ?? 'Other'}>
                  {entries.map((entry) => (
                    <option key={entry.key} value={entry.key}>
                      {entry.name} ({slotsText(entry.slots)})
                    </option>
                  ))}
                </optgroup>
              ))}
            </NativeSelect>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={picked === '' || (full && !held(picked))}
            onClick={addGear}
          >
            Add gear
          </Button>
        </div>
      )}
      <div className="flex items-end gap-2">
        <div className="min-w-0 flex-1 space-y-1">
          <Label htmlFor={`${id}-name`}>Free stack name</Label>
          <Input
            id={`${id}-name`}
            maxLength={maxNameLength}
            value={freeName}
            onChange={(event) => {
              setFreeName(event.target.value)
            }}
          />
        </div>
        <div className="w-20 space-y-1">
          <Label htmlFor={`${id}-slots`}>Slots each</Label>
          <Input
            id={`${id}-slots`}
            type="number"
            min={0}
            max={maxSlots}
            value={freeSlots}
            onChange={(event) => {
              setFreeSlots(event.target.value)
            }}
          />
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={!freeReady || full}
          onClick={addFree}
        >
          Add free stack
        </Button>
      </div>
      {full && (
        <p className="text-xs text-muted-foreground">
          {field.label} holds at most {field.maxStacks ?? defaultMaxStacks} stacks.
        </p>
      )}
    </fieldset>
  )
}
