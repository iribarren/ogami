import { useId, type ReactNode } from 'react'

import { Badge } from '@/shared/ui/badge'

import { DiceGroups } from '../dice/DiceGroups'
import type {
  CheckContent,
  FlowOutcomeContent,
  JournalEntry,
  LikelihoodContent,
  NoteContent,
  OracleTableContent,
  PickContent,
  RollContent,
  SheetChangeContent,
} from './useJournal'

type ChoiceContent = Extract<JournalEntry['content'], { kind: 'choice' }>

/** The release Tags (key and label) that name the keys a pick entry stores. */
type TagLabels = readonly { key: string; label: string }[]

/** The sheet fields (key and label) that name the fields a sheet change entry stores. */
type FieldLabels = readonly { key: string; label: string }[]

const recordedTime = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' })

const answerLabels: Record<LikelihoodContent['answer'], string> = {
  exceptional_yes: 'Exceptional yes',
  yes: 'Yes',
  no: 'No',
  exceptional_no: 'Exceptional no',
}

const kindLabels: Record<PickContent['of'], string> = {
  npc: 'NPC',
  thread: 'Thread',
  character: 'Character',
}

/** The accessible name of an entry: its kind and what it is about. */
function entryLabel(content: JournalEntry['content']) {
  switch (content.kind) {
    case 'note':
      return 'Note'
    case 'roll':
      return `Roll ${content.expression}`
    case 'oracle-table':
    case 'likelihood':
      return `Oracle ${content.oracleName}`
    case 'choice':
      return `Choice ${content.question}`
    case 'pick':
      return `Pick ${kindLabels[content.of]}`
    case 'check':
      return `Check ${content.checkLabel}`
    case 'sheet-change':
      return `Sheet ${content.characterName}`
    case 'flowOutcome':
      return 'Flow outcome'
  }
}

/** One journal entry, rendered by kind, with the time it was recorded. */
export function JournalEntryView({
  entry,
  tags = [],
  sheet = [],
}: {
  entry: JournalEntry
  /** The campaign's release Tags, so a pick shows labels instead of keys. */
  tags?: TagLabels
  /** The release's sheet fields, so a sheet change shows labels instead of keys. */
  sheet?: FieldLabels
}) {
  return (
    <article
      aria-label={entryLabel(entry.content)}
      className="space-y-2 rounded-xl p-3 shadow-card ring-1 ring-foreground/10"
    >
      <EntryContent content={entry.content} tags={tags} sheet={sheet} />
      <p className="text-xs text-muted-foreground">
        <time dateTime={entry.recordedAt}>{recordedTime.format(new Date(entry.recordedAt))}</time>
      </p>
    </article>
  )
}

function EntryContent({
  content,
  tags,
  sheet,
}: {
  content: JournalEntry['content']
  tags: TagLabels
  sheet: FieldLabels
}) {
  switch (content.kind) {
    case 'note':
      return <NoteView content={content} />
    case 'roll':
      return <RollView content={content} />
    case 'oracle-table':
      return <OracleTableView content={content} />
    case 'likelihood':
      return <LikelihoodView content={content} />
    case 'choice':
      return <ChoiceView content={content} />
    case 'pick':
      return <PickView content={content} tags={tags} />
    case 'check':
      return <CheckView content={content} />
    case 'sheet-change':
      return <SheetChangeView content={content} sheet={sheet} />
    case 'flowOutcome':
      return <FlowOutcomeView content={content} />
  }
}

/** What a Flow step's sheet Effects changed: one line per field, with the dice each rolled. */
function SheetChangeView({ content, sheet }: { content: SheetChangeContent; sheet: FieldLabels }) {
  return (
    <ul className="space-y-1 text-sm">
      {content.changes.map((change, index) => (
        <li key={index} className="tabular-nums">
          {content.characterName}:{' '}
          {sheet.find((field) => field.key === change.field)?.label ?? change.field} {change.before}{' '}
          → <strong>{change.after}</strong>
          {change.dice !== null && ` (${change.dice.expression}: ${String(change.dice.total)})`}
        </li>
      ))}
    </ul>
  )
}

/** What the Flow decided on its own, one muted line per note, in the order it decided. */
function FlowOutcomeView({ content }: { content: FlowOutcomeContent }) {
  return (
    <ul className="space-y-0.5 text-sm text-muted-foreground">
      {content.notes.map((note, index) => (
        <li key={index} className="tabular-nums">
          {flowNoteLine(note)}
        </li>
      ))}
    </ul>
  )
}

function flowNoteLine(note: FlowOutcomeContent['notes'][number]) {
  switch (note.kind) {
    case 'band':
      return note.label
    case 'tracker':
      return `${note.name} ${String(note.from)} → ${String(note.to)}`
    case 'switch':
      return `Scene Type: ${note.from} → ${note.to}`
    case 'nextScene':
      return `Next scene: ${note.sceneType}`
    case 'phaseEnd':
      return 'The phase ends after this scene'
  }
}

/** A check: who rolled which check, the band it landed in, then the dice and how the total adds up. */
function CheckView({ content }: { content: CheckContent }) {
  return (
    <>
      <p className="font-medium">
        {content.characterName} · {content.checkLabel}
      </p>
      <p className="flex flex-wrap items-baseline gap-2">
        <span className="sr-only">Outcome:</span>
        <span className="text-2xl font-bold">{content.bandLabel}</span>
      </p>
      {content.groups.length > 0 && (
        <div className="rounded-md bg-well p-2 shadow-well">
          <DiceGroups groups={content.groups} />
        </div>
      )}
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
        <Detail term="Dice">
          <span className="font-mono">{content.expression}</span> = {content.diceTotal}
        </Detail>
        {content.modifier !== 0 && <Detail term="Modifier">{signed(content.modifier)}</Detail>}
        {content.situational !== 0 && (
          <Detail term="Situational">{signed(content.situational)}</Detail>
        )}
        <Detail term="Total">
          <strong>{content.total}</strong>
          {content.difficulty !== null && <> vs {content.difficulty}</>}
        </Detail>
      </dl>
    </>
  )
}

/** A modifier with its sign, so a bonus and a penalty read alike. */
function signed(value: number) {
  return value > 0 ? `+${String(value)}` : String(value)
}

function PickView({ content, tags }: { content: PickContent; tags: TagLabels }) {
  const kind = kindLabels[content.of]
  const plural = content.picked.length > 1 ? 's' : ''
  return (
    <>
      <p>
        Picked {kind}
        {plural}:{' '}
        <strong>
          {content.picked.length === 0
            ? 'No match'
            : content.picked.map(({ name }) => name).join(', ')}
        </strong>
      </p>
      {content.tags.length > 0 && (
        <p className="text-sm text-muted-foreground">
          Tagged:{' '}
          {content.tags.map((key) => tags.find((tag) => tag.key === key)?.label ?? key).join(', ')}
        </p>
      )}
    </>
  )
}

function ChoiceView({ content }: { content: ChoiceContent }) {
  return (
    <p>
      {content.question} → <strong>{content.label}</strong>
    </p>
  )
}

function NoteView({ content }: { content: NoteContent }) {
  // Keeps the line breaks the player typed.
  return <p className="whitespace-pre-wrap">{content.text}</p>
}

function RollView({ content }: { content: RollContent }) {
  const totalLabelId = useId()
  return (
    <>
      <p className="flex flex-wrap items-baseline gap-2">
        <span className="font-mono text-sm text-muted-foreground">{content.expression}</span>
        <span aria-hidden="true" className="text-muted-foreground">
          =
        </span>
        <span id={totalLabelId} className="sr-only">
          total
        </span>
        <span
          aria-describedby={totalLabelId}
          className="rounded-md bg-well px-2 py-0.5 text-2xl font-bold tabular-nums shadow-well readout-glow"
        >
          {content.total}
        </span>
      </p>
      <DiceGroups groups={content.groups} />
    </>
  )
}

function OracleTableView({ content }: { content: OracleTableContent }) {
  return (
    <>
      <p className="font-medium">{content.oracleName}</p>
      <ol className="space-y-1" aria-label="Oracle table steps">
        {content.steps.map((step, depth) => (
          // Each step after the first rolls on the table its predecessor nests.
          <li
            key={depth}
            className="flex flex-wrap items-baseline gap-x-2 text-sm"
            style={{ paddingLeft: `${String(depth * 1.25)}rem` }}
          >
            {depth > 0 && (
              <>
                <span aria-hidden="true" className="text-muted-foreground">
                  ↳
                </span>
                <span className="sr-only">Nested roll:</span>
              </>
            )}
            <span className="font-medium">{step.tableName}</span>
            <span className="font-mono text-muted-foreground">{step.dice}</span>
            <span className="text-muted-foreground tabular-nums">= {step.total}</span>
            {step.text !== '' && <span className="font-semibold">{step.text}</span>}
          </li>
        ))}
      </ol>
    </>
  )
}

function LikelihoodView({ content }: { content: LikelihoodContent }) {
  return (
    <>
      <p className="font-medium">{content.oracleName}</p>
      {content.question !== null && <p className="italic">“{content.question}”</p>}
      <p className="flex flex-wrap items-center gap-2">
        <span className="rounded-md bg-well px-2 py-0.5 text-2xl font-bold shadow-well readout-glow">
          {answerLabels[content.answer]}
        </span>
        {content.randomEvent !== null && <Badge variant="warning">Random event</Badge>}
      </p>
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
        <Detail term="Roll">
          {content.roll} vs {content.effectiveTarget} on d{content.sides}
        </Detail>
        <Detail term="Likelihood">{content.likelihoodLabel}</Detail>
        {content.chaosFactor !== null && <Detail term="Chaos factor">{content.chaosFactor}</Detail>}
      </dl>
    </>
  )
}

function Detail({ term, children }: { term: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-muted-foreground">{term}</dt>
      <dd className="tabular-nums">{children}</dd>
    </>
  )
}
