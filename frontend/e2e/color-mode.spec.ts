import { expect, test } from '@playwright/test'

test('the color mode chosen in the header survives a reload', async ({ page }) => {
  await page.goto('/')
  const mode = page.getByLabel('Color mode', { exact: true })
  const root = page.locator('html')

  await mode.selectOption('dark')
  await expect(root).toHaveClass(/\bdark\b/)

  await page.reload()

  await expect(mode).toHaveValue('dark')
  await expect(root).toHaveClass(/\bdark\b/)

  await mode.selectOption('light')
  await page.reload()

  await expect(mode).toHaveValue('light')
  await expect(root).not.toHaveClass(/\bdark\b/)
})
