import type { EditedEffect } from '../steps/effectDraft'
import { scenePartNames, type ScenePart } from './sceneTypeDraft'

// The search params the flow Map hands to the Scene Type page (D31, D32): which step to show, and
// the transition to add to one of its parts. Like `nodeParam`, each parser returns an explicit
// undefined for anything it does not know, since the router merges the validated search over the raw
// one and an omitted key would keep the raw value.

/** A step of a Scene Type: its list and its place in it (0-based). */
export interface StepRef {
  part: ScenePart
  index: number
}

/** The transition a page is asked to add: `nextScene` and `switchSceneType` name a Scene Type. */
export type PendingEffect =
  { kind: 'nextScene' | 'switchSceneType'; sceneType: string } | { kind: 'endPhase' }

/** What the page is asked to add, and where. */
export interface PendingTransition {
  step: StepRef
  carrier: string
  effect: PendingEffect
}

/** `<part>.<index>`: `play.1` is the second step of Play. Null for anything else. */
export function parseStep(value: unknown): StepRef | null {
  if (typeof value !== 'string') {
    return null
  }
  const [part, index, ...rest] = value.split('.')
  if (rest.length > 0 || !/^(0|[1-9]\d{0,3})$/.test(index ?? '')) {
    return null
  }
  const found = scenePartNames.find((name) => name === part)
  return found === undefined ? null : { part: found, index: Number(index) }
}

/** The `step` search param: the value when it names a step place, else undefined. */
export const stepParam = (value: unknown): string | undefined =>
  typeof value === 'string' && parseStep(value) !== null ? value : undefined

// A carrier id is `step`, `otherwise`, or a kind and a position or answer (`band:0`, `oracle:yes`).
// Whether the step has that part is for the page to say when it applies it.
const CARRIER = /^(step|otherwise|(band|option|branch|check):\d{1,3}|(oracle|pick):[A-Za-z]{1,20})$/

/** The `carrier` search param: the value when it is shaped like a carrier id, else undefined. */
export const carrierParam = (value: unknown): string | undefined =>
  typeof value === 'string' && CARRIER.test(value) ? value : undefined

/** `endPhase`, `nextScene:<key>` or `switchSceneType:<key>`. Null for anything else. */
export function parseEffect(value: unknown): PendingEffect | null {
  if (value === 'endPhase') {
    return { kind: 'endPhase' }
  }
  if (typeof value !== 'string') {
    return null
  }
  const [kind, ...key] = value.split(':')
  const sceneType = key.join(':')
  if ((kind !== 'nextScene' && kind !== 'switchSceneType') || !/^\S{1,200}$/.test(sceneType)) {
    return null
  }
  return { kind, sceneType }
}

/** The `effect` search param: the value when it says an effect, else undefined. */
export const effectParam = (value: unknown): string | undefined =>
  typeof value === 'string' && parseEffect(value) !== null ? value : undefined

/** The search the Map sends for a transition (the other way round of `parseEffect`). */
export function transitionSearch(
  { part, index }: StepRef,
  carrier: string,
  effect: EditedEffect,
): { step: string; carrier: string; effect: string } {
  return {
    step: `${part}.${String(index)}`,
    carrier,
    effect:
      effect.kind === 'nextScene' || effect.kind === 'switchSceneType'
        ? `${effect.kind}:${effect.sceneType}`
        : 'endPhase',
  }
}

/**
 * The transition the search asks for, `null` when it asks for none, or why it cannot be read: a
 * half of one (a `carrier` or an `effect` with no step, or a step with only one of them) is refused
 * rather than guessed at. A value that is not valid reads as absent: the route has already dropped it.
 */
export function pendingOf(search: {
  step?: string | undefined
  carrier?: string | undefined
  effect?: string | undefined
}): PendingTransition | null | { refused: string } {
  const step = parseStep(search.step)
  const effect = parseEffect(search.effect)
  if (search.carrier === undefined && effect === null) {
    return null
  }
  if (step === null || search.carrier === undefined || effect === null) {
    return {
      refused:
        'The transition to add is incomplete or malformed, so nothing was added. Draw the arrow on the flow Map again.',
    }
  }
  return { step, carrier: search.carrier, effect }
}
