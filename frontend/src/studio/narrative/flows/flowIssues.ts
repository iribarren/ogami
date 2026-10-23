import type { GameSystemValidation } from '../../useGameSystems'

/** Whether a path is the flow at `index` or goes into it (`flows[0].phases[1]…`, `flows[0].oracles[2]`). */
const isAbout = (path: string, index: number) => {
  const base = `flows[${String(index)}]`
  return path === base || path.startsWith(`${base}.`) || path.startsWith(`${base}[`)
}

/**
 * How many of the validation's errors and warnings are about the flow at `index`. An error says
 * where it is in its `path`; a warning starts with its path, then `: `. The index is the flow's
 * stored position, which is the order of the narrative read model too.
 */
export function flowIssueCount(validation: GameSystemValidation, index: number): number {
  const paths = [
    ...validation.errors.map((error) => error.path),
    ...validation.warnings.map((warning) => warning.split(': ')[0] ?? warning),
  ]
  return paths.filter((path) => isAbout(path, index)).length
}
