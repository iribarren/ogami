import type { CampaignLog } from './campaignLog'
import type { ChecksReference } from './checksReference'
import type { Paper } from './layout'
import type { ClockSheet, CounterSheet, TrackerSheetEntry } from './trackerSheet'

const pageClass = (paper: Paper) => `print-doc print-${paper}`

/** The checks of the release and the likelihood oracles of the flow, to keep beside the table. */
export function ChecksReferenceSheet({
  reference,
  paper,
}: {
  reference: ChecksReference
  paper: Paper
}) {
  const { checks, oracles } = reference
  if (checks.length === 0 && oracles.length === 0) {
    return null
  }
  return (
    <section className={`${pageClass(paper)} print-checks`} data-sheet="checks">
      {checks.length > 0 && <h1>Checks</h1>}
      {checks.map((check) => (
        <div key={check.key} className="print-check" data-check={check.key}>
          <h2>
            {check.label}
            {check.dice !== undefined && <span className="print-mode"> ({check.dice})</span>}
          </h2>
          <p>{check.hint}</p>
          <p className="print-bands">Results: {check.bands.join(', ')}.</p>
        </div>
      ))}
      {oracles.length > 0 && <h1>Likelihood oracles</h1>}
      {oracles.map((oracle) => (
        <table key={oracle.key} data-oracle={oracle.key}>
          <caption>
            {oracle.name}: d{oracle.die}
          </caption>
          <tbody>
            {oracle.levels.map((level, at) => (
              <tr key={at}>
                <td>{level.label}</td>
                <td>{level.answer}</td>
              </tr>
            ))}
          </tbody>
          {oracle.exceptional !== undefined && (
            <tfoot>
              <tr>
                <td colSpan={2}>{oracle.exceptional}</td>
              </tr>
            </tfoot>
          )}
        </table>
      ))}
    </section>
  )
}

const POINT = 50
const RADIUS = 46

const at = (turn: number) => {
  const angle = turn * 2 * Math.PI - Math.PI / 2
  return `${(POINT + RADIUS * Math.cos(angle)).toFixed(2)} ${(POINT + RADIUS * Math.sin(angle)).toFixed(2)}`
}

/** A circle split into its segments, black on white with no fill: each one is shaded by hand. */
function Clock({ segments }: { segments: number }) {
  return (
    <svg
      className="print-clock"
      viewBox="0 0 100 100"
      role="img"
      aria-label={`A clock of ${String(segments)} segments`}
    >
      {segments === 1 ? (
        <circle cx={POINT} cy={POINT} r={RADIUS} fill="none" stroke="#000" strokeWidth="1.5" />
      ) : (
        Array.from({ length: segments }, (_, index) => (
          <path
            key={index}
            d={`M ${String(POINT)} ${String(POINT)} L ${at(index / segments)} A ${String(RADIUS)} ${String(RADIUS)} 0 0 1 ${at((index + 1) / segments)} Z`}
            fill="none"
            stroke="#000"
            strokeWidth="1.5"
            strokeLinejoin="round"
          />
        ))
      )}
    </svg>
  )
}

function Counter({ counter }: { counter: CounterSheet }) {
  return (
    <>
      {counter.boxes === null ? (
        <p className="print-write-in">
          <span className="print-box print-box-wide" /> Write the value ({counter.range}).
        </p>
      ) : (
        <ol className="print-boxes">
          {counter.boxes.map((box) => (
            <li key={box.value} className={box.value === counter.start ? 'print-start' : undefined}>
              <span className="print-box" />
              <span className="print-box-value">{box.value}</span>
              {box.label !== undefined && <span className="print-box-label">{box.label}</span>}
            </li>
          ))}
        </ol>
      )}
      {counter.boxes === null && counter.levels.length > 0 && (
        <p className="print-levels">
          {counter.levels.map((level) => `${level.label} ${level.range}`).join(', ')}.
        </p>
      )}
      {counter.start !== undefined && <p>Starts at {counter.start}.</p>}
    </>
  )
}

function Tracker({ tracker }: { tracker: ClockSheet | CounterSheet }) {
  return (
    <div className="print-tracker" data-tracker={tracker.key} data-kind={tracker.kind}>
      <h2>{tracker.name}</h2>
      {tracker.hint !== undefined && <p>{tracker.hint}</p>}
      {tracker.kind === 'clock' ? (
        <>
          <Clock segments={tracker.segments} />
          <p>{tracker.segments} segments.</p>
        </>
      ) : (
        <Counter counter={tracker} />
      )}
    </div>
  )
}

/** The trackers of the flow: clocks to shade, counters to tick; each is kept whole on a page. */
export function TrackersSheet({
  trackers,
  paper,
}: {
  trackers: TrackerSheetEntry[]
  paper: Paper
}) {
  if (trackers.length === 0) {
    return null
  }
  return (
    <section className={`${pageClass(paper)} print-trackers`} data-sheet="trackers">
      <h1>Trackers and clocks</h1>
      {trackers.map((tracker) => (
        <Tracker key={tracker.key} tracker={tracker} />
      ))}
    </section>
  )
}

/** The Fact slots and Tags to fill in as the campaign goes, then the ruled lines of the log. */
export function CampaignLogSheet({ log, paper }: { log: CampaignLog; paper: Paper }) {
  return (
    <section className={`${pageClass(paper)} print-log`} data-sheet="log">
      <h1>Campaign log</h1>
      {log.slots.length > 0 && <h2>Fact slots</h2>}
      {log.slots.map((slot) => (
        <div key={slot.key} className="print-entry-field" data-slot={slot.key}>
          <strong>{slot.label}</strong>
          {slot.hint !== undefined && <span className="print-hint"> {slot.hint}</span>}
          <div className="print-line" />
        </div>
      ))}
      {log.tags.length > 0 && <h2>Tags</h2>}
      {log.tags.map((tag) => (
        <div key={tag.key} className="print-entry-field" data-tag={tag.key}>
          <strong>{tag.label}</strong>
          {tag.hint !== undefined && <span className="print-hint"> {tag.hint}</span>}
          {Array.from({ length: log.tagLines }, (_, line) => (
            <div key={line} className="print-line" />
          ))}
        </div>
      ))}
      <h2>Log</h2>
      <div className="print-ruled" />
    </section>
  )
}
