import { render, screen } from '@testing-library/react'

import { CreditsNotice } from './CreditsNotice'

const url = 'https://example.com/policy'

describe('CreditsNotice', () => {
  it('shows nothing without credits', () => {
    const { container } = render(<CreditsNotice credits={null} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('links the license name to the url', () => {
    render(<CreditsNotice credits={{ notice: 'Fan content.', license: 'Homebrew Policy', url }} />)

    expect(screen.getByRole('link', { name: 'Homebrew Policy' })).toHaveAttribute('href', url)
  })

  it('falls back to the url as link text when the license is blank', () => {
    render(<CreditsNotice credits={{ notice: 'Fan content.', license: '  ', url }} />)

    expect(screen.getByRole('link', { name: url })).toHaveAttribute('href', url)
  })

  it('falls back to the url as link text when the license is empty', () => {
    render(<CreditsNotice credits={{ notice: 'Fan content.', license: '', url }} />)

    expect(screen.getByRole('link', { name: url })).toHaveAttribute('href', url)
  })

  it('shows a license without a url as plain text', () => {
    render(<CreditsNotice credits={{ notice: 'Fan content.', license: 'MIT', url: null }} />)

    expect(screen.getByText(/MIT/)).toBeInTheDocument()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})
