import { expect, type Locator } from '@playwright/test'

/**
 * Opens a Studio step card (`group` "Step N") the way a person does, by pressing its disclosure.
 * Step cards start collapsed, but one the editor flags or marks current starts open, so the
 * disclosure is pressed only while it reads closed: pressing an open card would close it.
 */
export async function openStepCard(card: Locator) {
  // The card's own disclosure is its first button.
  const disclosure = card.getByRole('button').first()
  await expect(disclosure).toHaveAttribute('aria-expanded', /^(true|false)$/)
  if ((await disclosure.getAttribute('aria-expanded')) === 'false') {
    await disclosure.click()
  }
  await expect(disclosure).toHaveAttribute('aria-expanded', 'true')
}
