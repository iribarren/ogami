import { screen, within } from '@testing-library/react'
import type { UserEvent } from '@testing-library/user-event'

/**
 * Opens the Studio `Select` named `name` and picks `option`, the way a person does (the native
 * `selectOptions` does not drive a Radix select). `option` is the option's accessible name; as
 * with `selectOptions`, a string that is no option's name falls back to the option's value.
 * `scope` narrows the trigger lookup, e.g. to one card; the options open in a portal on the
 * document.
 */
export async function chooseOption(
  user: UserEvent,
  name: string | RegExp,
  option: string | RegExp,
  scope: Pick<typeof screen, 'getByRole'> = screen,
) {
  await user.click(scope.getByRole('combobox', { name }))
  const list = within(await screen.findByRole('listbox'))
  const byName = list.queryAllByRole('option', { name: option })
  const [match] =
    byName.length > 0
      ? byName
      : list
          .getAllByRole('option')
          .filter((candidate) => candidate.getAttribute('data-option-value') === option)
  if (!match) {
    throw new Error(`No option "${String(option)}" in the "${String(name)}" select.`)
  }
  await user.click(match)
}

/**
 * The options a `Select` offers, as `{ label, value }` in order: opens it, reads the list and
 * closes it again. A closed Radix select has no option elements to query.
 */
export async function listOptions(user: UserEvent, trigger: HTMLElement) {
  await user.click(trigger)
  const options = within(await screen.findByRole('listbox'))
    .getAllByRole('option')
    .map((option) => ({
      label: option.textContent,
      value: option.getAttribute('data-option-value'),
    }))
  await user.keyboard('{Escape}')
  return options
}
