import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { vi } from 'vitest'
import { atStep, guidedWithShop, promptStep, shopStep } from './fixtures'
import { ShopStepForm } from './ShopStepForm'
import type { Campaign } from '../campaigns/useCampaigns'

/** Narrows a fixture that may be absent. */
function fail(message: string): never {
  throw new Error(message)
}

const base = guidedWithShop(atStep(promptStep))
const shop = shopStep.shop ?? fail('shopStep has no shop')

/** The shop form for `campaign`, with the Character's money and the Pack as given. */
function renderForm(
  values: Record<string, unknown>,
  { moneyField }: { moneyField?: Partial<Campaign['pinnedRelease']['sheet'][number]> } = {},
) {
  const onComplete = vi.fn()
  const campaign: Campaign = {
    ...base,
    pinnedRelease: {
      ...base.pinnedRelease,
      sheet: base.pinnedRelease.sheet.map((field) =>
        field.key === 'eddies' ? { ...field, ...moneyField } : field,
      ),
    },
    characters: base.characters.map((character) => ({
      ...character,
      values: values as Campaign['characters'][number]['values'],
    })),
  }
  render(<ShopStepForm campaign={campaign} shop={shop} pending={false} onComplete={onComplete} />)
  return onComplete
}

describe('ShopStepForm balance rules', () => {
  it('lets a Character under the floor confirm a sale that ends higher than it began, as the server does', async () => {
    const user = userEvent.setup()
    renderForm({ eddies: -50, pack: [{ gear: 'used-pistol', quantity: 2 }] })

    await user.type(screen.getByLabelText('Sell Used pistol from Pack'), '1')

    expect(screen.getByText(/-25 eb/, { selector: 'dd' })).toBeVisible()
    expect(screen.getByRole('button', { name: 'Confirm' })).toBeEnabled()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('refuses a purchase that leaves the balance under the floor and lower than before', async () => {
    const user = userEvent.setup()
    renderForm({ eddies: -5 })

    await user.type(screen.getByLabelText('Buy Ration'), '1')

    expect(screen.getByRole('alert')).toHaveTextContent('Not enough Eddies')
    expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled()
  })

  it('reads a missing money value as the server does: the default, else 0 inside min..max', () => {
    renderForm({}, { moneyField: { default: null, min: 10, max: null } })

    // The server starts the field at 0 moved inside 10..: 10.
    expect(screen.getByText('Eddies now').nextSibling).toHaveTextContent('10 eb')
  })

  it('reads a missing money value as the field default when there is one', () => {
    renderForm({}, { moneyField: { default: 30 } })

    expect(screen.getByText('Eddies now').nextSibling).toHaveTextContent('30 eb')
  })

  it('gives each of two stacks of the same gear its own sell line', async () => {
    const user = userEvent.setup()
    const onComplete = renderForm({
      eddies: 100,
      pack: [
        { gear: 'used-pistol', quantity: 2 },
        { gear: 'used-pistol', quantity: 1 },
      ],
    })

    const boxes = screen.getAllByLabelText('Sell Used pistol from Pack')
    expect(boxes).toHaveLength(2)
    const [first, second] = boxes
    await user.type(first ?? fail('no sell line'), '1')
    expect(second).toHaveValue(null)
    await user.click(screen.getByRole('button', { name: 'Confirm' }))

    expect(onComplete).toHaveBeenCalledWith(
      expect.any(String),
      [],
      [{ field: 'pack', gear: 'used-pistol', quantity: 1 }],
    )
  })
})
