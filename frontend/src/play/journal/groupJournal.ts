import type { Campaign } from '../campaigns/useCampaigns'
import type { JournalEntry } from './useJournal'

export interface SceneGroup {
  number: number
  /** Null only for a scene the campaign does not list (yet), e.g. from a stale campaign. */
  title: string | null
  entries: JournalEntry[]
}

export interface SessionGroup {
  number: number
  scenes: SceneGroup[]
}

/**
 * Groups the journal by session, then scene, in number order. Every session and scene of
 * the campaign is present, even without entries; entries keep their recorded order.
 */
export function groupJournal(
  sessions: Campaign['sessions'],
  entries: JournalEntry[],
): SessionGroup[] {
  const groups = new Map<number, Map<number, SceneGroup>>()

  function sceneGroup(sessionNumber: number, sceneNumber: number, title: string | null) {
    let scenes = groups.get(sessionNumber)
    if (scenes === undefined) {
      scenes = new Map()
      groups.set(sessionNumber, scenes)
    }
    let scene = scenes.get(sceneNumber)
    if (scene === undefined) {
      scene = { number: sceneNumber, title, entries: [] }
      scenes.set(sceneNumber, scene)
    }
    return scene
  }

  for (const session of sessions) {
    if (!groups.has(session.number)) {
      groups.set(session.number, new Map())
    }
    for (const scene of session.scenes) {
      sceneGroup(session.number, scene.number, scene.title)
    }
  }
  for (const entry of entries) {
    sceneGroup(entry.sessionNumber, entry.sceneNumber, null).entries.push(entry)
  }

  const byNumber = (a: { number: number }, b: { number: number }) => a.number - b.number
  return [...groups.entries()]
    .map(([number, scenes]) => ({ number, scenes: [...scenes.values()].sort(byNumber) }))
    .sort(byNumber)
}
