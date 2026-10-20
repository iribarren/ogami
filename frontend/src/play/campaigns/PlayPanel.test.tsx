import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { PlayPanel } from './PlayPanel'
import { playPanelStorageKey } from './playPanelStorage'

function renderPanel(count?: number) {
  return render(
    <PlayPanel id="facts" title="Facts" count={count}>
      <p>The harbour master owes a favour.</p>
    </PlayPanel>,
  )
}

const trigger = () => screen.getByRole('button', { name: 'Facts' })

describe('PlayPanel', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('draws the theme frame only when asked to, without changing its accessible name', () => {
    const { unmount } = renderPanel()
    expect(screen.getByRole('region', { name: 'Facts' })).not.toHaveClass('frame-corners')
    unmount()

    render(
      <PlayPanel id="dice" title="Dice" frame>
        <p>Roll.</p>
      </PlayPanel>,
    )
    expect(screen.getByRole('region', { name: 'Dice' })).toHaveClass('frame-corners')
    expect(screen.getByRole('button', { name: 'Dice' })).toBeInTheDocument()
  })

  it('is a titled region, open by default', () => {
    renderPanel()

    expect(screen.getByRole('region', { name: 'Facts' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'Facts' })).toBeInTheDocument()
    expect(trigger()).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText('The harbour master owes a favour.')).toBeInTheDocument()
  })

  it('shows an optional icon that stays out of the title name', () => {
    render(
      <PlayPanel id="facts" title="Facts" icon={<svg data-testid="icon" />}>
        <p>Body</p>
      </PlayPanel>,
    )

    expect(screen.getByTestId('icon').closest('[aria-hidden="true"]')).not.toBeNull()
    expect(screen.getByRole('heading', { level: 2, name: 'Facts' })).toBeInTheDocument()
    expect(trigger()).toBeInTheDocument()
  })

  it('collapses and expands from its title', async () => {
    const user = userEvent.setup()
    renderPanel()

    await user.click(trigger())

    expect(trigger()).toHaveAttribute('aria-expanded', 'false')
    expect(screen.getByText('The harbour master owes a favour.')).not.toBeVisible()
    expect(screen.queryByRole('paragraph')).not.toBeInTheDocument()

    await user.click(trigger())

    expect(trigger()).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText('The harbour master owes a favour.')).toBeInTheDocument()
  })

  it('links its title to the body it controls, open or collapsed', async () => {
    const user = userEvent.setup()
    renderPanel()
    const controlledBody = () =>
      document.getElementById(trigger().getAttribute('aria-controls') ?? '')

    expect(controlledBody()).toContainElement(screen.getByText('The harbour master owes a favour.'))

    await user.click(trigger())

    expect(controlledBody()).toContainElement(screen.getByText('The harbour master owes a favour.'))
  })

  it('keeps the body mounted while collapsed, so local tool state survives', async () => {
    const user = userEvent.setup()
    render(
      <PlayPanel id="facts" title="Facts">
        <input aria-label="Draft fact" />
      </PlayPanel>,
    )

    await user.type(screen.getByRole('textbox', { name: 'Draft fact' }), 'Owes a favour')
    await user.click(trigger())

    expect(screen.queryByRole('textbox', { name: 'Draft fact' })).not.toBeInTheDocument()

    await user.click(trigger())

    expect(screen.getByRole('textbox', { name: 'Draft fact' })).toHaveValue('Owes a favour')
  })

  it('remembers its state per section across a remount', async () => {
    const user = userEvent.setup()
    const { unmount } = renderPanel()

    await user.click(trigger())
    expect(localStorage.getItem(playPanelStorageKey('facts'))).toBe('closed')
    unmount()
    render(
      <>
        <PlayPanel id="facts" title="Facts">
          <p>Remounted facts.</p>
        </PlayPanel>
        <PlayPanel id="dice" title="Dice">
          <p>Remounted dice.</p>
        </PlayPanel>
      </>,
    )

    expect(trigger()).toHaveAttribute('aria-expanded', 'false')
    expect(screen.getByRole('button', { name: 'Dice' })).toHaveAttribute('aria-expanded', 'true')
  })

  it('treats an unknown stored value as open', () => {
    localStorage.setItem(playPanelStorageKey('facts'), 'sideways')

    renderPanel()

    expect(trigger()).toHaveAttribute('aria-expanded', 'true')
  })

  it('works when storage throws', async () => {
    const user = userEvent.setup()
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    renderPanel()

    expect(trigger()).toHaveAttribute('aria-expanded', 'true')

    await user.click(trigger())

    expect(trigger()).toHaveAttribute('aria-expanded', 'false')
  })

  it('shows a count next to the title only when given one', () => {
    const { unmount } = renderPanel(3)

    expect(screen.getByText('3')).toBeInTheDocument()
    unmount()
    const { unmount: unmountEmpty } = renderPanel(0)
    expect(screen.getByText('0')).toBeInTheDocument()
    unmountEmpty()
    renderPanel()
    expect(screen.queryByText('0')).not.toBeInTheDocument()
  })
})

describe('PlayPanel help', () => {
  it('shows one muted line at the top of the body, and none without help', () => {
    const { rerender } = render(
      <PlayPanel id="threads" title="Threads" help="What the story follows.">
        <p>Find the keeper</p>
      </PlayPanel>,
    )

    const help = screen.getByText('What the story follows.')
    const body = screen.getByText('Find the keeper')
    expect(help.className).toContain('text-muted-foreground')
    expect(help.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()

    rerender(
      <PlayPanel id="threads" title="Threads">
        <p>Find the keeper</p>
      </PlayPanel>,
    )

    expect(screen.queryByText('What the story follows.')).not.toBeInTheDocument()
  })
})
