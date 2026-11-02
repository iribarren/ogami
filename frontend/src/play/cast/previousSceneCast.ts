import type { Campaign } from '../campaigns/useCampaigns'

/**
 * The NPCs in the cast of the Scene before this one: the nearest earlier Scene of the session, or
 * the last of an earlier session when it is the session's first. A hook Scene is skipped, as it
 * has no cast of its own. Undefined when there is no earlier Scene.
 */
export function previousSceneCast(
  sessions: Campaign['sessions'],
  sessionNumber: number,
  sceneNumber: number,
) {
  const earlier = sessions
    .filter(({ number }) => number <= sessionNumber)
    .toSorted((a, b) => b.number - a.number)
    .flatMap((session) =>
      session.scenes
        .filter(
          ({ number, hook }) =>
            hook === null && (session.number < sessionNumber || number < sceneNumber),
        )
        .toSorted((a, b) => b.number - a.number),
    )
  return earlier[0]?.cast
}
