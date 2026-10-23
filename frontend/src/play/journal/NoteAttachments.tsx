import type { Campaign } from '../campaigns/useCampaigns'
import { attachedTo } from './attachments'
import { JournalEntryView } from './JournalEntryView'
import { RandomEventOffer } from './RandomEventOffer'
import { SceneTypeOffer, type ListedScene } from './SceneTypeOffer'
import type { JournalEntry } from './useJournal'

/**
 * The results a note attaches, shown inside the note in the order the note lists them. An
 * attached Likelihood answer keeps its random event under it, as it has in the journal, and an
 * attached oracle table result still offers its Scene Type in the current scene.
 */
export function NoteAttachments({
  campaignId,
  note,
  entries,
  tables,
  tags,
  sheet,
  current,
  scene,
  sceneTypes,
}: {
  campaignId: string
  note: JournalEntry
  /** The whole journal, which holds the attached entries and the random events. */
  entries: readonly JournalEntry[]
  tables: Campaign['oracleTables']
  tags: Campaign['tags']
  sheet?: Campaign['pinnedRelease']['sheet']
  /** Whether the note is in the current scene, where a random event can still be rolled. */
  current: boolean
  /** The scene the note is in; with `sceneTypes`, an attached table result offers its Scene Type. */
  scene?: ListedScene
  sceneTypes?: Campaign['sceneTypes']
}) {
  const attached = attachedTo(entries, note)
  if (attached.length === 0) {
    return null
  }

  return (
    <ul aria-label="Attached results" className="space-y-2">
      {attached.map((entry) => (
        <li key={entry.id} className="space-y-2">
          <JournalEntryView entry={entry} tags={tags} sheet={sheet} />
          {current && sceneTypes !== undefined && (
            <SceneTypeOffer
              campaignId={campaignId}
              entry={entry}
              scene={scene}
              sceneTypes={sceneTypes}
            />
          )}
          <RandomEventOffer
            campaignId={campaignId}
            entry={entry}
            entries={entries}
            tables={tables}
            tags={tags}
            sheet={sheet}
            current={current}
          />
        </li>
      ))}
    </ul>
  )
}
