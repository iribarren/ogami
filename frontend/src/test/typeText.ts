import type { UserEvent } from '@testing-library/user-event'

/**
 * Enters `text` at the end of `field` in one input event, where `user.type` would send one per
 * character. Every input event re-renders the edited card and its editor (the step, band and group
 * cards are memoised, so the other cards stay as they were), and that cost per event is what makes
 * the Studio tests slow. Use `user.type` when the keystrokes matter: `{Enter}`, `{Escape}`, `{Backspace}`, an
 * assertion between two keys, or a field that reacts to each key.
 */
export async function typeText(user: UserEvent, field: HTMLElement, text: string) {
  await user.click(field)
  await user.paste(text)
}

/**
 * Replaces the content of `field` with `text` in one input event (select all, then paste), where
 * `user.clear` followed by `user.type` sends one event for the clear and one per character.
 */
export async function replaceText(user: UserEvent, field: HTMLElement, text: string) {
  await user.tripleClick(field)
  await user.paste(text)
}
