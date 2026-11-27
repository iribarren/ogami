import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { describeFlowDiagram } from './describeFlowDiagram'
import { FlowDiagramView } from './FlowDiagramView'
import { flowDiagramFromReleaseContent } from './fromReleaseContent'
import { layoutFlowDiagram } from './layout'
import { phaseDiagram } from './phaseDiagram'
import { loadPreset, phase, release, sceneType, step } from './testSupport'

const coldBerth = flowDiagramFromReleaseContent(loadPreset('dogwatch'), 'cold-berth')

describe('FlowDiagramView', () => {
  it('draws one node per layout node with its label', () => {
    const { container } = render(<FlowDiagramView diagram={coldBerth} />)
    const layout = layoutFlowDiagram(coldBerth)
    const nodes = container.querySelectorAll('[data-node-id]')
    expect(nodes).toHaveLength(layout.nodes.length)
    for (const node of layout.nodes) {
      const drawn = container.querySelector(`[data-node-id="${node.id}"]`)
      expect(drawn?.textContent).toContain(node.label)
    }
    expect(container.querySelectorAll('[data-phase-id]')).toHaveLength(coldBerth.phases.length)
    expect(container.querySelectorAll('[data-edge-id]')).toHaveLength(layout.edges.length)
  })

  // print.css styles `.paper figure`, `figure svg` and `figure details`: the root must stay a figure
  // holding the drawing and its text alternative, or the print rules stop applying.
  it('is a figure holding the svg and the text alternative', () => {
    const { container } = render(<FlowDiagramView diagram={coldBerth} />)

    const root = container.firstElementChild
    expect(root?.tagName.toLowerCase()).toBe('figure')
    expect(root?.querySelector('svg[role="img"]')).not.toBeNull()
    expect(root?.querySelector('details')).not.toBeNull()
  })

  it('names the flow for assistive technology', () => {
    render(<FlowDiagramView diagram={coldBerth} />)
    const svg = screen.getByRole('img', { name: 'Flow diagram: Cold Berth' })
    const words = document.getElementById(svg.getAttribute('aria-describedby') ?? '')
    expect(words).not.toBeNull()
  })

  it('draws the edges that come through a table dashed, with the table name', () => {
    const { container } = render(<FlowDiagramView diagram={phaseDiagram(coldBerth, 'the-ship')} />)
    const next = container.querySelectorAll('[data-kind="nextScene"]')
    expect(next).toHaveLength(4)
    for (const edge of next) {
      expect(edge.querySelector('polyline')?.getAttribute('stroke-dasharray')).toBe('5 4')
    }
    // One label for the four edges of the same hook and table.
    expect([...next].map((edge) => edge.textContent).filter((text) => text !== '')).toEqual([
      'Ship trouble',
    ])
    const end = container.querySelector('[data-kind="endPhase"] polyline')
    expect(end?.getAttribute('stroke-dasharray')).toBeNull()
  })

  it('shows each hook as a chip with its label and step count', () => {
    const { container } = render(<FlowDiagramView diagram={phaseDiagram(coldBerth, 'the-ship')} />)
    const chips = [...container.querySelectorAll('[data-header-id^="hook:"]')]
    expect(chips.map((chip) => chip.textContent)).toEqual(['Scene closing · 2', 'World turn · 4'])
  })

  it('marks a Scene Type only a transition reaches', () => {
    const diagram = flowDiagramFromReleaseContent(
      release({
        sceneTypes: [
          sceneType('a', {
            play: [step('s', { effects: [{ kind: 'nextScene', sceneType: 'b' }] })],
          }),
          sceneType('b'),
        ],
        phases: [phase('p', { selection: { rule: 'sequence', sceneTypes: ['a'] } })],
      }),
      'f',
    )
    const { container } = render(<FlowDiagramView diagram={diagram} />)
    const reached = container.querySelector('[data-node-id="scene:p:b"]')
    expect(reached?.textContent).toContain('reached')
    expect(reached?.querySelector('rect')?.getAttribute('stroke-dasharray')).toBe('4 3')
    expect(container.querySelector('[data-node-id="scene:p:a"]')?.textContent).not.toContain(
      'reached',
    )
  })

  it('gives the text alternative every phase and every transition', () => {
    render(<FlowDiagramView diagram={coldBerth} />)
    const description = describeFlowDiagram(coldBerth)
    const words = within(screen.getByText('The flow in words').closest('details') as HTMLElement)
    for (const item of description.phases) {
      expect(words.getByText(item.title)).toBeInTheDocument()
    }
    for (const transition of description.transitions) {
      expect(words.getByText(transition)).toBeInTheDocument()
    }
  })

  it('takes every colour from the theme variables', () => {
    const { container } = render(<FlowDiagramView diagram={coldBerth} />)
    const html = container.innerHTML
    const attributes = [...html.matchAll(/ (?:fill|stroke)="([^"]*)"/g)].map((match) => match[1])
    const styles = [...html.matchAll(/(?:^|[;"\s])(?:fill|stroke): ?([^;"]*)/g)].map(
      (match) => match[1],
    )
    expect(new Set(attributes)).toEqual(new Set(['none']))
    expect(styles.length).toBeGreaterThan(0)
    for (const value of styles) {
      expect(value).toMatch(/^(var\(--[a-z-]+\)|context-stroke|none)$/)
    }
    expect(html).toContain('var(--foreground)')
  })
})

describe('FlowDiagramView, interactive', () => {
  const ship = phaseDiagram(coldBerth, 'the-ship')
  const firstScene = ship.phases[0]?.selection.sceneTypes[0]?.key ?? ''

  it('is static without onActivate: no button, nothing focusable', () => {
    const { container } = render(
      <FlowDiagramView diagram={ship} issues={{ 'phase:the-ship': ['x'] }} />,
    )

    expect(screen.queryAllByRole('button')).toHaveLength(0)
    expect(container.querySelector('[tabindex]')).toBeNull()
  })

  it('makes the phase, its Scene Types, the selection node and the hook chips buttons, and nothing else', () => {
    const { container } = render(<FlowDiagramView diagram={ship} onActivate={vi.fn()} />)
    const layout = layoutFlowDiagram(ship)
    const interactive = layout.nodes.filter(({ kind }) => !['end', 'exitStub'].includes(kind))

    const buttons = screen.getAllByRole('button')
    // The phase box, its mode chip, one per node that is not a marker, one per hook chip.
    expect(buttons).toHaveLength(2 + interactive.length + (ship.phases[0]?.hooks.length ?? 0))
    expect(screen.getByRole('button', { name: 'Phase: The Ship' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Hook: Scene closing, 2 steps' })).toBeInTheDocument()
    expect(
      container.querySelectorAll('[data-kind="end"][role], [data-kind="exitStub"][role]'),
    ).toHaveLength(0)
  })

  it('says which kind of node was activated, by click, Enter and Space', async () => {
    const user = userEvent.setup()
    const onActivate = vi.fn()
    render(<FlowDiagramView diagram={ship} onActivate={onActivate} />)

    await user.click(screen.getByRole('button', { name: 'Phase: The Ship' }))
    await user.click(screen.getByRole('button', { name: 'Hook: World turn, 4 steps' }))
    screen.getByRole('button', { name: 'Selection: Player chooses' }).focus()
    await user.keyboard('{Enter}')
    await user.keyboard(' ')

    expect(onActivate.mock.calls.slice(0, 2)).toEqual([
      ['phase:the-ship', 'phase'],
      ['hook:the-ship:worldTurn', 'hook'],
    ])
    // A chip does not open the phase behind it.
    expect(onActivate).toHaveBeenCalledTimes(4)
    expect(onActivate.mock.calls[2]).toEqual(onActivate.mock.calls[3])
  })

  it('does not toggle on other keys', async () => {
    const user = userEvent.setup()
    const onActivate = vi.fn()
    render(<FlowDiagramView diagram={ship} onActivate={onActivate} />)

    screen.getByRole('button', { name: 'Phase: The Ship' }).focus()
    await user.keyboard('a{Escape}')

    expect(onActivate).not.toHaveBeenCalled()
  })

  it('marks the selected node and lists the issues of a node in its name', () => {
    const id = `scene:the-ship:${firstScene}`
    const { container } = render(
      <FlowDiagramView
        diagram={ship}
        onActivate={vi.fn()}
        selectedId="phase:the-ship"
        issues={{ [id]: ['Scene Type is not in the draft.', 'Another.'] }}
      />,
    )

    expect(screen.getByRole('button', { name: 'Phase: The Ship' })).toHaveAttribute(
      'aria-current',
      'true',
    )
    const named = container.querySelector(`[data-node-id="${id}"]`)
    expect(named?.getAttribute('aria-label')).toMatch(
      /^Scene Type: .*\. Issues: Scene Type is not in the draft\. Another\.$/,
    )
    // The mark is a "!" as well as a colour.
    expect(named?.textContent).toContain('!')
    expect(container.querySelectorAll('[aria-current]')).toHaveLength(1)
  })
})
