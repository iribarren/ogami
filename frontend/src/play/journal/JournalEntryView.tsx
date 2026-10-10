import { useId, type ReactNode } from 'react'

import { DiceGroups } from '../dice/DiceGroups'
import type {
  JournalEntry,
  LikelihoodContent,
  NoteContent,
  OracleTableContent,
  RollContent,
} from './useJournal'

type ChoiceContent = Extract<JournalEntry['content'], { kind: 'choice' }>

const recordedTime = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' })

const answerLabels: Record<LikelihoodContent['answer'], string> = {
  exceptional_yes: 'Exceptional yes',
  yes: 'Yes',
  no: 'No',
  exceptional_no: 'Exceptional no',
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
  }
}

/** One journal entry, rendered by kind, with the time it was recorded. */
export function JournalEntryView({ entry }: { entry: JournalEntry }) {
  return (
    <article
      aria-label={entryLabel(entry.content)}
      className="space-y-2 rounded-xl p-3 ring-1 ring-foreground/10"
    >
      <EntryContent content={entry.content} />
      <p className="text-xs text-muted-foreground">
        <time dateTime={entry.recordedAt}>{recordedTime.format(new Date(entry.recordedAt))}</time>
      </p>
    </article>
  )
}

function EntryContent({ content }: { content: JournalEntry['content'] }) {
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
  }
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
        <span aria-describedby={totalLabelId} className="text-2xl font-bold tabular-nums">
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
      <p className="text-2xl font-bold">{answerLabels[content.answer]}</p>
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
