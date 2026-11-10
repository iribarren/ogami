/**
 * What a flow needs on paper beyond its preset: the wording of steps a card cannot run from their
 * text, the deck, and the hand-written rules sheet. `buildFlowCards` checks every entry against the
 * preset, so a flow that changes fails the build until its paper file follows.
 */
export type PaperDeck =
  | {
      kind: 'table'
      /** The key of the weighted table whose copies are the cards. */
      table: string
      back: string
      /** The card shuffled into the bottom of the deck: a Scene Type of the flow. */
      finale: { sceneType: string; note: string }
    }
  | { kind: 'compartments'; count: number; back: string }

export interface PaperFlow {
  /** The key of the flow in the preset. */
  flow: string
  /**
   * Wording for `'sceneTypeKey.stepKey'`, instead of the step's own text; `null` leaves the step
   * off the card. A `character`, `shop`, `check` or `condition` step needs an entry.
   */
  steps: Record<string, string | null>
  /** Tips for the front of a Scene Type card, by Scene Type key, instead of the preset's. */
  tips?: Record<string, string>
  deck: PaperDeck
  rules: {
    /** What each Fact slot is on paper, by slot key. */
    slots: Record<string, string>
    /** The hand-written sections, after the Trackers and Fact slots the preset gives. */
    sections: { heading: string; lines: readonly string[] }[]
  }
}
