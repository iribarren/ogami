import type { JournalEntry, LikelihoodContent, RollContent } from '../journal/useJournal'

// Pure, framework-free step-through of a mock NarrativeFlow, shared by the flow
// presentation prototypes (Storybook only). Results are scripted, never rolled.

export type StepId = string

interface BaseStep {
  id: StepId
  title: string
}

/** Asks the player to write text (e.g. set the scene); recorded as a note. */
export interface PromptStep extends BaseStep {
  kind: 'prompt'
  text: string
  placeholder: string
  next: StepId | null
}

/** Asks a likelihood oracle; branches on its scripted answer. */
export interface OracleStep extends BaseStep {
  kind: 'oracle'
  question: string
  oracleName: string
  likelihood: string
  likelihoodLabel: string
  scripted: Pick<LikelihoodContent, 'answer' | 'roll' | 'sides' | 'effectiveTarget' | 'chaosFactor'>
  /** A yes (exceptional or not) follows `yes`, a no follows `no`. */
  next: { yes: StepId | null; no: StepId | null }
}

/** Rolls a dice expression; its result is scripted. */
export interface RollStep extends BaseStep {
  kind: 'roll'
  text: string
  expression: string
  scripted: Pick<RollContent, 'total' | 'groups'>
  next: StepId | null
}

/** The player picks one option; each option leads to its own next step. */
export interface ChoiceStep extends BaseStep {
  kind: 'choice'
  text: string
  options: { id: string; label: string; next: StepId | null }[]
}

/** Asks the player to write a journal entry; recorded as a note. */
export interface JournalStep extends BaseStep {
  kind: 'journal'
  text: string
  placeholder: string
  next: StepId | null
}

export type FlowStep = PromptStep | OracleStep | RollStep | ChoiceStep | JournalStep

/** A scene of a mock NarrativeFlow: its steps by id and where it starts. */
export interface FlowSession {
  sessionNumber: number
  sceneNumber: number
  sceneTitle: string
  startStepId: StepId
  steps: Record<StepId, FlowStep>
}

/** What the player does to answer the current step. */
export type FlowInput =
  | { kind: 'text'; text: string }
  | { kind: 'ask' }
  | { kind: 'roll' }
  | { kind: 'choose'; optionId: string }

/** One answered step: what it was, a one-line summary of its result and the entry it recorded. */
export interface StepResult {
  stepId: StepId
  title: string
  summary: string
  entryId: string
}

/** The live progress of a mock FlowRun: current step, answered steps and the scene's journal. */
export interface FlowRunState {
  session: FlowSession
  /** `null` once the scene is finished. */
  currentStepId: StepId | null
  history: StepResult[]
  /** Every entry recorded in the scene, step results and free notes, in recorded order. */
  entries: JournalEntry[]
}

/** A possible next step after the current one; `step` is `null` when the scene ends there. */
export interface UpcomingStep {
  /** Why this branch is taken (e.g. "Yes", an option label); absent for a single next step. */
  when?: string
  step: FlowStep | null
}

/** The time the first entry of a run is recorded at; each later entry is a minute later. */
const RUN_STARTED_AT = Date.parse('2026-10-07T18:00:00+00:00')

const answerLabels: Record<LikelihoodContent['answer'], string> = {
  exceptional_yes: 'Exceptional yes',
  yes: 'Yes',
  no: 'No',
  exceptional_no: 'Exceptional no',
}

export function startRun(session: FlowSession): FlowRunState {
  return { session, currentStepId: session.startStepId, history: [], entries: [] }
}

/** The step the player answers now; `null` once the scene is finished. */
export function currentStep(state: FlowRunState): FlowStep | null {
  return state.currentStepId === null ? null : stepById(state.session, state.currentStepId)
}

/** Answers the current step: records its result in the journal and moves to the next step. */
export function advance(state: FlowRunState, input: FlowInput): FlowRunState {
  const step = currentStep(state)
  if (step === null) {
    throw new Error('The scene is finished.')
  }
  const { content, summary, next } = resolve(step, input)
  const recorded = record(state, content)
  const entryId = recorded.entries.at(-1)?.id ?? ''
  return {
    ...recorded,
    currentStepId: next,
    history: [...state.history, { stepId: step.id, title: step.title, summary, entryId }],
  }
}

/** Writes a free note in the scene, between steps, without moving the flow. */
export function addNote(state: FlowRunState, text: string): FlowRunState {
  return record(state, { kind: 'note', text: requireText(text) })
}

/** Every step that can follow the current one, one per branch; empty once the scene is finished. */
export function upcomingSteps(state: FlowRunState): UpcomingStep[] {
  const step = currentStep(state)
  if (step === null) {
    return []
  }
  return branches(step).map(({ when, next }) => ({
    ...(when === undefined ? {} : { when }),
    step: next === null ? null : stepById(state.session, next),
  }))
}

/**
 * The number of the current step and the shortest and longest scene over the branches
 * still open, counting the steps already answered.
 */
export function progress(state: FlowRunState): { number: number; min: number; max: number } {
  const answered = state.history.length
  if (state.currentStepId === null) {
    return { number: answered, min: answered, max: answered }
  }
  const { min, max } = remaining(state.session, state.currentStepId)
  return { number: answered + 1, min: answered + min, max: answered + max }
}

/** Plays `inputs` from the start: a run at any point of the session, e.g. for a story. */
export function replay(session: FlowSession, inputs: FlowInput[]): FlowRunState {
  return inputs.reduce(advance, startRun(session))
}

function stepById(session: FlowSession, id: StepId): FlowStep {
  const step = session.steps[id]
  if (step === undefined) {
    throw new Error(`Unknown step "${id}".`)
  }
  return step
}

/** The steps that can follow `step`, labelled when there is more than one. */
function branches(step: FlowStep): { when?: string; next: StepId | null }[] {
  switch (step.kind) {
    case 'oracle':
      return [
        { when: 'Yes', next: step.next.yes },
        { when: 'No', next: step.next.no },
      ]
    case 'choice':
      return step.options.map(({ label, next }) => ({ when: label, next }))
    default:
      return [{ next: step.next }]
  }
}

/** The fewest and most steps from `id` (included) to the end of the scene. */
function remaining(session: FlowSession, id: StepId): { min: number; max: number } {
  const ahead = branches(stepById(session, id)).map(({ next }) =>
    next === null ? { min: 0, max: 0 } : remaining(session, next),
  )
  return {
    min: 1 + Math.min(...ahead.map(({ min }) => min)),
    max: 1 + Math.max(...ahead.map(({ max }) => max)),
  }
}

/** The entry an answer records, its one-line summary and the step it leads to. */
function resolve(
  step: FlowStep,
  input: FlowInput,
): { content: JournalEntry['content']; summary: string; next: StepId | null } {
  if ((step.kind === 'prompt' || step.kind === 'journal') && input.kind === 'text') {
    const text = requireText(input.text)
    return { content: { kind: 'note', text }, summary: text, next: step.next }
  }
  if (step.kind === 'oracle' && input.kind === 'ask') {
    const { answer } = step.scripted
    return {
      content: {
        kind: 'likelihood',
        oracleKey: step.id,
        oracleName: step.oracleName,
        question: step.question,
        likelihood: step.likelihood,
        likelihoodLabel: step.likelihoodLabel,
        ...step.scripted,
      },
      summary: `${step.question} ${answerLabels[answer]}`,
      next: answer === 'yes' || answer === 'exceptional_yes' ? step.next.yes : step.next.no,
    }
  }
  if (step.kind === 'roll' && input.kind === 'roll') {
    const { total } = step.scripted
    return {
      content: { kind: 'roll', expression: step.expression, ...step.scripted },
      summary: `${step.expression} = ${String(total)}`,
      next: step.next,
    }
  }
  if (step.kind === 'choice' && input.kind === 'choose') {
    const option = step.options.find(({ id }) => id === input.optionId)
    if (option === undefined) {
      throw new Error(`"${input.optionId}" is not an option of "${step.id}".`)
    }
    return {
      content: { kind: 'note', text: `Chose: ${option.label}` },
      summary: option.label,
      next: option.next,
    }
  }
  throw new Error(`A "${input.kind}" input does not answer the ${step.kind} step "${step.id}".`)
}

function requireText(text: string): string {
  const trimmed = text.trim()
  if (trimmed === '') {
    throw new Error('The text is blank.')
  }
  return trimmed
}

/** Appends an entry to the scene's journal, with a deterministic id and time. */
function record(state: FlowRunState, content: JournalEntry['content']): FlowRunState {
  const number = state.entries.length + 1
  const entry: JournalEntry = {
    id: `flow-entry-${String(number)}`,
    sessionNumber: state.session.sessionNumber,
    sceneNumber: state.session.sceneNumber,
    recordedAt: new Date(RUN_STARTED_AT + (number - 1) * 60_000).toISOString(),
    kind: content.kind,
    content,
    flowStep: null,
  }
  return { ...state, entries: [...state.entries, entry] }
}
