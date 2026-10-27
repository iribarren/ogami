import { gritCheck, persuadeCheck } from '../campaigns/fixtures'
import { nightJobCampaign } from '../characters/fixtures'
import {
  bestialFailureEntry,
  gritEntry,
  hungerOnlyFailedEntry,
  pushedBestialEntry,
  pushedGritEntry,
  skillCheckEntry,
} from '../journal/fixtures'
import { canPush } from './pushing'

const campaign = { ...nightJobCampaign, checks: [gritCheck, persuadeCheck] }

describe('canPush', () => {
  it('offers a push for a roll with a failed regular die of a check that declares one', () => {
    expect(canPush(campaign, gritEntry)).toBe(true)
    expect(canPush(campaign, bestialFailureEntry)).toBe(true)
  })

  it('does not count failed Hunger dice: they are never re-rolled', () => {
    // Two Hunger dice failed (below the successes of the pool), no regular die did.
    expect(canPush(campaign, hungerOnlyFailedEntry)).toBe(false)
  })

  it('offers nothing for a roll pushed already, a check without a push or a roll without a pool', () => {
    expect(canPush(campaign, pushedGritEntry)).toBe(false)
    expect(canPush(campaign, pushedBestialEntry)).toBe(false)
    expect(canPush({ ...campaign, checks: [{ ...gritCheck, push: null }] }, gritEntry)).toBe(false)
    expect(canPush(campaign, skillCheckEntry)).toBe(false)
  })
})
