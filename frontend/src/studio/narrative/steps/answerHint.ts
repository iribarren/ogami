import { createContext } from 'react'

/** The hint under the text fields where `{answer}` is worth knowing about (S11). */
export const answerHint =
  'Effects can use {answer}: the option the player picks, or what they type in a prompt step.'

/** Whether the step being edited has an answer (a prompt or a choice step): its fields and effects show the hint. */
export const AnswerHint = createContext(false)
