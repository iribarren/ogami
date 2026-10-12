import type { Campaign } from '../campaigns/useCampaigns'
import { guidedWithLists, atStep, promptStep } from '../flow/fixtures'
import { brannNpc, lostMineWithLists, miraNpc } from '../lists/fixtures'

// Shared by the Scene cast tests and stories.

const ref = ({ id, name }: { id: string; name: string }) => ({ id, name })

/** `campaign` with the casts of its scenes replaced: session 1 scene 1 (past) and session 2 scene 1 (current). */
export function withCasts(
  campaign: Campaign,
  past: { id: string; name: string }[],
  current: { id: string; name: string }[],
): Campaign {
  return {
    ...campaign,
    sessions: campaign.sessions.map((session) => ({
      ...session,
      scenes: session.scenes.map((scene) => ({
        ...scene,
        cast: session.number === 1 ? past : current,
      })),
    })),
  }
}

/** Lists in the campaign; Mira in the cast of the past scene, Brann in the current one. */
export const castCampaign = withCasts(lostMineWithLists, [ref(miraNpc)], [ref(brannNpc)])

/** The same, played along a Flow, in focus mode. */
export const castFocus = withCasts(
  guidedWithLists(atStep(promptStep), 'focus'),
  [ref(miraNpc)],
  [ref(brannNpc)],
)
