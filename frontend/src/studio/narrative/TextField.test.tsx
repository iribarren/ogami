import { render, screen } from '@testing-library/react'

import { TextField } from './TextField'

describe('TextField', () => {
  it('describes a key field by the key hint, then its own help, then its error', () => {
    render(
      <TextField
        label="Key"
        keyField
        help="Renaming is refused."
        error="Key is required."
        value=""
        onChange={() => undefined}
      />,
    )

    const input = screen.getByLabelText('Key')
    expect(input).toHaveAccessibleDescription(
      'Lower case letters, digits and hyphens. Renaming is refused. Key is required.',
    )
    expect(input).toBeInvalid()
  })

  it('has no description when it has nothing to say', () => {
    render(<TextField label="Name" value="" onChange={() => undefined} />)

    expect(screen.getByLabelText('Name')).toHaveAccessibleDescription('')
  })
})
