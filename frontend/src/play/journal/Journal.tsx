import { useMutationState } from '@tanstack/react-query'
import { useId, type ReactNode } from 'react'

import type { Campaign } from '../campaigns/useCampaigns'
import { groupJournal } from './groupJournal'
import { JournalEntryView } from './JournalEntryView'
import { recordEntryKey, type JournalEntry } from './useJournal'

/** Brings an entry just recorded into view, without moving focus (jsdom cannot scroll). */
function reveal(element: HTMLElement | null) {
  if (element !== null && 'scrollIntoView' in element) {
    element.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  }
}

/** The journal of a campaign, grouped by session and scene, each entry rendered by kind. */
export function Journal({
  campaignId,
  sessions,
  entries,
}: {
  campaignId: string
  sessions: Campaign['sessions']
  entries: JournalEntry[]
}) {
  const recorded = useMutationState({
    filters: { mutationKey: recordEntryKey(campaignId), status: 'success' },
    select: (mutation) => (mutation.state.data as JournalEntry | undefined)?.id,
  })
  const latestRecordedId = recorded.at(-1)
  const groups = groupJournal(sessions, entries)

  if (groups.length === 0) {
    return (
      <p className="text-muted-foreground">
        The journal is empty. Start a session, then a scene, to begin playing.
      </p>
    )
  }

  return (
    <div className="space-y-8">
      {groups.map((session) => (
        <Group key={session.number} level={3} title={`Session ${String(session.number)}`}>
          {session.scenes.length === 0 ? (
            <p className="text-sm text-muted-foreground">No scene in this session yet.</p>
          ) : (
            session.scenes.map((scene) => (
              <Group
                key={scene.number}
                level={4}
                title={`Scene ${String(scene.number)}${scene.title === null ? '' : ` — ${scene.title}`}`}
              >
                {scene.entries.length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    Nothing recorded in this scene yet.
                  </p>
                ) : (
                  <ol className="space-y-3">
                    {scene.entries.map((entry) => (
                      <li key={entry.id} ref={entry.id === latestRecordedId ? reveal : undefined}>
                        <JournalEntryView entry={entry} />
                      </li>
                    ))}
                  </ol>
                )}
              </Group>
            ))
          )}
        </Group>
      ))}
    </div>
  )
}

function Group({ level, title, children }: { level: 3 | 4; title: string; children: ReactNode }) {
  const headingId = useId()
  const Heading = level === 3 ? 'h3' : 'h4'
  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <Heading
        id={headingId}
        className={
          level === 3
            ? 'font-heading text-lg font-semibold'
            : 'text-sm font-semibold tracking-wide text-muted-foreground uppercase'
        }
      >
        {title}
      </Heading>
      {children}
    </section>
  )
}
