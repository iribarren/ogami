import { useId } from 'react'

import { FieldError } from '@/shared/ui/alert'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import { TextField } from '../TextField'
import { Area, CheckList } from '../steps/fields'
import type { StepContext } from '../steps/stepContext'
import { EnableOnFlow } from './EnableOnFlow'
import { flowViews, type FlowDraft } from './flowDraft'
import type { Missing } from './flowEnable'

/**
 * The fields of the flow itself: key, name, texts, default view and flag, the oracle shortcuts and
 * the trackers it shows. `errors` are the messages by field name.
 */
export function FlowSettings({
  draft,
  context,
  errors,
  missing,
  onEnable,
  onChange,
}: {
  draft: FlowDraft
  context: StepContext
  errors: Record<string, string>
  /** What the flow's steps and selections use that it does not enable. */
  missing: Missing
  onEnable: () => void
  onChange: (draft: FlowDraft) => void
}) {
  const viewId = useId()
  const options = [...context.tables, ...context.likelihood]

  return (
    <div className="space-y-6">
      <EnableOnFlow
        missing={missing}
        context={context}
        intro="The steps and Scene Types of this flow use what is not enabled on it:"
        onEnable={onEnable}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          label="Key"
          keyField
          value={draft.key}
          error={errors.key}
          onChange={(key) => {
            onChange({ ...draft, key })
          }}
        />
        <TextField
          label="Name"
          value={draft.name}
          error={errors.name}
          onChange={(name) => {
            onChange({ ...draft, name })
          }}
        />
      </div>
      <Area
        label="Description"
        value={draft.description}
        error={errors.description}
        onChange={(description) => {
          onChange({ ...draft, description })
        }}
      />
      <Area
        label="Introduction"
        value={draft.introduction}
        error={errors.introduction}
        onChange={(introduction) => {
          onChange({ ...draft, introduction })
        }}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor={viewId}>Default view</Label>
          <Select
            id={viewId}
            value={draft.defaultView}
            aria-invalid={errors.defaultView !== undefined}
            aria-describedby={errors.defaultView ? `${viewId}-error` : undefined}
            onChange={(event) => {
              onChange({
                ...draft,
                defaultView: flowViews.find((view) => view === event.target.value) ?? 'focus',
              })
            }}
          >
            <option value="focus">Focus</option>
            <option value="journal">Journal</option>
          </Select>
          {errors.defaultView && (
            <FieldError id={`${viewId}-error`}>{errors.defaultView}</FieldError>
          )}
        </div>
        <div className="space-y-1">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={draft.default}
              aria-invalid={errors.default !== undefined}
              aria-describedby={errors.default ? `${viewId}-default-error` : undefined}
              onChange={(event) => {
                onChange({ ...draft, default: event.target.checked })
              }}
            />
            Default flow
          </label>
          <p className="text-sm text-muted-foreground">
            Saving it as the default clears the flag on the other flows.
          </p>
          {errors.default && (
            <FieldError id={`${viewId}-default-error`}>{errors.default}</FieldError>
          )}
        </div>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1">
          <CheckList
            legend="Oracle shortcuts"
            options={options}
            value={draft.oracles}
            error={errors.oracles}
            onChange={(oracles) => {
              onChange({ ...draft, oracles })
            }}
          />
        </div>
        <div className="space-y-1">
          <CheckList
            legend="Trackers"
            options={context.trackers}
            value={draft.trackers}
            error={errors.trackers}
            onChange={(trackers) => {
              onChange({ ...draft, trackers })
            }}
          />
        </div>
      </div>
    </div>
  )
}
