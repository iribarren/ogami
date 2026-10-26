import { ArrowDown, ArrowUp, Plus, X } from 'lucide-react'
import { useId } from 'react'

import { cn } from '@/shared/lib/utils'
import { FieldError } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import { IconButton } from '@/shared/ui/icon-button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { KeyHint } from '../KeyHint'
import {
  editOptionKey,
  editOptionLabel,
  newOption,
  optionLimits,
  type OptionDraft,
  type OptionErrors,
} from './sheetDraft'

/**
 * The options of a conditions field (ADR 0041): rows of a key and a label, in the order a
 * Character form lists them. A key follows its label until it is typed by hand. Removing or
 * renaming an option an effect marks is refused by the server when the field is saved.
 */
export function ConditionOptionsEditor({
  options,
  onChange,
  error,
  errors,
}: {
  options: OptionDraft[]
  onChange: (options: OptionDraft[]) => void
  /** The message about the options as a list. */
  error?: string
  /** The messages of each option's inputs, by position. */
  errors?: Partial<Record<number, OptionErrors>>
}) {
  const keyHintId = useId()
  const edit = (index: number, next: (option: OptionDraft) => OptionDraft) => {
    onChange(options.map((option, at) => (at === index ? next(option) : option)))
  }
  const move = (index: number, to: number) => {
    const next = [...options]
    const [option] = next.splice(index, 1)
    if (option !== undefined) {
      next.splice(to, 0, option)
    }
    onChange(next)
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <h4 className="mr-auto text-sm font-medium">Options</h4>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={options.length >= optionLimits.count}
          onClick={() => {
            onChange([...options, newOption()])
          }}
        >
          <Plus aria-hidden="true" />
          Add option
        </Button>
      </div>
      <ol className="space-y-2">
        {options.map((option, index) => {
          const name = `Option ${String(index + 1)}`
          const found = errors?.[index]
          return (
            // The options have no identity of their own, so the position is the key.
            <li key={index} className="well-row">
              <div className="flex flex-wrap items-start gap-2">
                <span aria-hidden="true" className="mt-1.5 count-pill">
                  {index + 1}
                </span>
                <OptionInput
                  label={`${name} key`}
                  placeholder="Key"
                  className="w-44 flex-[2]"
                  mono
                  describedBy={keyHintId}
                  value={option.key}
                  onChange={(key) => {
                    edit(index, (held) => editOptionKey(held, key))
                  }}
                  error={found?.key}
                />
                <OptionInput
                  label={`${name} label`}
                  placeholder="Label"
                  className="w-48 flex-[3]"
                  value={option.label}
                  onChange={(label) => {
                    edit(index, (held) => editOptionLabel(held, label))
                  }}
                  error={found?.label}
                />
                <div className="ml-auto flex">
                  <IconButton
                    label={`Move up ${name.toLowerCase()}`}
                    dense
                    disabled={index === 0}
                    onClick={() => {
                      move(index, index - 1)
                    }}
                  >
                    <ArrowUp />
                  </IconButton>
                  <IconButton
                    label={`Move down ${name.toLowerCase()}`}
                    dense
                    disabled={index === options.length - 1}
                    onClick={() => {
                      move(index, index + 1)
                    }}
                  >
                    <ArrowDown />
                  </IconButton>
                  <IconButton
                    label={`Remove ${name.toLowerCase()}`}
                    dense
                    destructive
                    disabled={options.length <= 1}
                    onClick={() => {
                      onChange(options.filter((_, at) => at !== index))
                    }}
                  >
                    <X />
                  </IconButton>
                </div>
              </div>
            </li>
          )
        })}
      </ol>
      <KeyHint id={keyHintId}>Every option key follows this rule.</KeyHint>
      <p className="hint">
        A Character marks any of these; a formula reads how many are marked. 1 to{' '}
        {optionLimits.count} options.
      </p>
      {error && <FieldError>{error}</FieldError>}
    </div>
  )
}

/** A compact text input of an option row: its label is for assistive technology only. */
function OptionInput({
  label,
  placeholder,
  value,
  onChange,
  error,
  describedBy,
  mono = false,
  className,
}: {
  label: string
  placeholder: string
  value: string
  onChange: (value: string) => void
  error?: string
  describedBy?: string
  mono?: boolean
  className?: string
}) {
  const id = useId()
  const errorId = `${id}-error`
  const described = [describedBy, error ? errorId : undefined].filter(Boolean).join(' ')

  return (
    <div className={cn('min-w-0 space-y-1', className)}>
      <Label htmlFor={id} className="sr-only">
        {label}
      </Label>
      <Input
        id={id}
        className={mono ? 'font-mono' : undefined}
        placeholder={placeholder}
        value={value}
        autoComplete="off"
        aria-invalid={error !== undefined}
        aria-describedby={described === '' ? undefined : described}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {error && <FieldError id={errorId}>{error}</FieldError>}
    </div>
  )
}
