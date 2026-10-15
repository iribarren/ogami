import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { CharacterSheetDialog } from './CharacterSheetDialog'
import { initiativeField, oldCharacter, sheet, viCharacter } from './fixtures'

/** The sheet with a derived field laid out inside the Stats group. */
const withInitiative = [...sheet.slice(0, 3), initiativeField, ...sheet.slice(3)]

describe('CharacterSheetDialog', () => {
  it('opens the sheet of the Character read-only, field by field under its group', async () => {
    const user = userEvent.setup()
    render(<CharacterSheetDialog character={viCharacter} sheet={withInitiative} />)

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'Vi' }))
    expect(dialog.getByRole('group', { name: 'Identity' })).toHaveTextContent('RoleSolo')
    expect(dialog.getByRole('group', { name: 'Stats' })).toHaveTextContent('Reflexes7')
    expect(dialog.getByRole('group', { name: 'Stats' })).toHaveTextContent('Body6')
    expect(dialog.getByRole('group', { name: 'Vitals' })).toHaveTextContent('HP30 / 40')
    expect(dialog.getByRole('group', { name: 'Gear' })).toHaveTextContent('KnifeAgent')
    expect(dialog.getByText('Owes a favor.')).toBeInTheDocument()
    expect(dialog.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('shows a derived value the server computed, and a dash for one it did not', async () => {
    const user = userEvent.setup()
    const { rerender } = render(
      <CharacterSheetDialog
        character={{ ...viCharacter, derived: { initiative: 13 } }}
        sheet={withInitiative}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))
    expect(within(await screen.findByRole('dialog')).getByText('13')).toBeInTheDocument()
    await user.keyboard('{Escape}')

    rerender(<CharacterSheetDialog character={viCharacter} sheet={withInitiative} />)
    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))
    expect(within(await screen.findByRole('dialog')).getByText('—')).toBeInTheDocument()
  })

  it('fills the fields of a Character saved before the sheet gained them with the defaults', async () => {
    const user = userEvent.setup()
    render(<CharacterSheetDialog character={oldCharacter} sheet={sheet} />)

    await user.click(screen.getByRole('button', { name: 'View sheet of Old hand' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'Old hand' }))
    expect(dialog.getByRole('group', { name: 'Stats' })).toHaveTextContent('Reflexes5')
  })

  it('closes on Escape', async () => {
    const user = userEvent.setup()
    render(<CharacterSheetDialog character={viCharacter} sheet={sheet} />)

    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))
    await screen.findByRole('dialog')
    await user.keyboard('{Escape}')

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('says so when the release has no sheet fields', async () => {
    const user = userEvent.setup()
    render(<CharacterSheetDialog character={viCharacter} sheet={[]} />)

    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))

    expect(await screen.findByText('This release has no sheet fields.')).toBeInTheDocument()
  })
})
