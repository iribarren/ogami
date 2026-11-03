import { expect, type Locator } from '@playwright/test'

/**
 * Sees the "Roll a check" `dialog` closed after its Roll button was clicked. The dice decide how:
 * a failed die to push or a special result to read keeps it open on Keep or Done, and any other
 * roll closes it on its own, so a spec cannot expect the result inside the dialog.
 */
export async function finishRoll(dialog: Locator) {
  const close = dialog.getByRole('button', { name: /^(Keep|Done)$/ })
  await expect(async () => {
    if (await close.isVisible()) {
      await close.click()
    }
    await expect(dialog).toBeHidden({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })
}
