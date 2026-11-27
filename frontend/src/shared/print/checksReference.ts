import type { Release, ReleaseLikelihoodOracle } from './release'

export interface CheckReference {
  key: string
  label: string
  hint: string
  /** The dice as the release writes them; a check without dice (a pool) has none. */
  dice?: string
  /** The labels of its bands, in order. */
  bands: string[]
}

export interface LikelihoodReference {
  key: string
  name: string
  /** Sides of the die the question is rolled on. */
  die: number
  levels: { label: string; answer: string }[]
  exceptional?: string
}

export interface ChecksReference {
  checks: CheckReference[]
  oracles: LikelihoodReference[]
}

/**
 * The Randomness rule: the question rolls 1d<sides> and the answer is yes when the roll is at or
 * under the level's target (at the neutral chaos factor), no above it.
 */
function answerText(target: number, sides: number): string {
  if (target <= 0) {
    return 'Always no'
  }
  return target >= sides ? 'Always yes' : `Yes on ${String(target)} or less`
}

function likelihoodReference(oracle: ReleaseLikelihoodOracle): LikelihoodReference {
  const percent = oracle.exceptionalPercent
  return {
    key: oracle.key,
    name: oracle.name,
    die: oracle.sides,
    levels: oracle.levels.map((level) => ({
      label: level.label,
      answer: answerText(level.target, oracle.sides),
    })),
    exceptional:
      percent === undefined || percent === 0
        ? undefined
        : `An exceptional answer on the most extreme ${String(percent)}% of the yes rolls and of the no rolls.`,
  }
}

/**
 * The checks of a release (in release order) and the likelihood oracles of a flow (in flow order),
 * as a reference to keep beside the table. Both lists may be empty, and then there is nothing to
 * print. A flow oracle that is a table, or neither a table nor a likelihood oracle, is not this
 * sheet's: the tables sheet says what is wrong with it. Throws for a flow the release lacks.
 */
export function buildChecksReference(release: Release, flowKey: string): ChecksReference {
  const flow = release.flows.find((candidate) => candidate.key === flowKey)
  if (flow === undefined) {
    throw new Error(`The release has no flow "${flowKey}".`)
  }
  return {
    checks: release.checks.map((check) => ({
      key: check.key,
      label: check.label,
      hint: check.hint,
      dice: check.dice,
      bands: check.bands.map((band) => band.label),
    })),
    oracles: flow.oracles.flatMap((key) => {
      const oracle = release.likelihoodOracles.find((candidate) => candidate.key === key)
      return oracle === undefined ? [] : [likelihoodReference(oracle)]
    }),
  }
}
