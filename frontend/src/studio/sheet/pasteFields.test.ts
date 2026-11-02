import { keyOfName, parseFieldPaste, pastedField, type PasteSettings } from './pasteFields'

const groups = [{ name: 'Stats', columns: 1, span: 1 }]
const settings: PasteSettings = { type: 'number', group: 'Stats', min: '', max: '' }

describe('parsing pasted field names', () => {
  it('derives a key per non-blank line and numbers the lines as pasted', () => {
    expect(parseFieldPaste('Strength\n\n  Hit Points \r\nWill-power!', [])).toEqual([
      { line: 1, name: 'Strength', key: 'strength', error: null },
      { line: 3, name: 'Hit Points', key: 'hit-points', error: null },
      { line: 4, name: 'Will-power!', key: 'will-power', error: null },
    ])
  })

  it('reads nothing from blank text', () => {
    expect(parseFieldPaste(' \n\n', [])).toEqual([])
  })

  it('refuses a name with no letter or digit, naming the line', () => {
    const [line] = parseFieldPaste('???', [])

    expect(line).toMatchObject({ line: 1, key: '' })
    expect(line?.error).toContain('letter')
  })

  it('refuses a key the sheet already has', () => {
    const [line] = parseFieldPaste('Strength', ['strength'])

    expect(line?.error).toBe('The key "strength" is already a field of this sheet.')
  })

  it('refuses a key an earlier line already derives, and keeps the first line valid', () => {
    const lines = parseFieldPaste('Hit points\nHit-points\nHP', [])

    expect(lines.map((line) => line.error)).toEqual([
      null,
      'The key "hit-points" is already used by line 1.',
      null,
    ])
  })

  it('refuses a name over 100 characters', () => {
    const [line] = parseFieldPaste('a'.repeat(101), [])

    expect(line?.error).toBe('The name is over 100 characters.')
  })

  it('cuts a derived key to 64 characters with no trailing hyphen', () => {
    expect(keyOfName('a'.repeat(63) + ' b')).toBe('a'.repeat(63))
    expect(keyOfName('a'.repeat(80))).toHaveLength(64)
  })
})

describe('the field a pasted line becomes', () => {
  it('is what the New field form would send, with the shared settings', () => {
    expect(
      pastedField(
        { name: 'Strength', key: 'strength' },
        { ...settings, min: '0', max: '5' },
        groups,
      ),
    ).toEqual({
      field: {
        key: 'strength',
        label: 'Strength',
        group: 'Stats',
        type: 'number',
        min: 0,
        max: 5,
      },
    })
  })

  it('leaves the bounds out of a text field', () => {
    expect(
      pastedField({ name: 'Notes', key: 'notes' }, { ...settings, type: 'text', max: '5' }, groups),
    ).toEqual({
      field: { key: 'notes', label: 'Notes', group: 'Stats', type: 'text' },
    })
  })

  it('reports a bound that is not a whole number', () => {
    expect(
      pastedField({ name: 'Strength', key: 'strength' }, { ...settings, max: 'x' }, groups),
    ).toEqual({ errors: { max: 'Must be a whole number.' } })
  })

  it('reports a group the sheet does not declare', () => {
    expect(
      pastedField({ name: 'Strength', key: 'strength' }, { ...settings, group: 'Nope' }, groups),
    ).toEqual({ errors: { group: 'Pick a group.' } })
  })
})
