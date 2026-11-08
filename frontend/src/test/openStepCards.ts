import { fireEvent } from '@testing-library/react'

/**
 * Opens every step card on the page the way a person does, by pressing its disclosure: step cards
 * start collapsed, and a closed card has no fields to edit. A card already open is left as it is.
 */
export function openStepCards(root: ParentNode = document) {
  for (const card of root.querySelectorAll('li[aria-label^="Step "]')) {
    // The card's own disclosure is its first button; a stored card has none.
    const disclosure = card.querySelector('button')
    if (disclosure?.getAttribute('aria-expanded') === 'false') {
      fireEvent.click(disclosure)
    }
  }
}
