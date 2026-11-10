import { chapters } from './chapters'
import { splitEditionBlocks } from './editionBlocks'
import { pageSources } from './pages'

describe('the manual content', () => {
  const sources = pageSources()

  it('has a file for every manifest entry and an entry for every file', () => {
    expect(Object.keys(sources).sort()).toEqual(chapters.map((chapter) => chapter.slug).sort())
  })

  it('has unique slugs', () => {
    expect(new Set(chapters.map((chapter) => chapter.slug)).size).toBe(chapters.length)
  })

  it.each(chapters.map((chapter) => chapter.slug))(
    'splits %s into well-formed segments',
    (slug) => {
      const segments = splitEditionBlocks(sources[slug] ?? '')

      expect(segments.length).toBeGreaterThan(0)
      expect(segments[0]).toMatchObject({ edition: 'common' })
    },
  )

  it.each(chapters.map((chapter) => chapter.slug))(
    'keeps the app out of the common text of %s',
    (slug) => {
      const common = splitEditionBlocks(sources[slug] ?? '')
        .filter((segment) => segment.edition === 'common')
        .map((segment) => segment.text)
        .join('\n')

      expect(common).not.toMatch(/\b(Ogami|Studio|app)\b/i)
    },
  )

  it.each(chapters.filter((chapter) => chapter.editions === 'both').map((chapter) => chapter.slug))(
    'has no internal link in the common text of %s',
    (slug) => {
      // The Paper edition renders this text without a router, which an internal link needs.
      const common = splitEditionBlocks(sources[slug] ?? '')
        .filter((segment) => segment.edition === 'common')
        .map((segment) => segment.text)
        .join('\n')

      expect(common).not.toContain('](/')
    },
  )

  it('says in the first page where the Studio links are', () => {
    const inOgami = splitEditionBlocks(sources['how-to-use'] ?? '').filter(
      (segment) => segment.edition === 'in-ogami',
    )

    expect(inOgami.length).toBeGreaterThan(0)
  })

  it.each(
    chapters.filter((chapter) => /^Part (I|II),/.test(chapter.part)).map((chapter) => chapter.slug),
  )('gives %s an On paper section and one In Ogami block', (slug) => {
    const segments = splitEditionBlocks(sources[slug] ?? '')

    expect(segments.filter((segment) => segment.edition === 'in-ogami')).toHaveLength(1)
    expect(sources[slug]).toMatch(/^## On paper$/m)
  })
})
