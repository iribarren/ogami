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

  it('is a titled region, open by default', () => {
    renderPanel()

    expect(screen.getByRole('region', { name: 'Facts' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'Facts' })).toBeInTheDocument()
    expect(trigger()).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText('The harbour master owes a favour.')).toBeInTheDocument()
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
