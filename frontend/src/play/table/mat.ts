import type { Campaign } from '../campaigns/useCampaigns'

type Session = Campaign['sessions'][number]
type Scene = Session['scenes'][number]

/** A scene card on the table: what identifies it, nothing more (D3 of play-tabletop). */
export interface MatCard {
  sessionNumber: number
  /** Numbered from 1 within its session. */
  number: number
  title: string
  /** The name of its Scene Type, its key when the name is missing, null for a scene without one. */
  sceneType: string | null
  /** A hook Scene: drawn smaller than a scene of play. */
  hook: boolean
  /** The scene the campaign is in now: larger and face up. */
  current: boolean
}

/** The scenes of an earlier session, stacked. `cards` are in play order, the last one on top. */
export interface MatPile {
  sessionNumber: number
  sceneCount: number
  top: MatCard
  cards: MatCard[]
}

export interface Mat {
  /** This session's scenes in play order; empty between sessions or before the first scene. */
  row: MatCard[]
  /** Earlier sessions, oldest first. */
  piles: MatPile[]
}

function cardOf(session: number, scene: Scene, current: boolean): MatCard {
  return {
    sessionNumber: session,
    number: scene.number,
    title: scene.title,
    // Legacy scenes have no Scene Type, or a key whose name the release no longer gives.
    sceneType: scene.sceneTypeName ?? scene.sceneType ?? null,
    hook: scene.kind === 'hook',
    current,
  }
}

const inPlayOrder = (scenes: Scene[]) => [...scenes].sort((a, b) => a.number - b.number)

/**
 * The mat of a campaign, derived from its sessions and where it stands: nothing about the table is
 * stored (ADR 0054). The session under way is a row; every other session that has a scene is a
 * pile. Between sessions (none under way, or the flow waiting for the next one) there is no row.
 */
export function matOf(campaign: Campaign): Mat {
  const waiting = campaign.flowRun?.waitsForSession === true
  const under = waiting ? null : campaign.currentSessionNumber
  const row: MatCard[] = []
  const piles: MatPile[] = []

  for (const session of [...campaign.sessions].sort((a, b) => a.number - b.number)) {
    const scenes = inPlayOrder(session.scenes)
    if (session.number === under) {
      row.push(
        ...scenes.map((scene) =>
          cardOf(session.number, scene, scene.number === campaign.currentSceneNumber),
        ),
      )
      continue
    }
    const cards = scenes.map((scene) => cardOf(session.number, scene, false))
    const top = cards.at(-1)
    if (top !== undefined) {
      piles.push({ sessionNumber: session.number, sceneCount: cards.length, top, cards })
    }
  }

  return { row, piles }
}
