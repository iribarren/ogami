import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderAppAt } from '@/test/renderApp'

import { chapters } from './chapters'

describe('the manual reader', () => {
  it('lists the chapters by part, in manifest order, for an anonymous visitor', async () => {
    renderAppAt('/manual')

    const nav = await screen.findByRole('navigation', { name: 'Contents' })
    expect(within(nav).getByRole('region', { name: 'Front matter' })).toBeInTheDocument()
    const links = within(nav).getAllByRole('link')
    expect(links.map((link) => link.textContent)).toEqual(chapters.map((chapter) => chapter.title))
    expect(links[0]).toHaveAttribute('href', '/manual/how-to-use')
  })

  it('shows a page with common prose and an In Ogami callout', async () => {
    renderAppAt('/manual/how-to-use')

    expect(
      await screen.findByRole('heading', { name: 'How to use this book', level: 1 }),
    ).toBeVisible()
    const callout = screen.getByRole('complementary', { name: 'In Ogami' })
    expect(within(callout).getByText(/Full edition inside Ogami/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'What you need' })).toHaveAttribute(
      'href',
      '/manual/what-you-need',
    )
  })

  it('links to the previous and the next chapter, and stops at the ends', async () => {
    const user = userEvent.setup()
    renderAppAt('/manual/what-you-need')

    const pager = await screen.findByRole('navigation', { name: 'Chapter navigation' })
    expect(within(pager).getByRole('link', { name: /How to use this book/ })).toBeInTheDocument()
    await user.click(within(pager).getByRole('link', { name: /The two games/ }))

    expect(await screen.findByRole('heading', { name: 'The two games', level: 1 })).toBeVisible()
  })

  it('has no previous link on the first page and no next link on the last', async () => {
    renderAppAt('/manual/how-to-use')
    const first = await screen.findByRole('navigation', { name: 'Chapter navigation' })
    expect(within(first).queryByText(/Previous/)).not.toBeInTheDocument()
    expect(within(first).getByText(/Next/)).toBeInTheDocument()
  })

  it('goes to the not-found page for an unknown chapter', async () => {
    renderAppAt('/manual/no-such-chapter')

    expect(await screen.findByText('Page not found')).toBeInTheDocument()
  })
})
