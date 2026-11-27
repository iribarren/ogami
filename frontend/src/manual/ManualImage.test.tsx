import { render, screen } from '@testing-library/react'
import { vi } from 'vitest'

import { imagePrompt } from './images/styles'
import { ManualImage } from './ManualImage'

const lookup = vi.hoisted(() => ({ urls: new Map<string, string>() }))
vi.mock('./images/lookup', () => ({ imageUrl: (file: string) => lookup.urls.get(file) }))

const props = {
  file: 'hc-ford.webp',
  style: 'hard-country',
  size: 'half',
  alt: 'A ford at dusk.',
  subject: 'Tea-brown river and a frayed rope on a willow.',
} as const

describe('ManualImage', () => {
  beforeEach(() => {
    lookup.urls.clear()
  })

  it('shows the image with its alt text, lazily, when the file exists', () => {
    lookup.urls.set('hc-ford.webp', '/assets/hc-ford.webp')

    const { container } = render(<ManualImage {...props} />)

    const image = screen.getByRole('img', { name: 'A ford at dusk.' })
    expect(image).toHaveAttribute('src', '/assets/hc-ford.webp')
    expect(image).toHaveAttribute('loading', 'lazy')
    expect(container.querySelector('figure')).toHaveAttribute('data-image-size', 'half')
    expect(screen.queryByText('Illustration to come')).toBeNull()
  })

  it('loads the image eagerly when asked, for the printed book', () => {
    lookup.urls.set('hc-ford.webp', '/assets/hc-ford.webp')

    render(<ManualImage {...props} eager />)

    expect(screen.getByRole('img', { name: 'A ford at dusk.' })).toHaveAttribute('loading', 'eager')
  })

  it('shows a placeholder of the same size with the file, the alt text and the full prompt', () => {
    const { container } = render(<ManualImage {...props} />)

    expect(container.querySelector('figure')).toHaveAttribute('data-image-size', 'half')
    expect(container.querySelector('img')).toBeNull()
    expect(screen.getByText('Illustration to come')).toBeVisible()
    expect(screen.getByText('hc-ford.webp')).toBeVisible()
    expect(screen.getByText('A ford at dusk.')).toBeVisible()
    expect(screen.getByText(imagePrompt('hard-country', props.subject))).toBeVisible()
  })

  it('lets the placeholder grow to fit its prompt, never clipping or scrolling it', () => {
    const { container } = render(<ManualImage {...props} size="spot" />)

    const placeholder = container.querySelector('[data-image-placeholder]')
    expect(placeholder).not.toBeNull()
    expect(placeholder?.className).not.toMatch(/overflow|(^|\s)h-(full|\d|\[)/)
    expect(placeholder?.getAttribute('style') ?? '').not.toMatch(/height|overflow/)
  })

  it('fails loudly for a style it does not know', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    expect(() => render(<ManualImage {...props} style="nope" />)).toThrow(/"nope"/)
  })
})
