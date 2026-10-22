import { flowIssueCount } from './flowIssues'

const valid = { valid: true, errors: [], warnings: [] }

describe('the issues of a flow', () => {
  it('counts the error and the warnings that are about the flow or go into it', () => {
    const validation = {
      valid: false,
      errors: [{ path: 'flows[1].phases[0].selection', message: 'unknown Scene Type "x"' }],
      warnings: [
        'flows[1]: has no default view',
        'flows[1].phases[0].sceneOpening[2]: is never reached',
        'flows[1][0]: odd',
        'flows[0].phases[0]: is empty',
        'flows[10]: other flow',
        'sceneTypes[1].play[0]: mentions flows[1]',
      ],
    }

    expect(flowIssueCount(validation, 1)).toBe(4)
    expect(flowIssueCount(validation, 0)).toBe(1)
    expect(flowIssueCount(validation, 10)).toBe(1)
    expect(flowIssueCount(validation, 2)).toBe(0)
  })

  it('counts every error by its path, not by what its message says', () => {
    const validation = {
      valid: false,
      errors: [
        { path: 'flows[0].name', message: 'must not be blank' },
        { path: 'flows[0].phases[1].mode', message: 'must be once or loop' },
        { path: 'sceneTypes[0].play[0].title', message: 'flows[0]: is not a placeholder' },
      ],
      warnings: [],
    }

    expect(flowIssueCount(validation, 0)).toBe(2)
    expect(flowIssueCount(validation, 1)).toBe(0)
  })

  it('is zero for a valid draft', () => {
    expect(flowIssueCount(valid, 0)).toBe(0)
  })
})
