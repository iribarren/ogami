import { describe, expect, it } from 'vitest'

import { campaignError, explainedRefusals } from './useCampaigns'

describe('campaignError', () => {
  it('carries the creation error code of a 422 that has one', () => {
    const error = campaignError(
      new Response(null, { status: 422 }),
      { error: 'Spend at most 460 points.', code: 'point_budget_exceeded' },
      explainedRefusals,
      'Adding the Character',
    )

    expect(error.message).toBe('Spend at most 460 points.')
    expect(error.status).toBe(422)
    expect(error.code).toBe('point_budget_exceeded')
  })

  it('has no code for a 422 without one', () => {
    const error = campaignError(
      new Response(null, { status: 422 }),
      { error: 'The name is required.' },
      explainedRefusals,
      'Adding the Character',
    )

    expect(error.message).toBe('The name is required.')
    expect(error.code).toBeUndefined()
  })

  it('has no code for a null one or a status the API does not explain', () => {
    expect(
      campaignError(
        new Response(null, { status: 422 }),
        { error: 'Nope.', code: null },
        explainedRefusals,
        'Adding the Character',
      ).code,
    ).toBeUndefined()
    expect(
      campaignError(
        new Response(null, { status: 500 }),
        { error: 'Boom.', code: 'point_budget_exceeded' },
        explainedRefusals,
        'Adding the Character',
      ).code,
    ).toBeUndefined()
  })
})
