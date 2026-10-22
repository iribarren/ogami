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
  await page
    .getByRole('option', { name: option, exact: true })
    .or(page.locator(`[role="option"][data-option-value="${option}"]`))
    .first()
    .click()
}
