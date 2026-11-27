import { expect, test, type Page } from '@playwright/test'

import { solidPng } from './png.ts'
import { manager, signIn } from './sign-in.ts'

// Users and the "hard-country" preset are seeded by `make e2e-seed`.

const FLOW = 'The Long Carry'
const FLOW_KEY = 'the-long-carry'

const upload = (page: Page, label: string, colour: [number, number, number]) =>
  page.getByLabel(label).setInputFiles({
    name: 'card.png',
    mimeType: 'image/png',
    buffer: solidPng(colour),
  })

/** The path of an image the Overview tab previews: it holds the hash of the stored bytes. */
const previewPath = async (page: Page, alt: string) => {
  const src = await page.getByRole('img', { name: alt }).getAttribute('src')
  expect(src).toMatch(/\/images\/[0-9a-f]{64}$/)
  return src ?? ''
}

/** The cards that do not fit their own box, under print media. */
const overflowing = (page: Page) =>
  page.evaluate(() =>
    [...document.querySelectorAll('[data-card]')]
      .filter(
        (card) =>
          card.scrollHeight > card.clientHeight + 1 || card.scrollWidth > card.clientWidth + 1,
      )
      .map((card) => card.getAttribute('data-card')),
  )

/** The image URLs the cards print on, as the page asks for them. */
const cardImages = (page: Page) =>
  page.evaluate(() => [
    ...new Set(
      [...document.querySelectorAll('[data-card]')].flatMap((card) => {
        const match = /url\("(.+)"\)/.exec(getComputedStyle(card).backgroundImage)
        return match?.[1] === undefined ? [] : [new URL(match[1]).pathname]
      }),
    ),
  ])

const imagesLoad = (page: Page, paths: string[]) =>
  page.evaluate(
    (urls) =>
      Promise.all(
        urls.map(
          (url) =>
            new Promise<number>((resolve) => {
              const image = new Image()
              image.onload = () => {
                resolve(image.naturalWidth)
              }
              image.onerror = () => {
                resolve(0)
              }
              image.src = url
            }),
        ),
      ),
    paths,
  )

test('a game manager prints a published release with its pinned card images', async ({ page }) => {
  // Unique per run: the e2e database keeps the GameSystems of earlier runs.
  const key = `e2e-print-${Date.now().toString(36)}`
  await signIn(page, manager)
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New GameSystem' }).click()

  const newForm = page.getByRole('form', { name: 'New GameSystem' })
  await page.getByRole('radio', { name: 'Copy a release' }).click()
  await newForm.getByLabel('Source GameSystem').selectOption('hard-country')
  await newForm.getByLabel('Key', { exact: true }).fill(key)
  await newForm.getByLabel('Name').fill(`E2E print ${key}`)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}`)

  await upload(page, 'Upload card frame file', [200, 40, 40])
  await upload(page, 'Upload card back file', [30, 60, 160])
  const front = await previewPath(page, 'Card frame preview')
  const back = await previewPath(page, 'Card back preview')
  expect(front).not.toBe(back)

  await page.getByRole('button', { name: 'Publish' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Publish version 1' }).click()
  await expect(page.getByText('Published version 1.')).toBeVisible()

  await page.getByRole('link', { name: 'Releases' }).click()
  await page.getByRole('link', { name: 'Print version 1' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}/releases/1/print`)
  const options = page.getByRole('form', { name: 'Print options' })
  await options.getByLabel('Flow').selectOption({ label: FLOW })
  await expect(page).toHaveURL(new RegExp(`print\\?flow=${FLOW_KEY}$`))
  await expect(page.locator('[data-card]').first()).toBeVisible()

  await page.emulateMedia({ media: 'print' })
  await expect(options).toBeHidden()
  expect(await overflowing(page)).toEqual([])
  await expect(page.locator('.print-sheet.print-a4').first()).toBeVisible()
  // The Scene Type cards print on the frame, the deck backs on the back image.
  expect((await cardImages(page)).sort()).toEqual([back, front].sort())
  expect(await imagesLoad(page, [front, back])).toEqual([815, 815])

  // A5 with bleed: the form is a screen control, so switch the media for the choice.
  await page.emulateMedia({ media: 'screen' })
  await options.getByRole('radio', { name: 'A5' }).check()
  await options.getByLabel('Bleed and crop marks').check()
  await expect(page.locator('.print-sheet.print-a5.print-bleed').first()).toBeVisible()
  await page.emulateMedia({ media: 'print' })
  expect(await overflowing(page)).toEqual([])
  expect((await cardImages(page)).sort()).toEqual([back, front].sort())

  // Replacing the frame on the draft does not change what version 1 prints.
  await page.emulateMedia({ media: 'screen' })
  await page.goto(`/studio/game-systems/${key}`)
  await upload(page, 'Replace card frame file', [20, 140, 60])
  await expect(page.getByRole('img', { name: 'Card frame preview' })).not.toHaveAttribute(
    'src',
    front,
  )
  const replaced = await previewPath(page, 'Card frame preview')
  expect(replaced).not.toBe(front)

  await page.goto(`/studio/game-systems/${key}/releases/1/print?flow=${FLOW_KEY}`)
  await expect(page.locator('[data-card]').first()).toBeVisible()
  const printed = await cardImages(page)
  expect(printed).toContain(front)
  expect(printed).not.toContain(replaced)
})
