/**
 * Opening the dialog focuses the first field, not the Close button (whose tooltip would pop up at
 * once). Without one, Radix focuses as usual, so focus never stays outside the modal.
 */
export function focusNotes(event: Event) {
  const notes =
    event.currentTarget instanceof HTMLElement
      ? event.currentTarget.querySelector('textarea')
      : null
  if (notes !== null) {
    event.preventDefault()
    notes.focus()
  }
}
