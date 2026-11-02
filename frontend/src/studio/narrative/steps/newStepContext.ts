import { createContext } from 'react'

import type { StepKind } from './stepDraft'

/**
 * What a Next select needs to create the step it points at. `create` adds a step of `kind` to the
 * end of the list (where only later steps are offered) and returns its key; the very next change of
 * a step in the same event carries it, so the new step and the link to it are one edit. Null where
 * the list cannot add a step.
 */
export const NewStepContext = createContext<{
  create: (kind: StepKind) => string
  /** Whether the list is a Scene Type's setup, which cannot hold every kind. */
  setup: boolean
} | null>(null)
