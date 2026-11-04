import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { gripCheck } from '../campaigns/fixtures'
import { CheckForm } from './CheckForm'

/** A pool check that opts out of the situational modifier, so of extra dice. */
const sealedCheck = {
  ...gripCheck,
  key: 'sealed',
  label: 'Sealed',
  noSituationalModifier: true,
}

describe('CheckForm on a pool check that takes no situational modifier', () => {
  it('hides the Extra dice field', () => {
    render(<CheckForm checks={[sealedCheck]} pending={false} onSubmit={() => undefined} />)

    expect(screen.getByText('Pool: 4d6, success on 6+')).toBeInTheDocument()
    expect(screen.queryByLabelText('Extra dice')).not.toBeInTheDocument()
  })

  it('does not send the extra dice typed for another check', async () => {
    const user = userEvent.setup()
    const onSubmit = vi.fn()
    render(<CheckForm checks={[gripCheck, sealedCheck]} pending={false} onSubmit={onSubmit} />)

    await user.clear(screen.getByLabelText('Extra dice'))
    await user.type(screen.getByLabelText('Extra dice'), '2')
    await user.selectOptions(screen.getByLabelText('Check'), 'sealed')
    expect(screen.queryByLabelText('Extra dice')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Roll' }))

    expect(onSubmit).toHaveBeenCalledWith({ check: 'sealed' })
  })

  it('does not mention extra dice next to the size of the pool', async () => {
    const user = userEvent.setup()
    render(
      <CheckForm
        checks={[gripCheck, sealedCheck]}
        characters={[{ id: 'ada', name: 'Ada', poolSize: 6 }]}
        pending={false}
        onSubmit={() => undefined}
      />,
    )

    await user.clear(screen.getByLabelText('Extra dice'))
    await user.type(screen.getByLabelText('Extra dice'), '2')
    expect(screen.getByText('(before the extra dice)')).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText('Check'), 'sealed')

    expect(screen.getByText('Ada rolls 6 dice')).toBeInTheDocument()
    expect(screen.queryByText('(before the extra dice)')).not.toBeInTheDocument()
  })
})
