import { edgeCheck, fulfillCheck, gritCheck, persuadeCheck } from '../campaigns/fixtures'
import { nightJobCampaign } from '../characters/fixtures'
import {
  bestialFailureEntry,
  burnableRollEntry,
  burnedEntry,
  gritEntry,
  recordedEntry,
  hungerOnlyFailedEntry,
  pushedBestialEntry,
  pushedGritEntry,
  skillCheckEntry,
  vowProgressEntry,
} from '../journal/fixtures'
import { burnScore, canBurn, canPush, diceBeatenBy } from './pushing'

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

/** The roll of "Edge" with the momentum it had when rolled (dice 3 and 7, score 6, cap 10). */
function rolledWith(momentum: number | null, over: { scoreMax?: number | null } = {}) {
  const { content } = burnableRollEntry
  if (content.kind !== 'check' || content.challenge === null) {
    throw new Error('The fixture is no challenge roll.')
  }
  return recordedEntry({
    ...content,
    challenge: { ...content.challenge, momentum, scoreMax: over.scoreMax ?? 10 },
  })
}

const edgeCampaign = { ...nightJobCampaign, checks: [edgeCheck, fulfillCheck] }

describe('burnScore and diceBeatenBy', () => {
  it('caps the momentum at scoreMax when the check has one', () => {
    expect(burnScore(8, 10)).toBe(8)
    expect(burnScore(12, 10)).toBe(10)
    expect(burnScore(12, null)).toBe(12)
  })

  it('counts the dice strictly below the score: ties go to the dice', () => {
    const dice = [{ face: 3 }, { face: 7 }]
    expect(diceBeatenBy(dice, 7)).toBe(1)
    expect(diceBeatenBy(dice, 8)).toBe(2)
    expect(diceBeatenBy(dice, 0)).toBe(0)
  })
})

describe('canBurn', () => {
  it('offers a burn when the momentum beats more dice than the score did', () => {
    expect(canBurn(edgeCampaign, burnableRollEntry)).toBe(true)
    expect(canPush(edgeCampaign, burnableRollEntry)).toBe(true)
  })

  it('offers nothing when the momentum beats no more dice than the score did', () => {
    // The 7 is a tie at momentum 7, and the 3 is beaten already.
    expect(canBurn(edgeCampaign, rolledWith(7))).toBe(false)
    expect(canBurn(edgeCampaign, rolledWith(6))).toBe(false)
  })

  it('offers nothing for a negative or zero momentum', () => {
    expect(canBurn(edgeCampaign, rolledWith(-2))).toBe(false)
    expect(canBurn(edgeCampaign, rolledWith(0))).toBe(false)
    expect(canPush(edgeCampaign, rolledWith(0))).toBe(false)
  })

  it('offers nothing when the cap makes the momentum no better', () => {
    // A momentum of 10 capped at 6 beats what the score of 6 beat.
    expect(canBurn(edgeCampaign, rolledWith(10, { scoreMax: 6 }))).toBe(false)
    expect(canBurn(edgeCampaign, rolledWith(10))).toBe(true)
  })

  it('offers nothing for a roll burned already', () => {
    expect(canBurn(edgeCampaign, burnedEntry)).toBe(false)
  })

  it('offers nothing when the check has no push or names no momentum', () => {
    const noPush = { ...nightJobCampaign, checks: [{ ...edgeCheck, push: null }] }
    const challenge = { count: 2, sides: 10, scoreMax: 10, momentum: null, momentumLabel: null }
    const noMomentum = { ...nightJobCampaign, checks: [{ ...edgeCheck, challenge }] }
    expect(canBurn(noPush, burnableRollEntry)).toBe(false)
    expect(canBurn(noMomentum, burnableRollEntry)).toBe(false)
    expect(canBurn({ ...nightJobCampaign, checks: [] }, burnableRollEntry)).toBe(false)
  })

  it('offers nothing when the pinned check changed the shape of the challenge since the roll', () => {
    const challenge = {
      count: 2,
      sides: 10,
      scoreMax: 10,
      momentumLabel: 'Momentum',
      momentum: 'momentum',
    }
    const pinned = (over: { count?: number; sides?: number; scoreMax?: number | null }) => ({
      ...nightJobCampaign,
      checks: [{ ...edgeCheck, challenge: { ...challenge, ...over } }],
    })
    // The roll holds two d10 and a cap of 10.
    expect(canBurn(pinned({}), burnableRollEntry)).toBe(true)
    expect(canBurn(pinned({ count: 3 }), burnableRollEntry)).toBe(false)
    expect(canBurn(pinned({ sides: 8 }), burnableRollEntry)).toBe(false)
    expect(canBurn(pinned({ scoreMax: 8 }), burnableRollEntry)).toBe(false)
    expect(canBurn(pinned({ scoreMax: null }), burnableRollEntry)).toBe(false)
  })

  it('offers nothing when the roll recorded no momentum, for a progress roll or a pool roll', () => {
    expect(canBurn(edgeCampaign, rolledWith(null))).toBe(false)
    expect(canBurn(edgeCampaign, vowProgressEntry)).toBe(false)
    expect(canBurn(edgeCampaign, gritEntry)).toBe(false)
    expect(canBurn(edgeCampaign, skillCheckEntry)).toBe(false)
  })
})
