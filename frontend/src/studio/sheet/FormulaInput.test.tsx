import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'

import { FormulaInput } from './FormulaInput'
import type { FormulaDiagnostics } from './formulaDiagnostics'
import { chooseOption } from '@/test/chooseOption'

const references = [
  { reference: 'pow', label: 'Power' },
  { reference: 'hp.max', label: 'Hit points max' },
  { reference: 'tracker:heat', label: 'Tracker Heat' },
]

function Harness({
  initial = '',
  diagnostics = null,
  error,
}: {
  initial?: string
  diagnostics?: FormulaDiagnostics | null
  error?: string
}) {
  const [value, setValue] = useState(initial)
  return (
    <FormulaInput
      label="Formula"
      value={value}
      onChange={setValue}
      diagnostics={diagnostics}
      references={references}
      error={error}
    />
  )
}

describe('the formula input', () => {
  it('marks the offending character, counting from the first non-blank one', () => {
    render(
      <Harness
        initial="  1 + * 2"
        diagnostics={{ error: { message: 'unexpected "*"', position: 5 }, result: null }}
      />,
    )

    expect(screen.getByText('*', { selector: 'mark' })).toBeInTheDocument()
    expect(screen.getByLabelText('Formula')).toHaveAccessibleDescription('unexpected "*"')
    expect(screen.getByLabelText('Formula')).toHaveAttribute('aria-invalid', 'true')
  })

  it('shows only the message when the error has no position, or one outside the text', () => {
    const { rerender } = render(
      <FormulaInput
        label="Formula"
        value="1 +"
        onChange={() => undefined}
        diagnostics={{ error: { message: 'cycle' }, result: null }}
        references={[]}
      />,
    )
    expect(screen.getByText('cycle')).toBeVisible()
    expect(document.querySelector('mark')).toBeNull()

    rerender(
      <FormulaInput
        label="Formula"
        value="1 +"
        onChange={() => undefined}
        diagnostics={{ error: { message: 'cycle', position: 99 }, result: null }}
        references={[]}
      />,
    )
    expect(document.querySelector('mark')).toBeNull()
  })

  it('shows the value over the sample values, or why there is none', () => {
    const { rerender } = render(<Harness diagnostics={{ error: null, result: { value: 14 } }} />)
    expect(screen.getByText('Value with the sample values: 14')).toBeVisible()

    rerender(<Harness diagnostics={{ error: null, result: { reason: 'division by zero' } }} />)
    expect(screen.getByText('No value with the sample values: division by zero')).toBeVisible()
  })

  it('shows the answer of the server on the input', () => {
    render(<Harness error='unknown field "x"' />)

    expect(screen.getByText('unknown field "x"')).toBeVisible()
    expect(screen.getByLabelText('Formula')).toHaveAttribute('aria-invalid', 'true')
  })

  it('inserts a reference at the caret and focuses the input after it', async () => {
    const user = userEvent.setup()
    render(<Harness initial="1 + 2" />)
    const input = screen.getByLabelText<HTMLInputElement>('Formula')

    input.focus()
    input.setSelectionRange(4, 4)
    await chooseOption(user, 'Insert reference', 'hp.max')

    expect(input).toHaveValue('1 + {hp.max}2')
    expect(input).toHaveFocus()
    expect(input.selectionStart).toBe('1 + {hp.max}'.length)
  })

  it('replaces the selection with the reference', async () => {
    const user = userEvent.setup()
    render(<Harness initial="1 + xx" />)
    const input = screen.getByLabelText<HTMLInputElement>('Formula')

    input.focus()
    input.setSelectionRange(4, 6)
    await chooseOption(user, 'Insert reference', 'tracker:heat')

    expect(input).toHaveValue('1 + {tracker:heat}')
  })

  it('offers each reference by label and key', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    await user.click(screen.getByRole('combobox', { name: 'Insert reference' }))

    expect(screen.getByRole('option', { name: 'Power {pow}' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Tracker Heat {tracker:heat}' })).toBeInTheDocument()
  })
})
