import { FlowDiagramView } from '@/shared/flowDiagram'

import type { DiagramPage } from './diagramPages'
import type { FlowSheet } from './flowSheet'
import type { Paper } from './layout'
import type { OracleTableSheet } from './tablesSheet'

const pageClass = (paper: Paper) => `print-doc print-${paper}`

/** The text of a flow on the pages of a paper: it flows over as many pages as it needs. */
export function FlowSheetText({ sheet, paper }: { sheet: FlowSheet; paper: Paper }) {
  return (
    <section className={`${pageClass(paper)} print-flow`} data-sheet="flow">
      <h1>{sheet.name}</h1>
      {sheet.description !== undefined && <p className="print-lead">{sheet.description}</p>}
      {sheet.introduction !== undefined && (
        <p className="print-introduction">{sheet.introduction}</p>
      )}
      {sheet.phases.map((phase, index) => (
        <section key={phase.key} className="print-phase">
          <h2>
            {index + 1}. {phase.name}
            <span className="print-mode"> ({phase.mode})</span>
          </h2>
          {phase.description !== undefined && <p>{phase.description}</p>}
          <p>{phase.selection}</p>
          {phase.hooks.map((hook) => (
            <div key={hook.label} className="print-hook">
              <h3>{hook.label}</h3>
              <ul>
                {hook.lines.map((line, at) => (
                  <li key={at}>{line}</li>
                ))}
              </ul>
            </div>
          ))}
        </section>
      ))}
    </section>
  )
}

/** The diagram of the flow, one page each: the drawing is sized in millimetres to its page. */
export function FlowDiagramPages({ pages, paper }: { pages: DiagramPage[]; paper: Paper }) {
  return pages.map((page) => (
    <section
      key={page.phase ?? 'flow'}
      className={`${pageClass(paper)} print-diagram`}
      data-sheet="diagram"
      data-phase={page.phase ?? undefined}
    >
      <div className="print-figure" style={{ width: `${String(page.width)}mm` }}>
        <FlowDiagramView diagram={page.diagram} />
      </div>
    </section>
  ))
}

/** The oracle tables of the flow to roll on: a table may run over pages, a row never does. */
export function TablesSheet({ tables, paper }: { tables: OracleTableSheet[]; paper: Paper }) {
  if (tables.length === 0) {
    return null
  }
  return (
    <section className={`${pageClass(paper)} print-tables`} data-sheet="tables">
      <h1>Oracle tables</h1>
      {tables.map((table) => (
        <table key={table.key} data-table={table.key}>
          <caption>
            {table.name}: d{table.die}
            {table.rollAgainAbove !== null && `, roll again above ${String(table.rollAgainAbove)}`}
          </caption>
          <tbody>
            {table.rows.map((row, at) => (
              <tr key={at}>
                <th scope="row">{row.range}</th>
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
  )
}
