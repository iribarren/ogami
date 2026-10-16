import { useRef, useState } from 'react'

import type { OracleTableResult } from './useGameSystemOracles'

/** How many results the panel keeps, the newest first. */
const KEPT = 5

interface Shown {
  id: number
  result: OracleTableResult
}

export interface RollHistory {
  results: Shown[]
  add: (result: OracleTableResult) => void
}

/**
 * The last results, newest first. The editor page owns it: a rename remounts the editor on the
 * table's new key, and the roll that saved it must still show.
 */
export function useRollHistory(): RollHistory {
  const [results, setResults] = useState<Shown[]>([])
  const nextId = useRef(0)
  return {
    results,
    add: (result) => {
      const id = nextId.current++
      setResults((shown) => [{ id, result }, ...shown].slice(0, KEPT))
    },
  }
}
