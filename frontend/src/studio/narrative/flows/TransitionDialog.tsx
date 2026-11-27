import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/dialog'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import type { EditedEffect } from '../steps/effectDraft'
import type { StepDraft } from '../steps/stepDraft'
import type { ArrowTo } from './flowMapArrows'
import { CarrierPicker } from './CarrierPicker'
import { carriersOf, sceneEffect } from './flowMapCarriers'

/** A place a transition can go, for the keyboard path that has no arrow to say it. */
export interface Destination {
  value: string
  label: string
  to: ArrowTo
}

/** The first step that has a part to carry an effect; 0 when none does (the caller refuses that first). */
const firstEditable = (steps: StepDraft[]) =>
  Math.max(
    0,
    steps.findIndex((step) => carriersOf(step).length > 0),
  )

/**
 * Where a transition goes in a hook or a Scene Type (D24, D30): the step and part that carry it, the kind for a Scene
 * Type target, and, with no arrow to say it, the destination. It is mounted per request, so every
 * choice starts afresh. `onConfirm` returns why the edit was refused, which the dialog shows and
 * stays open for; null closes it.
 */
export function TransitionDialog({
  source,
  steps,
  stepLabels,
  to,
  destinations,
  names,
  onConfirm,
  onCancel,
}: {
  /** Where the transition starts, as the dialog names it: `hook "Phase opening" of phase "Setup"`, `Scene Type "Ambush"`. */
  source: string
  /** A hook's steps, or a Scene Type's steps of all three lists in page order. */
  steps: StepDraft[]
  /** What each step is called when its place alone does not say (a Scene Type's lists): `Play · 2 "Ask"`. */
  stepLabels?: string[]
  /** Where the arrow went; null asks for it (the keyboard path). */
  to: ArrowTo | null
  destinations: Destination[]
  names: (sceneType: string) => string
  onConfirm: (step: number, carrier: string, effect: EditedEffect) => string | null
  onCancel: () => void
}) {
  const [step, setStep] = useState(firstEditable(steps))
  const [part, setPart] = useState('step')
  const [kind, setKind] = useState<'nextScene' | 'switchSceneType'>('nextScene')
  const [destination, setDestination] = useState(destinations[0]?.value ?? '')
  const [refusal, setRefusal] = useState<string | null>(null)

  const target = to ?? destinations.find(({ value }) => value === destination)?.to ?? null
  const reset = () => {
    setRefusal(null)
  }

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open) {
          onCancel()
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add a transition</DialogTitle>
          <DialogDescription>Choose where in {source} the transition is written.</DialogDescription>
        </DialogHeader>
        {to === null && (
          <div className="grid gap-1.5">
            <Label htmlFor="transition-to">To</Label>
            <Select
              id="transition-to"
              value={destination}
              onChange={(event) => {
                setDestination(event.target.value)
                reset()
              }}
            >
              {destinations.map(({ value, label }) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </div>
        )}
        <CarrierPicker
          idPrefix="transition"
          steps={steps}
          labels={stepLabels}
          step={step}
          part={part}
          onStep={(next) => {
            setStep(next)
            setPart('step')
            reset()
          }}
          onPart={(next) => {
            setPart(next)
            reset()
          }}
        />
        {target?.type === 'sceneType' && (
          <div className="grid gap-1.5">
            <Label htmlFor="transition-kind">
              {`Kind of transition to "${names(target.sceneType)}"`}
            </Label>
            <Select
              id="transition-kind"
              value={kind}
              onChange={(event) => {
                setKind(event.target.value === 'switchSceneType' ? 'switchSceneType' : 'nextScene')
                reset()
              }}
            >
              <option value="nextScene">Next scene</option>
              <option value="switchSceneType">Switch Scene Type</option>
            </Select>
          </div>
        )}
        {refusal !== null && (
          <p role="alert" className="text-sm text-destructive">
            {refusal}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button
            type="button"
            disabled={target === null}
            onClick={() => {
              if (target === null) {
                return
              }
              const effect: EditedEffect =
                target.type === 'endPhase'
                  ? { kind: 'endPhase', extra: {} }
                  : sceneEffect(kind, target.sceneType)
              setRefusal(onConfirm(step, part, effect))
            }}
          >
            Add transition
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
