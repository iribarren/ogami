import { mkdirSync } from 'node:fs'
import { extname, join, normalize, resolve } from 'node:path'

import { expect, type Page, test } from '@playwright/test'

/**
 * Prints the manual PDFs from the build in `dist-print/` (`make manual-pdf`). A module script
 * cannot load from `file://`, so the build is served from a fixed fake origin through `page.route`:
 * no server is needed.
 */
const ORIGIN = 'http://print.local'
const BUILD = resolve(process.cwd(), 'dist-print')
const OUT = resolve(process.cwd(), 'dist-manual')

const MIME: Record<string, string | undefined> = {
  '.html': 'text/html',
  '.js': 'text/javascript',
  '.css': 'text/css',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
}

async function serveBuild(page: Page) {
  await page.route(`${ORIGIN}/**`, async (route) => {
    const path = normalize(new URL(route.request().url()).pathname).replace(/^\/+/, '')
    const file = join(BUILD, path === '' ? 'print.html' : path)
    const type = MIME[extname(file)]
    if (!file.startsWith(BUILD) || type === undefined) {
      await route.fulfill({ status: 404, body: `Not in the print build: ${path}` })
      return
    }
    await route.fulfill({ path: file, contentType: type })
  })
}

/** Opens a document and waits for the fonts and for the page to say it rendered. */
async function open(page: Page, doc: string) {
  await serveBuild(page)
  await page.goto(`${ORIGIN}/print.html?doc=${doc}`)
  await page.waitForSelector('body[data-print]')
  const state = await page.evaluate(() => document.body.dataset.print)
  if (state !== 'ready') {
    throw new Error(
      `The print page "${doc}" did not render: ${await page.locator('[role=alert]').innerText()}`,
    )
  }
  await page.evaluate(() => document.fonts.ready)
}

/** No card or sheet may hold more than its box shows: names every one that does. */
async function overflowing(page: Page): Promise<string[]> {
  return page.$$eval('[data-card]', (cards) =>
    cards
      .filter(
        (card) => card.scrollHeight > card.clientHeight || card.scrollWidth > card.clientWidth,
      )
      .map((card) => card.getAttribute('data-card') ?? 'unnamed'),
  )
}

async function printPdf(page: Page, file: string, extra: Parameters<Page['pdf']>[0] = {}) {
  mkdirSync(OUT, { recursive: true })
  await page.pdf({
    path: join(OUT, file),
    preferCSSPageSize: true,
    printBackground: true,
    ...extra,
  })
}

/** The paper never names the app, in the text or in the title the PDF takes as its metadata. */
async function expectNoApp(page: Page) {
  const text = await page.evaluate(() => `${document.title}\n${document.body.innerText}`)
  expect(text).not.toMatch(/\b(Ogami|Studio|app)\b/i)
}

test('prints the Paper edition', async ({ page }) => {
  await open(page, 'paper')
  await expectNoApp(page)
  expect(await page.title()).toBe('The Narrative Flow: Designing and Playing Solo Games')
  await printPdf(page, 'manual-paper.pdf', {
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate:
      '<div style="width:100%;font-size:9px;text-align:center;font-family:serif"><span class="pageNumber"></span></div>',
  })
})

for (const doc of ['cards-the-long-carry', 'cards-cold-berth']) {
  test(`prints the card sheets: ${doc}`, async ({ page }) => {
    await open(page, doc)
    await expectNoApp(page)
    expect(await overflowing(page)).toEqual([])
    await printPdf(page, `${doc}.pdf`)
  })
}

test('an unknown document shows an error and never prints', async ({ page }) => {
  await serveBuild(page)
  await page.goto(`${ORIGIN}/print.html?doc=nope`)
  await page.waitForSelector('body[data-print]')

  expect(await page.evaluate(() => document.body.dataset.print)).toBe('error')
  await expect(page.locator('[role=alert]')).toContainText('Unknown document "nope"')
})
