import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'

import { BandsEditor } from './BandsEditor'
import type { BandDraft } from './checkDraft'

/** Every band row draws its inputs through this one, so the inputs drawn count the rows that rendered. */
const drawn = vi.hoisted(() => ({ inputs: 0 }))

vi.mock('@/shared/ui/input', async (importOriginal) => {
  const { Input: Real } = await importOriginal<typeof import('@/shared/ui/input')>()
  return {
    Input: (props: React.ComponentProps<'input'>) => {
      drawn.inputs += 1
      return <Real {...props} />
    },
  }
})

const catalog = { fields: [], trackers: [] }
const noErrors = {}

const bands: BandDraft[] = ['one', 'two', 'three', 'four', 'five'].map((key, at) => ({
  key,
  label: `Band ${key}`,
  upTo: at === 4 ? { kind: 'none' } : { kind: 'number', value: String((at + 1) * 3) },
}))

function Harness() {
  const [held, setHeld] = useState(bands)
  return (
    <>
      <BandsEditor
        bands={held}
        onChange={setHeld}
        catalog={catalog}
        difficulty={null}
        copyFrom={[]}
        errors={noErrors}
      />
      <output aria-label="Keys">{held.map((band) => band.key).join('|')}</output>
    </>
  )
}

const label = (number: number) =>
  screen.getByRole('textbox', { name: `Band ${String(number)} label` })

describe('the band rows', () => {
  beforeEach(() => {
    drawn.inputs = 0
  })

  it('renders only the edited row when one band changes', () => {
    render(<Harness />)
    const row = label(1).closest('li')
    if (row === null) {
      throw new Error('No row for band 1.')
    }
    const inputs = within(row).getAllByRole('textbox').length
    drawn.inputs = 0

    fireEvent.change(label(1), { target: { value: 'Changed' } })

    expect(label(1)).toHaveValue('Changed')
    expect(drawn.inputs).toBe(inputs)
  })

  it('keeps both of two quick edits on different rows', () => {
    render(<Harness />)

    fireEvent.change(label(2), { target: { value: 'Second' } })
    fireEvent.change(label(4), { target: { value: 'Fourth' } })
    fireEvent.change(label(2), { target: { value: 'Second again' } })

    expect(label(2)).toHaveValue('Second again')
    expect(label(4)).toHaveValue('Fourth')
    expect(label(3)).toHaveValue('Band three')
  })

  it('moves and removes the band of the row, whatever else was edited before', async () => {
    const user = userEvent.setup()
    render(<Harness />)

    fireEvent.change(label(1), { target: { value: 'First edited' } })
    await user.click(screen.getByRole('button', { name: 'Move up band 3' }))
    expect(screen.getByLabelText('Keys')).toHaveTextContent('one|three|two|four|five')
    await user.click(screen.getByRole('button', { name: 'Remove band 1' }))

    expect(screen.getByLabelText('Keys')).toHaveTextContent('three|two|four|five')
  })
})
