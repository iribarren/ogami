import { Button } from '@/shared/ui/button'
import { NativeSelect } from '@/shared/ui/native-select'

import { moveItem } from '../steps/draftParts'
import { PickField, SelectField } from '../steps/fields'
import {
  MAX_SELECTION_SCENE_TYPES,
  selectionRules,
  withRule,
  type SelectionDraft,
} from './flowDraft'

interface Option {
  key: string
  name: string
}

const ruleNames: Option[] = [
  { key: 'sequence', name: 'Plays in order' },
  { key: 'player', name: 'The player picks' },
  { key: 'oracle', name: 'An oracle table picks' },
]

/**
 * How a phase picks its scenes: a rule, then an ordered list of Scene Types (`sequence`, `player`)
 * or an oracle table (`oracle`). `messages` are what the checks and the server said about it.
 */
export function SelectionEditor({
  selection,
  sceneTypes,
  tables,
  messages,
  onChange,
}: {
  selection: SelectionDraft
  sceneTypes: Option[]
  tables: Option[]
  messages: string[]
  onChange: (selection: SelectionDraft) => void
}) {
  const nameOf = (key: string) => sceneTypes.find((option) => option.key === key)?.name ?? key

  return (
    <fieldset className="space-y-3">
      <legend className="text-sm font-medium">Selection</legend>
      <SelectField
        label="Rule"
        value={selection.rule}
        options={ruleNames}
        onChange={(rule) => {
          const picked = selectionRules.find((option) => option === rule)
          if (picked !== undefined) {
            onChange(withRule(selection, picked))
          }
        }}
      />
      {selection.rule === 'oracle' ? (
        <PickField
          label="Oracle table"
          value={selection.table}
          options={tables}
          placeholder="Pick a table"
          onChange={(table) => {
            onChange({ ...selection, table })
          }}
        />
      ) : (
        <SceneTypeList
          selection={selection}
          sceneTypes={sceneTypes}
          nameOf={nameOf}
          onChange={onChange}
        />
      )}
      {messages.map((message) => (
        <p key={message} className="text-sm text-destructive">
          {message}
        </p>
      ))}
    </fieldset>
  )
}

function SceneTypeList({
  selection,
  sceneTypes,
  nameOf,
  onChange,
}: {
  selection: Extract<SelectionDraft, { rule: 'sequence' | 'player' }>
  sceneTypes: Option[]
  nameOf: (key: string) => string
  onChange: (selection: SelectionDraft) => void
}) {
  const list = selection.sceneTypes
  // A player list names each Scene Type once, so it offers only the ones not listed yet.
  const offered = sceneTypes.filter(
    (option) => selection.rule === 'sequence' || !list.includes(option.key),
  )
  const full = list.length >= MAX_SELECTION_SCENE_TYPES
  const set = (next: string[]) => {
    onChange({ ...selection, sceneTypes: next })
  }

  return (
    <div className="space-y-2">
      {list.length === 0 ? (
        <p className="text-sm text-muted-foreground">No Scene Types yet.</p>
      ) : (
        <ol className="space-y-1">
          {list.map((key, index) => (
            // A sequence may repeat a Scene Type, so the position is the identity.
            <li key={index} className="flex flex-wrap items-center gap-2 text-sm">
              <span>
                {String(index + 1)}. {nameOf(key)}
              </span>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={index === 0}
                aria-label={`Move Scene Type ${String(index + 1)} up`}
                onClick={() => {
                  set(moveItem(list, index, -1))
                }}
              >
                Up
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={index === list.length - 1}
                aria-label={`Move Scene Type ${String(index + 1)} down`}
                onClick={() => {
                  set(moveItem(list, index, 1))
                }}
              >
                Down
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label={`Remove Scene Type ${String(index + 1)}`}
                onClick={() => {
                  set(list.filter((_, at) => at !== index))
                }}
              >
                Remove
              </Button>
            </li>
          ))}
        </ol>
      )}
      <NativeSelect
        aria-label="Add a Scene Type"
        value=""
        disabled={full || offered.length === 0}
        onChange={(event) => {
          if (event.target.value !== '') {
            set([...list, event.target.value])
          }
        }}
      >
        <option value="">
          {full
            ? `At most ${String(MAX_SELECTION_SCENE_TYPES)} Scene Types`
            : offered.length === 0
              ? 'No Scene Type left to add'
              : 'Pick a Scene Type'}
        </option>
        {offered.map((option) => (
          <option key={option.key} value={option.key}>
            {option.name}
          </option>
        ))}
      </NativeSelect>
    </div>
  )
}
