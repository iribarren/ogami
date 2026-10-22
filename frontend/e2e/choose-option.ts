import type { Locator, Page } from '@playwright/test'

/**
 * Picks `option` in the Studio select labelled `label`: opens it, then clicks the option by
 * its name (or its value, as `selectOption` took either). `scope` narrows the label lookup to a
 * dialog or form; the options always open in a portal on the page.
 */
export async function chooseOption(
  page: Page,
  label: string,
  option: string,
  scope: Page | Locator = page,
) {
  await scope.getByLabel(label, { exact: true }).click()
  // The name wins; the value is only a fallback when no option has that name, so an option
  // named like another one's value is never picked by mistake.
  const byName = page.getByRole('option', { name: option, exact: true })
  if ((await byName.count()) > 0) {
    await byName.first().click()
    return
  }
  const options = page.getByRole('option')
  const values = await options.evaluateAll((nodes) =>
    nodes.map((node) => node.getAttribute('data-option-value')),
  )
  const index = values.indexOf(option)
  if (index < 0) {
    throw new Error(`No option "${option}" in the "${label}" select.`)
  }
  await options.nth(index).click()
}
