import type { GameSystemValidation } from '../../useGameSystems'

/**
 * How many of the validation's messages are about the flow at `index`: the first error, if any,
 * plus the warnings, whose path (the text before the first `: `) is `flows[index]` or goes into it
 * (`flows[0].phases[1]…`, `flows[0].oracles[2]`). The index is the flow's stored position, which
 * is the order of the narrative read model too.
 */
export function flowIssueCount(validation: GameSystemValidation, index: number): number {
  const base = `flows[${String(index)}]`
  const about = (message: string) =>
    [`${base}:`, `${base}.`, `${base}[`].some((start) => message.startsWith(start))
  const messages = [...(validation.error ? [validation.error.message] : []), ...validation.warnings]
  return messages.filter(about).length
}
