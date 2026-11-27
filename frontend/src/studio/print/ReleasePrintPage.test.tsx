import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { miniRelease, sceneType, step } from '@/shared/print/testRelease'
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
  const onSearchChange = vi.fn<(search: PrintSearch) => void>()
  const { fakeApi } = renderWithApi(
    <ReleasePrintPage
      gameSystemKey="mini"
      version={version}
      search={search}
      onSearchChange={onSearchChange}
    />,
    { [CONTENT]: handler },
  )
  return { fakeApi, onSearchChange }
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

  it('prints the flow sheet, the diagram, the oracle tables, the references and the log, after the card sheets', async () => {
    const raw = miniRelease()
    Object.assign(raw.flows[0]?.phases[0] ?? {}, {
      description: 'Draw and play.',
      worldTurn: [step('turn', 'prompt', { prompt: 'The world turns.' })],
    })
    renderPage(content(raw), { flow: 'mini', paper: 'a5' })

    await screen.findByText(/release 3: print on A5/)
    const pages = [...document.querySelectorAll('.print-sheet, .print-doc')].map(
      (page) => page.getAttribute('data-sheet') ?? 'cards',
    )
    expect(pages).toEqual([
      'cards',
      'cards',
      'cards',
      'cards',
      'flow',
      'diagram',
      'tables',
      'checks',
      'trackers',
      'log',
    ])
    const flow = document.querySelector('[data-sheet="flow"]')
    expect(flow).toHaveClass('print-doc', 'print-a5')
    expect(flow?.textContent).toMatch(/Draw and play\./)
    expect(flow?.textContent).toMatch(/Each scene is drawn from The deck\./)
    expect(flow?.textContent).toMatch(/World turnTitle of turn\. The world turns\./)
    expect(document.querySelector('[data-sheet="diagram"] svg')).not.toBeNull()
    const luck = document.querySelector('[data-table="luck"]')
    expect(luck?.querySelector('caption')?.textContent).toBe('Luck: d4, roll again above 2')
    expect([...(luck?.querySelectorAll('tr') ?? [])].map((row) => row.textContent)).toEqual([
      '1Good',
      '2Bad',
    ])
  })

  it('prints no tables sheet for a flow without oracle tables', async () => {
    const raw = miniRelease()
    raw.flows[0]?.oracles.splice(0, 2, 'yes-no')
    renderPage(content(raw))

    await screen.findByText(/release 3/)
    expect(document.querySelector('[data-sheet="flow"]')).not.toBeNull()
    expect(document.querySelector('[data-sheet="tables"]')).toBeNull()
  })

  it('prints the checks reference, the trackers of the flow and the campaign log from the release', async () => {
    renderPage(content())

    await screen.findByText(/release 3/)
    expect(document.querySelector('[data-check="risk"]')?.textContent).toMatch(/Risk roll \(1d6\)/)
    expect(document.querySelector('[data-oracle="yes-no"]')?.textContent).toMatch(
      /Yes on 30 or less/,
    )
    expect(document.querySelector('[data-tracker="doom"] svg')).not.toBeNull()
    expect(document.querySelector('[data-tracker="nerve"]')).toBeNull()
    expect(document.querySelector('[data-slot="sin"]')).not.toBeNull()
    expect(document.querySelector('[data-tag="ally"]')).not.toBeNull()
  })

  it('prints no checks reference and no trackers sheet when there is nothing for them, and still the log', async () => {
    const raw = miniRelease()
    raw.checks.length = 0
    raw.flows[0]?.oracles.splice(0, 1)
    raw.flows[0]?.trackers.splice(0, 1)
    Object.assign(raw, { factSlots: [], tags: [] })
    renderPage(content(raw))

    await screen.findByText(/release 3/)
    expect(document.querySelector('[data-sheet="checks"]')).toBeNull()
    expect(document.querySelector('[data-sheet="trackers"]')).toBeNull()
    expect(document.querySelector('[data-sheet="log"] .print-ruled')).not.toBeNull()
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

    it('says a Tracker of the flow that the release lacks, and prints no sheet', async () => {
      const raw = miniRelease()
      raw.flows[0]?.trackers.push('gone')
      renderPage(content(raw))

      expect(await alert()).toMatch(/uses the Tracker "gone", which the release does not have/)
      expect(document.querySelector('[data-sheet]')).toBeNull()
    })

    it('says a check or a likelihood oracle the reader refuses', async () => {
      const raw = miniRelease()
      Object.assign(raw.checks[0] ?? {}, { hint: undefined })
      renderPage(content(raw))

      expect(await alert()).toMatch(/checks\[0\]\.hint/)
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

    it.each([
      [
        'no cardImages',
        { gameSystemKey: 'mini', content: miniRelease() },
        /no list of pinned card images/,
      ],
      [
        'cardImages that is not a list',
        { content: miniRelease(), cardImages: 'none' },
        /no list of pinned card images/,
      ],
      ['a pin that is not an object', { content: miniRelease(), cardImages: [null] }, /slot/],
      [
        'a pin of an unknown slot',
        { content: miniRelease(), cardImages: [{ slot: 'logo', sha256: FRONT }] },
        /slot this page does not print/,
      ],
      [
        'a pin without a hash',
        { content: miniRelease(), cardImages: [{ slot: 'cardFront' }] },
        /cardFront image with a malformed hash/,
      ],
      ['no content', { cardImages: [] }, /Release file: expected an object/],
      [
        'a content that is a list',
        { content: [], cardImages: [] },
        /Release file: expected an object/,
      ],
      [
        'a content that is a string',
        { content: 'x', cardImages: [] },
        /Release file: expected an object/,
      ],
    ])('shows the alert, and no cards, for %s', async (_name, body, problem) => {
      renderPage(() => Response.json(body))

      expect(await alert()).toMatch(problem)
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
      expect(screen.getByText('The flows of this release:')).toBeInTheDocument()
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

    it.each([
      [
        'a flow oracle that is no table',
        (raw: ReturnType<typeof miniRelease>) => raw.flows[0]?.oracles.push('gone'),
        /The flow's oracle "gone" is neither a table/,
      ],
      [
        'a table over a d100',
        (raw: ReturnType<typeof miniRelease>) =>
          Object.assign(raw.oracles.tables[1]?.entries[0] ?? {}, { weight: 100 }),
        /The table "luck" cannot be printed: .*more than a d100/,
      ],
      [
        'a phase that names a Scene Type the release lacks',
        (raw: ReturnType<typeof miniRelease>) =>
          Object.assign(raw.flows[0]?.phases[1]?.selection ?? {}, { sceneTypes: ['gone'] }),
        /gone/,
      ],
      [
        'a hook step that points at a step that is not there',
        (raw: ReturnType<typeof miniRelease>) =>
          Object.assign(raw.flows[0]?.phases[0] ?? {}, {
            worldTurn: [step('turn', 'prompt', { prompt: 'See {step:gone}.' })],
          }),
        /\{step:gone\}/,
      ],
    ])('says it, and prints no sheet at all, for %s', async (_name, change, problem) => {
      const raw = miniRelease()
      change(raw)
      renderPage(content(raw))

      expect(await alert()).toMatch(problem)
      expect(document.querySelector('.print-sheet, .print-doc')).toBeNull()
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

describe('the print options form', () => {
  const form = async () =>
    within(await screen.findByRole('form', { name: 'Print options' }))

  it('lists the flows, the papers and the extra decks of the chosen flow, and says which decks always print', async () => {
    renderPage(content(), { flow: 'mini' })

    const options = await form()
    expect(options.getByRole('combobox', { name: 'Flow' })).toHaveValue('mini')
    expect(options.getByRole('option', { name: 'Choose a flow' })).toHaveValue('')
    expect(options.getByRole('radio', { name: 'A4' })).toBeChecked()
    expect(options.getByRole('radio', { name: 'A5' })).not.toBeChecked()
    expect(options.getByRole('checkbox', { name: 'Bleed and crop marks' })).not.toBeChecked()
    // The likelihood oracle is not a table, and "The deck" is already the flow's own.
    expect(options.getByRole('checkbox', { name: 'Luck' })).not.toBeChecked()
    expect(options.queryByRole('checkbox', { name: 'The deck' })).toBeNull()
    expect(options.getByText('Always printed: The deck')).toBeInTheDocument()
    expect(options.getAllByRole('checkbox')).toHaveLength(2)
  })

  it('shows the form and no sheets, with nothing chosen, an unknown flow or an unknown deck', async () => {
    renderPage(content(), {})
    expect(await form()).toBeDefined()
    expect(screen.getByRole('alert')).toHaveTextContent('Choose a flow to print.')
    expect(screen.getByRole('combobox', { name: 'Flow' })).toHaveValue('')
    expect(screen.queryByRole('checkbox', { name: 'Luck' })).toBeNull()
    expect(cards()).toEqual([])
  })

  it.each([
    [{ flow: 'other' }, /no flow "other"/],
    [{ flow: 'mini', decks: ['nope'] }, /no oracle table "nope"/],
  ])('shows the problems above the form for %j', async (search, problem) => {
    renderPage(content(), search)

    expect(await form()).toBeDefined()
    expect(screen.getByRole('alert')).toHaveTextContent(problem)
    expect(cards()).toEqual([])
  })

  it('replaces the options of the address with the chosen flow, paper, bleed and deck', async () => {
    const user = userEvent.setup()
    const { onSearchChange } = renderPage(content(), { flow: 'mini' })
    const options = await form()

    await user.click(options.getByRole('radio', { name: 'A5' }))
    expect(onSearchChange).toHaveBeenLastCalledWith({
      flow: 'mini',
      paper: 'a5',
      bleed: undefined,
      decks: undefined,
    })
    await user.click(options.getByRole('checkbox', { name: 'Bleed and crop marks' }))
    expect(onSearchChange).toHaveBeenLastCalledWith({
      flow: 'mini',
      paper: undefined,
      bleed: 'true',
      decks: undefined,
    })
    await user.click(options.getByRole('checkbox', { name: 'Luck' }))
    expect(onSearchChange).toHaveBeenLastCalledWith({
      flow: 'mini',
      paper: undefined,
      bleed: undefined,
      decks: ['luck'],
    })
  })

  it('writes the flow chosen from the list, and keeps no deck the flow does not offer', async () => {
    const user = userEvent.setup()
    const raw = miniRelease()
    raw.flows.push({ ...structuredClone(raw.flows[0] ?? raw.flows[0]), key: 'other', name: 'Other' } as never)
    raw.flows[1]?.oracles.splice(0, 2)
    const { onSearchChange } = renderPage(content(raw), { flow: 'mini', decks: ['luck'] })

    await user.selectOptions(await screen.findByRole('combobox', { name: 'Flow' }), 'other')

    expect(onSearchChange).toHaveBeenLastCalledWith({
      flow: 'other',
      paper: undefined,
      bleed: undefined,
      decks: undefined,
    })
  })

  it('never writes a value the resolver would refuse: an unknown paper or bleed is replaced', async () => {
    const user = userEvent.setup()
    const { onSearchChange } = renderPage(content(), { flow: 'mini', paper: 'a3', bleed: 'maybe' })

    await user.click(await screen.findByRole('radio', { name: 'A5' }))

    expect(onSearchChange).toHaveBeenLastCalledWith({
      flow: 'mini',
      paper: 'a5',
      bleed: undefined,
      decks: undefined,
    })
  })

  it('disables Print while the options do not resolve', async () => {
    renderPage(content(), {})

    expect(await screen.findByRole('button', { name: 'Print' })).toBeDisabled()
  })

  it('opens the browser print dialog from Print once the options resolve', async () => {
    const user = userEvent.setup()
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined)
    renderPage(content(), { flow: 'mini' })

    await user.click(await screen.findByRole('button', { name: 'Print' }))

    expect(print).toHaveBeenCalledTimes(1)
    print.mockRestore()
  })

  it('lists the Scene Types that continue on "(cont.)" cards, and says nothing when none does', async () => {
    const lines = Array.from({ length: 12 }, (_, at) => ({
      key: `s${String(at)}`,
      kind: 'prompt',
      title: `Step ${String(at)}`,
      prompt: 'Say something long enough to take a couple of lines on a poker card back, please.',
    }))
    const raw = miniRelease()
    raw.sceneTypes = [sceneType('scene', lines)]
    renderPage(content(raw))

    expect(await screen.findByText(/These Scene Types continue on "\(cont\.\)" cards/)).toHaveTextContent(
      /: Scene scene \(2 cards\)\.$/,
    )
  })

  it('has no overflow note when no card continues', async () => {
    renderPage(content())

    await screen.findByText(/release 3: print on A4/)
    expect(screen.queryByText(/continue on/)).toBeNull()
  })
})
