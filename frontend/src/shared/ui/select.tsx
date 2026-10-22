import * as React from 'react'
import { Select as SelectPrimitive } from 'radix-ui'
import { Check, ChevronsUpDown } from 'lucide-react'

import { cn } from '@/shared/lib/utils'

/**
 * Radix forbids an empty item value, but "None", "Not set" and "Choose a tracker…" are real
 * options here. The empty string maps to this sentinel on the way in and back on the way out,
 * so callers keep passing and receiving `''`.
 */
const EMPTY = '\u0000empty'
const toInner = (value: string) => (value === '' ? EMPTY : value)
const fromInner = (value: string) => (value === EMPTY ? '' : value)

interface Option {
  value: string
  label: React.ReactNode
  disabled: boolean
}

/** Reads `<option>` children (also inside fragments, arrays and `cond && <option>`). */
function readOptions(children: React.ReactNode, into: Option[] = []): Option[] {
  React.Children.forEach(children, (child) => {
    if (!React.isValidElement<{ children?: React.ReactNode }>(child)) {
      return
    }
    if (child.type === React.Fragment) {
      readOptions(child.props.children, into)
    } else if (child.type === 'option') {
      const props = child.props as React.ComponentProps<'option'>
      // Like a native option without a `value`, the text is the value.
      const text = typeof props.children === 'string' ? props.children : ''
      into.push({
        value: props.value === undefined ? text : String(props.value),
        label: props.children,
        disabled: props.disabled === true,
      })
    }
  })
  return into
}

interface SelectProps {
  id?: string
  className?: string
  /** Names the trigger when no `<Label htmlFor>` does. */
  'aria-label'?: string
  'aria-describedby'?: string
  'aria-invalid'?: boolean
  disabled?: boolean
  /** The current value; `''` selects the option whose value is `''`. */
  value: string
  /** Called like a native select's `onChange`, so a call site only reads `event.target.value`. */
  onChange?: (event: { target: { value: string } }) => void
  /** Called as the panel closes; `preventDefault()` keeps focus off the trigger (e.g. to focus an input). */
  onCloseAutoFocus?: (event: Event) => void
  /** 36px by default; `sm` is the 32px height for dense rows. */
  size?: 'default' | 'sm'
  /** `<option value>` elements, the same shape as `NativeSelect`. */
  children: React.ReactNode
}

/**
 * The Studio select, on the Radix Select: typeahead, arrow keys, Enter. The trigger is a
 * `combobox` that `<Label htmlFor>` names and carries `value` (the real one, `''` included), the
 * panel lists `role="option"`s. Options are `<option>` children as with `NativeSelect`, which keeps
 * call sites unchanged apart from the tag. A value that is none of the options fails closed:
 * the trigger shows it as "not an option" and is `aria-invalid`, instead of silently showing
 * the first option.
 */
function Select({
  className,
  size = 'default',
  value,
  onChange,
  onCloseAutoFocus,
  disabled,
  children,
  ...props
}: SelectProps) {
  const options = readOptions(children)
  const selected = options.find((option) => option.value === value)
  const unknown = selected === undefined
  const invalid = props['aria-invalid'] === true || unknown

  return (
    <SelectPrimitive.Root
      value={toInner(value)}
      disabled={disabled}
      onValueChange={(next) => {
        onChange?.({ target: { value: fromInner(next) } })
      }}
    >
      <SelectPrimitive.Trigger
        data-slot="select-trigger"
        data-size={size}
        value={value}
        {...props}
        aria-invalid={invalid ? true : undefined}
        className={cn(
          'flex w-full min-w-0 items-center justify-between gap-2 rounded-lg border border-input bg-well px-2.5 py-1 text-left text-base shadow-well transition-colors outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm',
          size === 'sm' ? 'h-8' : 'h-9',
          className,
        )}
      >
        <SelectPrimitive.Value>
          <span className="block truncate">
            {unknown ? `${value} (not an option)` : selected.label}
          </span>
        </SelectPrimitive.Value>
        <SelectPrimitive.Icon asChild>
          <ChevronsUpDown aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
        </SelectPrimitive.Icon>
      </SelectPrimitive.Trigger>
      <SelectPrimitive.Portal>
        <SelectPrimitive.Content
          data-slot="select-content"
          position="popper"
          onCloseAutoFocus={onCloseAutoFocus}
          sideOffset={4}
          className="z-50 max-h-[min(20rem,var(--radix-select-content-available-height))] min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-[10px] border border-border bg-card p-1 text-card-foreground shadow-lg"
        >
          <SelectPrimitive.Viewport>
            {options.map((option) => (
              <SelectPrimitive.Item
                key={option.value}
                value={toInner(option.value)}
                data-option-value={option.value}
                disabled={option.disabled}
                className="relative flex cursor-default items-center gap-2 rounded-md py-[7px] pr-8 pl-2.5 text-[13px] outline-none select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[highlighted]:bg-secondary"
              >
                <SelectPrimitive.ItemText>{option.label}</SelectPrimitive.ItemText>
                <SelectPrimitive.ItemIndicator className="absolute right-2.5 inline-flex">
                  <Check aria-hidden="true" className="size-3.5 text-primary" />
                </SelectPrimitive.ItemIndicator>
              </SelectPrimitive.Item>
            ))}
          </SelectPrimitive.Viewport>
        </SelectPrimitive.Content>
      </SelectPrimitive.Portal>
    </SelectPrimitive.Root>
  )
}

export { Select }
export type { SelectProps }
