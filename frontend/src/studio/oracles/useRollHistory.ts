import { useRef, useState } from 'react'

/** How many results the panel keeps, the newest first. */
const KEPT = 5

interface Shown<Result> {
  id: number
  result: Result
}

export interface RollHistory<Result> {
  results: Shown<Result>[]
  add: (result: Result) => void
}

/**
 * The last results, newest first. The editor page owns it: a rename remounts the editor on the
 * oracle's new key, and the roll or answer that saved it must still show.
 */
export function useRollHistory<Result>(): RollHistory<Result> {
  const [results, setResults] = useState<Shown<Result>[]>([])
  const nextId = useRef(0)
  return {
    results,
    add: (result) => {
      const id = nextId.current++
      setResults((shown) => [{ id, result }, ...shown].slice(0, KEPT))
    },
  }
}
