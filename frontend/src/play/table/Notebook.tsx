import type { ReactNode } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import type { Campaign } from '../campaigns/useCampaigns'
import { Journal } from '../journal/Journal'
import { NoteComposer } from '../journal/NoteComposer'
import { useJournal, type JournalEntry } from '../journal/useJournal'

interface Scene {
  session: number
  scene: number
}

/** The Journal's own props, from the campaign, for the sessions and entries it is given. */
function JournalOf({
  campaign,
  sessions,
  entries,
  current,
}: {
  campaign: Campaign
  sessions: Campaign['sessions']
  entries: JournalEntry[]
  current: Scene | null
}) {
  return (
    <Journal
      campaignId={campaign.id}
      sessions={sessions}
      sceneTypes={campaign.sceneTypes}
      tags={campaign.tags}
      sheet={campaign.pinnedRelease.sheet}
      trackers={campaign.trackers}
      npcs={campaign.npcs}
      characters={campaign.characters}
      oracleTables={campaign.oracleTables}
      current={current}
      entries={entries}
      currencyUnit={campaign.pinnedRelease.currency?.unit ?? null}
    />
  )
}

/**
 * What the notebook reads: the current scene first, then the rest of the journal, both through
 * the Play page's own `Journal`, which renders every entry.
 */
function NotebookPages({ campaign, current }: { campaign: Campaign; current: Scene | null }) {
  const journal = useJournal(campaign.id)
  if (journal.status === 'pending') {
    return <p className="text-muted-foreground">Loading the journal…</p>
  }
  if (journal.status === 'error') {
    return (
      <p role="alert" className="text-sm text-destructive">
        {journal.error.message}
      </p>
    )
  }
  const isCurrent = (session: number, scene: number) =>
    current?.session === session && current.scene === scene
  const now = campaign.sessions
    .filter(({ number }) => number === current?.session)
    .map((session) => ({
      ...session,
      scenes: session.scenes.filter(({ number }) => isCurrent(session.number, number)),
    }))
  const earlier = campaign.sessions.map((session) => ({
    ...session,
    scenes: session.scenes.filter(({ number }) => !isCurrent(session.number, number)),
  }))
  const entriesNow = journal.data.filter((e) => isCurrent(e.sessionNumber, e.sceneNumber))
  const entriesEarlier = journal.data.filter((e) => !isCurrent(e.sessionNumber, e.sceneNumber))

  return (
    <div className="space-y-8">
      {current !== null && (
        <Pages title="This scene">
          <JournalOf campaign={campaign} sessions={now} entries={entriesNow} current={current} />
        </Pages>
      )}
      {(current === null ||
        entriesEarlier.length > 0 ||
        earlier.some((s) => s.scenes.length > 0)) && (
        <Pages title={current === null ? 'The journal' : 'Earlier in the journal'}>
          <JournalOf
            campaign={campaign}
            sessions={earlier}
            entries={entriesEarlier}
            current={current}
          />
        </Pages>
      )}
    </div>
  )
}

function Pages({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section aria-label={title} className="space-y-3">
      <h2 className="font-heading text-section-title">{title}</h2>
      {children}
    </section>
  )
}

/**
 * The notebook on the table (D7 of play-tabletop): the journal to read and write in, in a dialog
 * that takes the whole screen (D3). The note box is tall and grows with the panel; the text keeps
 * the app's reading size.
 */
export function Notebook({ campaign }: { campaign: Campaign }) {
  const current: Scene | null =
    campaign.currentSessionNumber === null || campaign.currentSceneNumber === null
      ? null
      : { session: campaign.currentSessionNumber, scene: campaign.currentSceneNumber }

  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button type="button" variant="ghost" className="h-auto w-full py-2 @3xl:min-h-20">
          Open the notebook
        </Button>
      </DialogTrigger>
      <DialogContent className="inset-0 top-0 left-0 h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 content-start gap-4 rounded-none p-4 text-base md:p-6">
        <DialogHeader>
          <DialogTitle className="text-xl">Notebook</DialogTitle>
          <DialogDescription>
            Read the journal, this scene first, and write a note in the current scene.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-2 [&_textarea]:min-h-80">
          {current === null && (
            <p className="text-sm text-muted-foreground">Start a scene to write in the journal.</p>
          )}
          <NoteComposer campaignId={campaign.id} disabled={current === null} current={current} />
        </div>
        <NotebookPages campaign={campaign} current={current} />
        <DialogClose asChild>
          <Button type="button" variant="outline" className="justify-self-start">
            Close
          </Button>
        </DialogClose>
      </DialogContent>
    </Dialog>
  )
}
