import { useMutationState } from '@tanstack/react-query'
import { useId, type ReactNode } from 'react'

import { Button } from '@/shared/ui/button'

import { SceneCast } from '../cast/SceneCast'
import { useSwitchSceneType, type Campaign } from '../campaigns/useCampaigns'
import { groupJournal } from './groupJournal'
import { JournalEntryView } from './JournalEntryView'
import { RandomEventOffer } from './RandomEventOffer'
import { titleAddsToType } from './sceneLabels'
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
  sceneTypes,
  tags,
  npcs,
  characters,
  oracleTables,
  current,
  entries,
}: {
  campaignId: string
  sessions: Campaign['sessions']
  sceneTypes: Campaign['sceneTypes']
  /** The release Tags, which name the keys of a pick entry. */
  tags: Campaign['tags']
  /** The campaign's NPCs, which the cast of the current scene draws from. */
  npcs: Campaign['npcs']
  /** The campaign's Characters, which the cast of the current scene also draws from. */
  characters: Campaign['characters']
  /** The release's oracle tables, which name the table a random event rolls. */
  oracleTables: Campaign['oracleTables']
  /** The scene play is in, which is the only one a rolled Scene Type can be switched in. */
  current: { session: number; scene: number } | null
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
                title={sceneHeading(scene, listed(sessions, session.number, scene.number))}
                hook={listed(sessions, session.number, scene.number)?.kind === 'hook'}
              >
                <SceneCastHeader
                  campaignId={campaignId}
                  npcs={npcs}
                  characters={characters}
                  sessionNumber={session.number}
                  scene={listed(sessions, session.number, scene.number)}
                  editable={current?.session === session.number && current.scene === scene.number}
                />
                {scene.entries.length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    Nothing recorded in this scene yet.
                  </p>
                ) : (
                  <ol className="space-y-3">
                    {scene.entries.map((entry) => (
                      <li
                        key={entry.id}
                        ref={entry.id === latestRecordedId ? reveal : undefined}
                        className="space-y-1"
                      >
                        {entry.flowStep !== null && (
                          <p className="text-xs text-muted-foreground">
                            Flow step · {entry.flowStep.title}
                          </p>
                        )}
                        <JournalEntryView entry={entry} tags={tags} />
                        {current?.session === session.number && current.scene === scene.number && (
                          <>
                            <SceneTypeOffer
                              campaignId={campaignId}
                              entry={entry}
                              scene={listed(sessions, session.number, scene.number)}
                              sceneTypes={sceneTypes}
                            />
                            <RandomEventOffer
                              campaignId={campaignId}
                              entry={entry}
                              tables={oracleTables}
                            />
                          </>
                        )}
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

/**
 * "Switch to <Scene Type>" for the Scene Type the latest step of a rolled oracle table offers,
 * unless the scene already has it. Only offered in the current scene (a hook Scene has no type).
 */
function SceneTypeOffer({
  campaignId,
  entry,
  scene,
  sceneTypes,
}: {
  campaignId: string
  entry: JournalEntry
  scene: ListedScene | undefined
  sceneTypes: Campaign['sceneTypes']
}) {
  const switchSceneType = useSwitchSceneType(campaignId)
  if (entry.content.kind !== 'oracle-table' || scene?.kind !== 'scene') {
    return null
  }
  const offered = entry.content.steps.findLast((step) => step.sceneType !== null)?.sceneType
  const target = sceneTypes.find(({ key }) => key === offered)
  if (target === undefined || target.key === scene.sceneType) {
    return null
  }

  return (
    <div className="space-y-1">
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={switchSceneType.isPending}
        onClick={() => {
          switchSceneType.mutate(target.key)
        }}
      >
        Switch to {target.name}
      </Button>
      {switchSceneType.error && (
        <p role="alert" className="text-sm text-destructive">
          {switchSceneType.error.message}
        </p>
      )}
    </div>
  )
}

/** The cast of a scene under its heading; nothing for a scene the campaign does not list. */
function SceneCastHeader({
  scene,
  ...props
}: { scene: ListedScene | undefined } & Omit<Parameters<typeof SceneCast>[0], 'scene'>) {
  return scene === undefined ? null : <SceneCast scene={scene} {...props} />
}

type ListedScene = Campaign['sessions'][number]['scenes'][number]

/** The scene as the campaign lists it, which tells a hook Scene and a Scene Type. */
function listed(sessions: Campaign['sessions'], sessionNumber: number, sceneNumber: number) {
  return sessions
    .find(({ number }) => number === sessionNumber)
    ?.scenes.find(({ number }) => number === sceneNumber)
}

const hookLabels: Record<NonNullable<ListedScene['hook']>, string> = {
  sessionOpening: 'Session opening',
  sessionClosing: 'Session closing',
  phaseOpening: 'Phase opening',
  phaseClosing: 'Phase closing',
  worldTurn: 'World turn',
}

/** "Scene 2 · Tour — Who walks in" (the title left out when it only repeats "Tour"), or "Session opening — Session 1 begins" for a hook Scene. */
function sceneHeading(scene: { number: number; title: string | null }, listedScene?: ListedScene) {
  const typeName = listedScene?.sceneTypeName ?? null
  // A title that only repeats the Scene Type (its numbered default) adds nothing.
  const title =
    scene.title === null || !titleAddsToType(scene.title, typeName) ? '' : ` — ${scene.title}`
  if (listedScene?.hook) {
    return `${hookLabels[listedScene.hook]}${title}`
  }
  const type = typeName ? ` · ${typeName}` : ''
  return `Scene ${String(scene.number)}${type}${title}`
}

function Group({
  level,
  title,
  hook = false,
  children,
}: {
  level: 3 | 4
  title: string
  /** A hook Scene's heading is set apart from a scene's. */
  hook?: boolean
  children: ReactNode
}) {
  const headingId = useId()
  const Heading = level === 3 ? 'h3' : 'h4'
  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <Heading
        id={headingId}
        className={
          level === 3
            ? 'font-heading text-lg font-semibold'
            : hook
              ? 'text-sm font-semibold tracking-wide text-primary uppercase'
              : 'text-sm font-semibold tracking-wide text-muted-foreground uppercase'
        }
      >
        {title}
      </Heading>
      {children}
    </section>
  )
}
