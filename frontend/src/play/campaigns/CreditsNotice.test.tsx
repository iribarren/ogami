import { render, screen } from '@testing-library/react'

import { CreditsNotice } from './CreditsNotice'

const url = 'https://example.com/policy'

describe('CreditsNotice', () => {
  it('shows nothing without credits', () => {
    const { container } = render(<CreditsNotice credits={null} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('links the license name to the url', () => {
    render(
      <CreditsNotice
        credits={{ notice: 'Fan content.', license: 'Homebrew Policy', url, badge: null }}
      />,
    )

    expect(screen.getByRole('link', { name: 'Homebrew Policy' })).toHaveAttribute('href', url)
  })

  it('falls back to the url as link text when the license is blank', () => {
    render(<CreditsNotice credits={{ notice: 'Fan content.', license: '  ', url, badge: null }} />)

    expect(screen.getByRole('link', { name: url })).toHaveAttribute('href', url)
  })

  it('falls back to the url as link text when the license is empty', () => {
    render(<CreditsNotice credits={{ notice: 'Fan content.', license: '', url, badge: null }} />)

    expect(screen.getByRole('link', { name: url })).toHaveAttribute('href', url)
  })

  it('shows a license without a url as plain text', () => {
    render(
      <CreditsNotice
        credits={{ notice: 'Fan content.', license: 'MIT', url: null, badge: null }}
      />,
    )

    expect(screen.getByText(/MIT/)).toBeInTheDocument()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })

  it('shows no logo without a badge', () => {
    render(
      <CreditsNotice credits={{ notice: 'Fan content.', license: null, url: null, badge: null }} />,
    )

    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })

  it('shows the logo of the badge beside the notice', () => {
    render(
      <CreditsNotice
        credits={{ notice: 'Fan content.', license: null, url, badge: 'dark-pack' }}
      />,
    )

    const logo = screen.getByRole('img', { name: 'Dark Pack logo' })
    expect(logo).toHaveAttribute('src', expect.stringContaining('dark-pack'))
    expect(screen.getByRole('region', { name: 'About this game' })).toContainElement(logo)
    expect(screen.getByText('Fan content.')).toBeInTheDocument()
  })

  it('shows the notice without a logo for a badge this build does not know', () => {
    render(
      <CreditsNotice
        credits={{
          notice: 'Fan content.',
          license: null,
          url,
          badge: 'future-badge' as 'dark-pack',
        }}
      />,
    )

    expect(screen.getByText('Fan content.')).toBeInTheDocument()
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })
})
