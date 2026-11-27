import { screen, waitFor } from '@testing-library/react'

import { miniRelease, sceneType } from '@/shared/print/testRelease'
import type { FakeHandler } from '@/test/fakeApi'
import { renderWithApi } from '@/test/renderWithApi'

import type { PrintSearch } from './printOptions'
import { ReleasePrintPage } from './ReleasePrintPage'

const CONTENT = 'GET /api/studio/game-systems/mini/releases/3/content'
const FRONT = 'a'.repeat(64)
const BACK = 'b'.repeat(64)

const pin = (slot: 'cardFront' | 'cardBack', sha256: string) => ({
  slot,
  sha256,
  mediaType: 'image/png',
  width: 815,
  height: 1110,
  url: `/api/studio/game-systems/mini/images/${sha256}`,
})

const content =
  (raw: unknown = miniRelease(), cardImages: object[] = []): FakeHandler =>
  () =>
    Response.json({
      gameSystemKey: 'mini',
      version: 3,
      publishedAt: '2026-11-27T10:00:00+00:00',
      content: raw,
      cardImages,
    })

function renderPage(handler: FakeHandler, search: PrintSearch = { flow: 'mini' }, version = '3') {
  return renderWithApi(
    <ReleasePrintPage gameSystemKey="mini" version={version} search={search} />,
    { [CONTENT]: handler },
  )
}

const cards = () => [...document.querySelectorAll<HTMLElement>('[data-card]')]
const names = () => cards().map((card) => card.getAttribute('data-card'))
const card = (name: string) => {
  const found = cards().find((candidate) => candidate.getAttribute('data-card') === name)
  if (found === undefined) {
    throw new Error(`No card "${name}": ${names().join(' | ')}`)
  }
  return found
}

describe('the release print page', () => {
  it('prints the Scene Type cards and the deck of the flow, a front and a back for each', async () => {
    renderPage(content())

    await screen.findByText(/release 3: print on A4/)
    expect(names()).toEqual([
      'Scene scene, front',
      'Scene scene, back',
      // The deck: three copies of One and one of Two, each with its own run of fronts and backs.
      ...[1, 2, 3, 4].map((at) => `The deck ${String(at)}, front`),
      // Mirrored along the long edge: each row of the backs runs the other way.
      ...[3, 2, 1, 4].map((at) => `The deck ${String(at)}, back`),
    ])
    expect(document.querySelectorAll('.print-sheet')).toHaveLength(4)
  })

  it('reads only the content of the release, never the draft', async () => {
    const { fakeApi } = renderPage(content())

    await screen.findByText(/release 3/)
    expect(fakeApi.requests).toEqual([CONTENT])
  })

  it('prints plain faces for a release that pinned no image', async () => {
    renderPage(content())

    await screen.findByText(/release 3/)
    for (const face of cards()) {
      expect(face.style.backgroundImage).toBe('')
    }
  })

  it('puts the pinned frame behind every face that prints text and the pinned back behind the deck backs', async () => {
    renderPage(content(miniRelease(), [pin('cardBack', BACK), pin('cardFront', FRONT)]))

    await screen.findByText(/release 3/)
    const url = (sha256: string) => `/api/studio/game-systems/mini/images/${sha256}`
    for (const name of [
      'Scene scene, front',
      'Scene scene, back',
      'The deck 1, front',
      'The deck 4, front',
    ]) {
      expect(card(name).style.backgroundImage).toContain(url(FRONT))
    }
    for (const name of ['The deck 1, back', 'The deck 4, back']) {
      expect(card(name).style.backgroundImage).toContain(url(BACK))
      expect(card(name).style.backgroundImage).not.toContain(FRONT)
    }
  })

  it('prints the one pinned image only where it belongs', async () => {
    renderPage(content(miniRelease(), [pin('cardBack', BACK)]))

    await screen.findByText(/release 3/)
    expect(card('Scene scene, front').style.backgroundImage).toBe('')
    expect(card('The deck 1, front').style.backgroundImage).toBe('')
    expect(card('The deck 1, back').style.backgroundImage).toContain(BACK)
  })

  it('lays the sheets out for the paper and the bleed it is given, and prints extra decks', async () => {
    renderPage(content(), { flow: 'mini', paper: 'a5', bleed: 'true', decks: ['luck'] })

    await screen.findByText(/print on A5 with bleed/)
    const sheet = document.querySelector<HTMLElement>('.print-sheet')
    expect(sheet).toHaveClass('print-a5', 'print-bleed')
    expect(sheet?.style.width).toBe('148mm')
    expect(card('Luck 2, front')).toBeInTheDocument()
    expect(card('Luck 2, back')).toBeInTheDocument()
    expect(card('Scene scene, front').style.padding).toBe('8mm')
  })

  it('continues a Scene Type whose back is too long on a "(cont.)" card', async () => {
    const lines = Array.from({ length: 12 }, (_, at) => ({
      key: `s${String(at)}`,
      kind: 'prompt',
      title: `Step ${String(at)}`,
      prompt: 'Say something long enough to take a couple of lines on a poker card back, please.',
    }))
    const raw = miniRelease()
    raw.sceneTypes = [sceneType('scene', lines)]
    renderPage(content(raw))

    await screen.findByText(/release 3/)
    expect(names()).toContain('Scene scene (cont.), front')
    expect(names()).toContain('Scene scene (cont.), back')
  })

  describe('says what is wrong instead of printing', () => {
    const alert = async () => (await screen.findByRole('alert')).textContent

    it('says a release that does not exist is not found', async () => {
      renderPage(() => Response.json({ error: 'No such release.' }, { status: 404 }))

      expect(await alert()).toMatch(/Release 3 not found/)
      expect(cards()).toEqual([])
    })

    it('says why a content the reader refuses is refused', async () => {
      const raw = miniRelease()
      Object.assign(raw.sceneTypes[0] ?? {}, { name: '' })
      renderPage(content(raw))

      expect(await alert()).toMatch(/sceneTypes\[0\]\.name/)
    })

    it('says a request that failed, and an answer that is not content', async () => {
      renderPage(() => Response.json({ error: 'Boom.' }, { status: 500 }))
      expect(await alert()).toMatch(/Loading the release content failed with HTTP 500/)
    })

    it('says the network failed', async () => {
      renderPage(() => {
        throw new TypeError('Failed to fetch')
      })

      expect(await alert()).toMatch(/Failed to fetch/)
    })

    it('refuses a pinned image whose hash is not a hash, so no URL is made of it', async () => {
      renderPage(content(miniRelease(), [pin('cardFront', 'x"); background: red')]))

      expect(await alert()).toMatch(/cardFront image with a malformed hash/)
      expect(cards()).toEqual([])
    })

    it('says a version that is not a number, without asking the API', async () => {
      const { fakeApi } = renderPage(content(), { flow: 'mini' }, 'latest')

      expect(await alert()).toMatch(/"latest" is not a release version/)
      expect(fakeApi.requests).toEqual([])
    })

    it('lists the flows when none is given', async () => {
      renderPage(content(), {})

      expect(await alert()).toMatch(/Choose a flow/)
      expect(screen.getByText(/Mini/)).toBeInTheDocument()
      expect(cards()).toEqual([])
    })

    it('lists the flows when the one given does not exist', async () => {
      renderPage(content(), { flow: 'other' })

      expect(await alert()).toMatch(/no flow "other"/)
      expect(screen.getByText('mini')).toBeInTheDocument()
    })

    it.each([
      [{ flow: 'mini', paper: 'letter' }, /Unknown paper "letter"/],
      [{ flow: 'mini', bleed: 'maybe' }, /Unknown bleed value "maybe"/],
      [{ flow: 'mini', decks: ['nope'] }, /no oracle table "nope"/],
    ])('refuses the option %j', async (search, problem) => {
      renderPage(content(), search)

      expect(await alert()).toMatch(problem)
      expect(cards()).toEqual([])
    })

    it('says why the cards of a flow cannot be built', async () => {
      const raw = miniRelease()
      Object.assign(raw.oracles.tables[0]?.entries[0] ?? {}, { sceneType: 'gone' })
      renderPage(content(raw))

      expect(await alert()).toMatch(/names the Scene Type "gone"/)
      await waitFor(() => {
        expect(cards()).toEqual([])
      })
    })
  })
})
