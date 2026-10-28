import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { CharacterSheetDialog } from './CharacterSheetDialog'
import {
  conditionsField,
  economy,
  eddiesField,
  initiativeField,
  inventoryField,
  oldCharacter,
  sheet,
  viCharacter,
} from './fixtures'

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

  it('shows the marked conditions by label, and None for a Character that never had the field', async () => {
    const user = userEvent.setup()
    const withWounds = [...sheet, conditionsField]
    const { rerender } = render(
      <CharacterSheetDialog
        character={{ ...viCharacter, values: { ...viCharacter.values, wounds: ['tired', 'hurt'] } }}
        sheet={withWounds}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'Vi' }))
    expect(dialog.getByText('Hurt, Tired')).toBeInTheDocument()
    expect(dialog.queryByRole('checkbox')).not.toBeInTheDocument()
    await user.keyboard('{Escape}')

    rerender(<CharacterSheetDialog character={viCharacter} sheet={withWounds} />)
    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))
    expect(within(await screen.findByRole('dialog')).getByText('None')).toBeInTheDocument()
  })

  it('lists the stacks of an inventory as Name ×n with the slots used, and nothing for a Character that never had the field', async () => {
    const user = userEvent.setup()
    const withPack = [...sheet, inventoryField]
    const { rerender } = render(
      <CharacterSheetDialog
        character={{
          ...viCharacter,
          values: {
            ...viCharacter.values,
            pack: [
              { gear: 'used-pistol', quantity: 2 },
              { name: 'Rope', quantity: 1, slots: 2 },
            ],
          },
        }}
        sheet={withPack}
        economy={economy}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))
    const pack = within(await screen.findByRole('dialog', { name: 'Vi' })).getByRole('group', {
      name: 'Pack',
    })
    expect(
      within(pack)
        .getAllByRole('listitem')
        .map((item) => item.textContent),
    ).toEqual(['Used pistol ×2', 'Rope'])
    expect(pack).toHaveTextContent('Slots: 4 / 10')
    await user.keyboard('{Escape}')

    rerender(<CharacterSheetDialog character={viCharacter} sheet={withPack} economy={economy} />)
    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))
    const empty = within(await screen.findByRole('dialog', { name: 'Vi' })).getByRole('group', {
      name: 'Pack',
    })
    expect(within(empty).queryAllByRole('listitem')).toHaveLength(0)
    expect(empty).toHaveTextContent('Slots: 0 / 10')
  })

  it('names gear by its key when the catalog is not known', async () => {
    const user = userEvent.setup()
    render(
      <CharacterSheetDialog
        character={{ ...viCharacter, values: { pack: [{ gear: 'used-pistol', quantity: 1 }] } }}
        sheet={[inventoryField]}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))

    expect(await screen.findByText('used-pistol')).toBeInTheDocument()
  })

  it('shows the unit of the currency after the money, and none after another number', async () => {
    const user = userEvent.setup()
    render(
      <CharacterSheetDialog
        character={{ ...viCharacter, values: { ...viCharacter.values, eddies: 120 } }}
        sheet={[...sheet, eddiesField]}
        economy={economy}
      />,
    )

    await user.click(screen.getByRole('button', { name: 'View sheet of Vi' }))

    const dialog = within(await screen.findByRole('dialog', { name: 'Vi' }))
    expect(dialog.getByText('120 eb')).toBeInTheDocument()
    expect(dialog.getByText('7')).toBeInTheDocument()
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
