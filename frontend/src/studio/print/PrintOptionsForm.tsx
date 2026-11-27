import { Button } from '@/shared/ui/button'
import { NativeSelect } from '@/shared/ui/native-select'
import { isPaper, PAPERS } from '@/shared/print/layout'
import type { Release } from '@/shared/print/release'

import type { PrintSearch } from './printOptions'

/** The oracle tables of a flow that a card deck can be made of: tables, not likelihood oracles. */
function deckCandidates(release: Release, flowKey: unknown) {
  const flow = release.flows.find((candidate) => candidate.key === flowKey)
  if (flow === undefined) {
    return { own: [], extra: [] }
  }
  const find = (key: string) => release.tables.filter((table) => table.key === key)
  const own = [...new Set(flow.selectionTables)]
  return {
    own: own.flatMap(find),
    extra: flow.oracles.filter((key) => !own.includes(key)).flatMap(find),
  }
}

/**
 * The options as the controls show them: only what the resolver accepts, so a change never writes
 * a value it would refuse. Whatever else the address holds is left to the page's messages until
 * a control is changed.
 */
function shown(search: PrintSearch, release: Release) {
  const flow = typeof search.flow === 'string' ? search.flow : ''
  const paper = search.paper !== undefined && isPaper(search.paper) ? search.paper : 'a4'
  const extra = deckCandidates(release, flow).extra.map((table) => table.key)
  const decks = (search.decks ?? []).filter(
    (deck): deck is string => typeof deck === 'string' && extra.includes(deck),
  )
  return { flow, paper, bleed: search.bleed === 'true', decks }
}

/** The address for the options: the defaults (A4, no bleed, no extra decks) are left out. */
function toSearch(options: ReturnType<typeof shown>): PrintSearch {
  return {
    flow: options.flow === '' ? undefined : options.flow,
    paper: options.paper === 'a4' ? undefined : options.paper,
    bleed: options.bleed ? 'true' : undefined,
    decks: options.decks.length > 0 ? options.decks : undefined,
  }
}

/**
 * The print options as controls, for the screen only. A change replaces the options of the
 * address through `onChange`, so the address stays the one source of the options.
 */
export function PrintOptionsForm({
  release,
  search,
  canPrint,
  onChange,
  onPrint,
}: {
  release: Release
  search: PrintSearch
  canPrint: boolean
  onChange: (search: PrintSearch) => void
  onPrint: () => void
}) {
  const options = shown(search, release)
  const { own, extra } = deckCandidates(release, options.flow)
  const change = (changes: Partial<typeof options>) => {
    const next = { ...options, ...changes }
    // Decks belong to the flow: the ones the new flow does not offer drop out.
    const offered = deckCandidates(release, next.flow).extra.map((table) => table.key)
    onChange(toSearch({ ...next, decks: next.decks.filter((deck) => offered.includes(deck)) }))
  }
  return (
    <form
      aria-label="Print options"
      className="print-note mb-4 space-y-4 rounded-md border p-4 text-sm"
      onSubmit={(event) => {
        event.preventDefault()
      }}
    >
      <div className="space-y-1">
        <label htmlFor="print-flow" className="font-medium">
          Flow
        </label>
        <NativeSelect
          id="print-flow"
          value={options.flow}
          onChange={(event) => {
            change({ flow: event.target.value })
          }}
        >
          <option value="">Choose a flow</option>
          {release.flows.map((flow) => (
            <option key={flow.key} value={flow.key}>
              {flow.name}
            </option>
          ))}
        </NativeSelect>
      </div>
      <fieldset className="space-y-1">
        <legend className="font-medium">Paper</legend>
        <div className="flex gap-4">
          {PAPERS.map((paper) => (
            <label key={paper} className="flex items-center gap-2">
              <input
                type="radio"
                name="print-paper"
                checked={options.paper === paper}
                onChange={() => {
                  change({ paper })
                }}
              />
              {paper.toUpperCase()}
            </label>
          ))}
        </div>
      </fieldset>
      <label className="flex items-center gap-2">
        <input
          type="checkbox"
          checked={options.bleed}
          onChange={(event) => {
            change({ bleed: event.target.checked })
          }}
        />
        Bleed and crop marks
      </label>
      {options.flow !== '' && (own.length > 0 || extra.length > 0) && (
        <fieldset className="space-y-1">
          <legend className="font-medium">Extra decks</legend>
          {own.length > 0 && (
            <p className="text-muted-foreground">
              Always printed: {own.map((table) => table.name).join(', ')}
            </p>
          )}
          {extra.map((table) => (
            <label key={table.key} className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={options.decks.includes(table.key)}
                onChange={(event) => {
                  change({
                    decks: event.target.checked
                      ? [...options.decks, table.key]
                      : options.decks.filter((deck) => deck !== table.key),
                  })
                }}
              />
              {table.name}
            </label>
          ))}
        </fieldset>
      )}
      <Button type="button" disabled={!canPrint} onClick={onPrint}>
        Print
      </Button>
    </form>
  )
}
