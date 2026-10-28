import { ClipboardList, Eye, Feather, Flag, Split, Users } from 'lucide-react'
import { useId, type ReactNode } from 'react'

import { cn } from '@/shared/lib/utils'
import { Badge } from '@/shared/ui/badge'
import { IconTile } from '@/shared/ui/icon-tile'

import { DiceGroups } from '../dice/DiceGroups'
import { specialResultLabels } from './specialResults'
import { DieShape } from '@/shared/ui/die-shape'
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
  ShopContent,
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
    case 'shop':
      return `Shop ${content.characterName}`
    case 'flowOutcome':
      return 'Flow outcome'
  }
}

/** The icon of an entry's tile: dice for rolls and checks, an eye for oracles, a quill for notes. */
function entryIcon(content: JournalEntry['content']) {
  switch (content.kind) {
    case 'note':
      return <Feather />
    case 'roll':
    case 'check':
      return <DieShape sides={20} inner className="text-current" />
    case 'oracle-table':
    case 'likelihood':
      return <Eye />
    case 'choice':
      return <Split />
    case 'pick':
      return <Users />
    case 'sheet-change':
    case 'shop':
      return <ClipboardList />
    case 'flowOutcome':
      return <Flag />
  }
}

/** One journal entry, rendered by kind, with the time it was recorded. */
export function JournalEntryView({
  entry,
  tags = [],
  sheet = [],
  currencyUnit = null,
  children,
}: {
  entry: JournalEntry
  /** The campaign's release Tags, so a pick shows labels instead of keys. */
  tags?: TagLabels
  /** The release's sheet fields, so a sheet change shows labels instead of keys. */
  sheet?: FieldLabels
  /**
   * The unit of the release's currency ("eb"), which a shop entry shows after the money. The entry
   * does not carry it (it records amounts); the pinned release does. None: the bare amounts.
   */
  currencyUnit?: string | null
  /** Shown inside the card under the content: the results a note attaches. */
  children?: ReactNode
}) {
  return (
    <article
      aria-label={entryLabel(entry.content)}
      className="flex items-start gap-3 rounded-xl p-3 shadow-card ring-1 ring-foreground/10"
    >
      <IconTile size="md">{entryIcon(entry.content)}</IconTile>
      <div className="min-w-0 flex-1 space-y-2">
        <EntryContent
          content={entry.content}
          tags={tags}
          sheet={sheet}
          currencyUnit={currencyUnit}
        />
        {children}
        <p className="text-xs text-muted-foreground">
          <time dateTime={entry.recordedAt}>{recordedTime.format(new Date(entry.recordedAt))}</time>
        </p>
      </div>
    </article>
  )
}

function EntryContent({
  content,
  tags,
  sheet,
  currencyUnit,
}: {
  content: JournalEntry['content']
  tags: TagLabels
  sheet: FieldLabels
  currencyUnit: string | null
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
      return <CheckView content={content} sheet={sheet} />
    case 'sheet-change':
      return <SheetChangeView content={content} sheet={sheet} />
    case 'shop':
      return <ShopView content={content} currencyUnit={currencyUnit} />
    case 'flowOutcome':
      return <FlowOutcomeView content={content} />
  }
}

/** A shop visit: the gear bought and sold with unit prices, and the Character's money before and after. */
function ShopView({
  content,
  currencyUnit,
}: {
  content: ShopContent
  currencyUnit: string | null
}) {
  const lines = (title: string, items: ShopContent['bought']) =>
    items.length > 0 && (
      <div>
        <p className="text-xs font-medium text-muted-foreground">{title}</p>
        <ul className="text-sm tabular-nums">
          {items.map((line) => (
            <li key={`${line.field ?? ''}-${line.gear}`}>
              {`${String(line.quantity)} × ${line.name} @ ${String(line.price)}`}
            </li>
          ))}
        </ul>
      </div>
    )

  return (
    <div className="space-y-1">
      {content.bought.length === 0 && content.sold.length === 0 && (
        <p className="text-sm">Left the shop without buying or selling.</p>
      )}
      {lines('Bought', content.bought)}
      {lines('Sold', content.sold)}
      <p className="text-sm tabular-nums">
        {content.characterName}:{' '}
        {`${String(content.before)} → ${String(content.after)}${currencyUnit === null ? '' : ` ${currencyUnit}`}`}
      </p>
    </div>
  )
}

/** What a Flow step's sheet Effects changed: one line per field, with the dice each rolled. */
function SheetChangeView({ content, sheet }: { content: SheetChangeContent; sheet: FieldLabels }) {
  return (
    <ChangeLines characterName={content.characterName} changes={content.changes} sheet={sheet} />
  )
}

/** One line per sheet change, as a Flow step's Effects and the cost of a push both tell them. */
function ChangeLines({
  characterName,
  changes,
  sheet,
}: {
  characterName: string
  changes: SheetChangeContent['changes']
  sheet: FieldLabels
}) {
  return (
    <ul className="space-y-1 text-sm">
      {changes.map((change, index) => (
        <li key={index} className="tabular-nums">
          {characterName}:{' '}
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
function CheckView({ content, sheet }: { content: CheckContent; sheet: FieldLabels }) {
  const push = content.pool?.push ?? null
  return (
    <>
      <p className="font-medium">
        {content.characterName} · {content.checkLabel}
      </p>
      {content.hint !== null && <p className="text-sm text-muted-foreground">{content.hint}</p>}
      <p className="flex flex-wrap items-baseline gap-2">
        <span className="sr-only">Outcome:</span>
        <span className="text-2xl font-bold">{content.bandLabel}</span>
      </p>
      {content.special !== null && (
        <p className="text-sm">
          <span>Special result:</span> <strong>{specialResultLabels[content.special]}</strong>
        </p>
      )}
      {push !== null && (
        <p className="text-sm">
          <strong>Pushed: {push.label}</strong>
          {' · '}
          {push.rolledBandKey === content.bandKey
            ? `Rolled ${rolledAs(content, push.rolledBandLabel)}, still ${content.bandLabel}`
            : `Rolled ${rolledAs(content, push.rolledBandLabel)}, pushed to ${content.bandLabel}`}
        </p>
      )}
      {content.groups.length > 0 && (
        <div className="rounded-md bg-well p-2 shadow-well">
          <DiceGroups groups={content.groups} />
        </div>
      )}
      {content.pool !== null && <PoolDice pool={content.pool} />}
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
        {content.pool === null ? (
          <Detail term="Dice">
            <span className="font-mono">{content.expression}</span> = {content.diceTotal}
          </Detail>
        ) : (
          <Detail term="Pool">{poolSize(content.pool, content.situational)}</Detail>
        )}
        {(content.modifier !== 0 || content.modifierFormula !== null) && (
          <Detail term="Modifier">
            {signed(content.modifier)}
            {content.modifierFormula !== null && content.modifierTerms.length > 0 && (
              <span className="text-muted-foreground">
                {' · '}
                {explainModifier(content.modifierFormula, content.modifierTerms)}
              </span>
            )}
          </Detail>
        )}
        {/* A pool check shows its situational modifier once, as extra dice in the size explanation. */}
        {content.pool === null && content.situational !== 0 && (
          <Detail term="Situational">{signed(content.situational)}</Detail>
        )}
        <Detail term={content.pool === null ? 'Total' : 'Successes'}>
          <strong>{content.total}</strong>
          {content.pool !== null && <> {content.total === 1 ? 'success' : 'successes'}</>}
          {content.difficulty !== null && <> vs {content.difficulty}</>}
        </Detail>
      </dl>
      {push !== null && push.changes.length > 0 && (
        <ChangeLines characterName={content.characterName} changes={push.changes} sheet={sheet} />
      )}
    </>
  )
}

type CheckPool = NonNullable<CheckContent['pool']>

/** The first roll of a pushed check: its band, with its special result when it had one. */
function rolledAs(content: CheckContent, bandLabel: string) {
  const special = content.pool?.special ?? null
  return special === null
    ? bandLabel
    : `${bandLabel} (${specialResultLabels[special].toLowerCase()})`
}

/**
 * Every die of a pool roll, after the push when it was pushed; a success is told by text for
 * assistive tech and by weight and a ring, a die the push re-rolled by text too, and a Hunger die
 * (ADR 0044) by a second colour and its label, not by colour alone.
 */
function PoolDice({ pool }: { pool: CheckPool }) {
  const dice: { group: 'pool' | 'hunger'; face: number; success: boolean; rerolled?: boolean }[] =
    pool.push?.dice ?? pool.dice
  return (
    <div className="rounded-md bg-well p-2 shadow-well">
      <ul aria-label="Pool dice" className="flex flex-wrap gap-1.5">
        {dice.map((die, index) => (
          <li
            // A pool can show the same face many times; the position is the identity.
            key={index}
            data-group={die.group}
            className={cn(
              'min-w-8 rounded-md px-1.5 py-0.5 text-center',
              die.success
                ? 'border-2 border-foreground font-bold'
                : 'border border-dashed border-muted-foreground text-muted-foreground',
              // The second colour of the Hunger dice; the label below says it in text too.
              die.group === 'hunger' &&
                'border-destructive bg-destructive/10 text-destructive dark:bg-destructive/20',
            )}
          >
            {die.face}
            <span className="sr-only">{die.success ? ' (success)' : ' (no success)'}</span>
            {die.group === 'hunger' && (
              <span className="block text-xs font-normal">Hunger die</span>
            )}
            {die.rerolled === true && <span className="block text-xs font-normal">re-rolled</span>}
          </li>
        ))}
      </ul>
    </div>
  )
}

/** How many dice were rolled and why: the count (explained by the sheet values it read) and the extra dice. */
function poolSize(pool: CheckPool, situational: number) {
  const count =
    pool.countFormula === null || pool.terms.length === 0
      ? String(pool.count)
      : `${explainModifier(pool.countFormula, pool.terms)} = ${String(pool.count)}`
  const extra = situational === 0 ? '' : ` ${signed(situational)} extra`
  return (
    <>
      {String(pool.size)} d{pool.sides}, success on {pool.successAt}+
      <span className="text-muted-foreground">
        {' · '}
        {count}
        {extra}
      </span>
    </>
  )
}

/**
 * The modifier formula with each reference replaced by its label and the value it had, e.g.
 * `{hot} - 1` as `Hot (2) - 1`; the operators and spacing stay as written. Every `{...}` in the
 * formula must match a term; otherwise the terms are listed (`Hot (2), Edge (1)`) rather than
 * showing a half-replaced formula.
 */
function explainModifier(formula: string, terms: CheckContent['modifierTerms']) {
  const byReference = new Map(terms.map((term) => [term.reference, term]))
  const describe = ({ label, value }: (typeof terms)[number]) => `${label} (${String(value)})`
  const references = formula.match(/\{[^{}]*\}/g) ?? []
  if (!references.every((reference) => byReference.has(reference))) {
    return terms.map(describe).join(', ')
  }
  return formula.replace(/\{[^{}]*\}/g, (reference) => {
    const term = byReference.get(reference)
    return term ? describe(term) : reference
  })
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
  return <p className="drop-cap type-prose whitespace-pre-wrap">{content.text}</p>
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
