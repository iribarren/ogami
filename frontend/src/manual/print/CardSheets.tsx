import type { DeckCard, FlowCards, SceneCard } from './flowCards'
import { mirrorRows, paginate } from './sheets'

/** A card on a sheet, or a gap where the last sheet has no card. `data-card` names it for the print spec. */
function Slot({
  name,
  className,
  children,
}: {
  name?: string
  className: string
  children?: React.ReactNode
}) {
  return name === undefined ? (
    <div className={className} />
  ) : (
    <article className={`card ${className}`} data-card={name}>
      {children}
    </article>
  )
}

function SceneFront({ card }: { card: SceneCard }) {
  return (
    <Slot name={`${card.name}, front`} className="scene">
      <h2>{card.name}</h2>
      <p className="purpose">{card.purpose}</p>
      <p>{card.tips}</p>
    </Slot>
  )
}

function SceneBack({ card }: { card: SceneCard }) {
  return (
    <Slot name={`${card.name}, back`} className="scene back">
      {card.back.map((section) => (
        <section key={section.heading}>
          <h3>{section.heading}</h3>
          <ul>
            {section.lines.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        </section>
      ))}
    </Slot>
  )
}

function DeckFront({ card, at }: { card: DeckCard; at: number }) {
  if (card.kind === 'compartment') {
    return (
      <Slot name={`Compartment ${String(at)}, front`} className="deck">
        <p className="label">Compartment</p>
        <p className="line">Name</p>
        <p className="line">What it is</p>
        <p className="line" />
      </Slot>
    )
  }
  return (
    <Slot name={`${card.starts ?? 'Card'} ${String(at)}, front`} className="deck">
      <p className="entry">{card.text}</p>
      {card.kind === 'finale' && <p className="note">{card.note}</p>}
      {card.starts !== null && <p className="label">Starts: {card.starts}</p>}
    </Slot>
  )
}

/** Fronts, then the backs of the same sheet mirrored, so the pile prints double-sided. */
function Duplex<T>({
  items,
  columns,
  rows,
  className,
  front,
  back,
}: {
  items: readonly T[]
  columns: number
  rows: number
  className: string
  front: (item: T, at: number) => React.ReactNode
  back: (item: T, at: number) => React.ReactNode
}) {
  return paginate(items, columns, rows).flatMap((sheet, page) => {
    const render = (cells: (T | null)[], draw: (item: T, at: number) => React.ReactNode) =>
      cells.map((item, cell) =>
        item === null ? (
          <div key={cell} className="gap" />
        ) : (
          draw(item, page * columns * rows + cell + 1)
        ),
      )
    return [
      <section key={`front-${String(page)}`} className={`sheet ${className}`}>
        {render(sheet, front)}
      </section>,
      <section key={`back-${String(page)}`} className={`sheet ${className}`}>
        {render(mirrorRows(sheet, columns), back)}
      </section>,
    ]
  })
}

/** The card sheets of one flow, in print order: Scene Type cards, deck, rules sheet, tables. */
export function CardSheets({ cards }: { cards: FlowCards }) {
  const { rulesSheet, tables } = cards
  return (
    <div className="cards-doc">
      <Duplex
        items={cards.sceneCards}
        columns={2}
        rows={2}
        className="a6"
        front={(card) => <SceneFront key={card.name} card={card} />}
        back={(card) => <SceneBack key={card.name} card={card} />}
      />
      <Duplex
        items={cards.deck}
        columns={3}
        rows={3}
        className="small"
        front={(card, at) => <DeckFront key={at} card={card} at={at} />}
        back={(_card, at) => (
          <Slot key={at} name={`${cards.deckBack} ${String(at)}, back`} className="deck back">
            <p className="label">{cards.deckBack}</p>
          </Slot>
        )}
      />
      <section className="sheet rules" data-card="Rules sheet">
        <h2>{rulesSheet.title}</h2>
        <p className="question">{rulesSheet.question}</p>
        {rulesSheet.sections.map((section) => (
          <section key={section.heading}>
            <h3>{section.heading}</h3>
            {section.lines.map((line) => (
              <p key={line}>{line}</p>
            ))}
          </section>
        ))}
        <p className="credit">{rulesSheet.credit}</p>
      </section>
      <section className="tables-sheet">
        <h2>{rulesSheet.title}: tables</h2>
        {tables.map((table) => (
          <table key={table.name}>
            <caption>
              {table.name}: d{table.die}
              {table.rollAgainAbove !== null &&
                `, roll again above ${String(table.rollAgainAbove)}`}
            </caption>
            <tbody>
              {table.rows.map((row) => (
                <tr key={row.range}>
                  <th>{row.range}</th>
                  <td>
                    {row.text}
                    {row.effects.length > 0 && <em> ({row.effects.join('; ')})</em>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ))}
      </section>
    </div>
  )
}
