import { isPaper, PAPERS, type Paper } from '@/shared/print/layout'
import type { Release } from '@/shared/print/release'

/**
 * The print options as the URL gives them: text, or absent. Whether a value means anything is
 * decided against the release by `resolvePrintOptions`; reading the URL never defaults a value
 * that is present.
 */
export interface PrintSearch {
  flow?: string | undefined
  paper?: string | undefined
  bleed?: string | undefined
  decks?: string[] | undefined
}

const text = (value: unknown): string | undefined => {
  if (value === undefined || value === null) {
    return undefined
  }
  if (typeof value === 'string') {
    return value
  }
  return typeof value === 'number' || typeof value === 'boolean'
    ? String(value)
    : JSON.stringify(value)
}

/** The router parses the query string as JSON, so `bleed=true` is a boolean and `flow=1` a number. */
export function parsePrintSearch(search: Record<string, unknown>): PrintSearch {
  const decks = search.decks
  return {
    flow: text(search.flow),
    paper: text(search.paper),
    bleed: text(search.bleed),
    decks:
      decks === undefined || decks === null
        ? undefined
        : (Array.isArray(decks) ? (decks as unknown[]) : [decks]).map((deck) => text(deck) ?? ''),
  }
}

export interface PrintOptions {
  flow: string
  paper: Paper
  bleed: boolean
  /** Oracle tables to print as decks besides the flow's own, once each. */
  decks: string[]
}

export type ResolvedOptions =
  | { ok: true; options: PrintOptions }
  | { ok: false; problems: string[]; flows: { key: string; name: string }[] }

/** Checks the options against the release: a missing flow, or a value it does not know, is a problem. */
export function resolvePrintOptions(search: PrintSearch, release: Release): ResolvedOptions {
  const problems: string[] = []
  const flows = release.flows.map(({ key, name }) => ({ key, name }))
  const flow = search.flow === '' ? undefined : search.flow
  const flowKnown = flow !== undefined && flows.some((candidate) => candidate.key === flow)
  if (flow === undefined) {
    problems.push('Choose a flow to print: add flow=<key> to the address.')
  } else if (!flowKnown) {
    problems.push(`The release has no flow "${flow}".`)
  }
  const paper = search.paper ?? 'a4'
  if (!isPaper(paper)) {
    problems.push(`Unknown paper "${paper}": use ${PAPERS.join(' or ')}.`)
  }
  const bleed = search.bleed ?? 'false'
  if (bleed !== 'true' && bleed !== 'false') {
    problems.push(`Unknown bleed value "${bleed}": use true or false.`)
  }
  const decks = [...new Set(search.decks ?? [])]
  for (const deck of decks) {
    if (!release.tables.some((table) => table.key === deck)) {
      problems.push(
        `The release has no oracle table "${deck}" to print as a deck. Its tables: ${release.tables.map((table) => table.key).join(', ') || 'none'}.`,
      )
    }
  }
  if (problems.length > 0 || flow === undefined || !isPaper(paper)) {
    // The flows are listed only when the flow is what to choose.
    return { ok: false, problems, flows: flowKnown ? [] : flows }
  }
  return { ok: true, options: { flow, paper, bleed: bleed === 'true', decks } }
}
