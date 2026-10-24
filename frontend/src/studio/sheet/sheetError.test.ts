import { sheetFieldAnswer, sheetLayoutAnswer, sheetPointBudgetAnswer } from './sheetError'

describe('reading a refusal for the field in the dialog', () => {
  it.each([
    ['sheet.fields[2].label: must not be blank', 'label'],
    ['sheet.fields[2].key: duplicate field key "pow"', 'key'],
    ['sheet.fields[2].max: must be at least min (3), 1 given', 'max'],
    ['sheet.fields[2].default: must be within min..max, 9 is above max (5)', 'default'],
    ['sheet.fields[2].maxLength: must be at most 2000', 'maxLength'],
    ['sheet.fields[2].group: unknown group "Ghost", declare it first', 'group'],
    ['sheet.fields[2].span: must be at most the group columns (1)', 'span'],
    ['sheet.fields[2].formula: unknown field {ghost}', 'formula'],
  ])('gives %s to the %s input', (text, name) => {
    const answer = sheetFieldAnswer(text, 2)

    expect(Object.keys(answer.fields)).toEqual([name])
    expect(answer.form).toBeNull()
  })

  it('shows the message without its path next to the input', () => {
    expect(sheetFieldAnswer('sheet.fields[0].label: must not be blank', 0).fields).toEqual({
      label: 'must not be blank',
    })
  })

  it.each([
    ['another field of the sheet', 'sheet.fields[1].label: must not be blank'],
    ['a property the form has no input for', 'sheet.fields[2].colour: unknown property'],
    ['the field itself', 'sheet.fields[2]: must be an object'],
    ['a group of the layout', 'sheet.groups[0].span: must be at most the sheet columns'],
    ['another part of the draft', 'sceneTypes[0].oracles[0]: unknown oracle "ghost"'],
    ['a path that only starts like the field', 'sheet.fields[22].label: must not be blank'],
    ['a message without a path', 'Something else'],
  ])('gives %s to the form, path and all', (_name, text) => {
    expect(sheetFieldAnswer(text, 2)).toEqual({ fields: {}, form: text })
  })
})

describe('reading a refusal for the layout dialog', () => {
  it.each([
    [
      'sheet.groups[1].name: duplicate group name "Stats"',
      1,
      'name',
      'duplicate group name "Stats"',
    ],
    ['sheet.groups[0].columns: must be at most 4', 0, 'columns', 'must be at most 4'],
    [
      'sheet.groups[2].span: must be at most the columns of the sheet (1), 2 given',
      2,
      'span',
      'must be at most the columns of the sheet (1), 2 given',
    ],
  ])('gives %s to the input of that group', (text, index, property, message) => {
    expect(sheetLayoutAnswer(text)).toEqual({
      columns: null,
      groups: { [index]: { [property]: message } },
      form: null,
    })
  })

  it('gives the sheet columns their own message', () => {
    expect(sheetLayoutAnswer('sheet.columns: must be at most 2')).toEqual({
      columns: 'must be at most 2',
      groups: {},
      form: null,
    })
  })

  it.each([
    ['a property the form has no input for', 'sheet.groups[0].colour: unknown property'],
    ['a group as a whole', 'sheet.groups[0]: must be an object'],
    [
      'the request groups',
      'groups: must not be empty, use DELETE /sheet/layout to remove the layout',
    ],
    [
      'a dropped group',
      'groups: cannot drop the group "Stats", it holds 2 fields, move or delete its fields first',
    ],
    ['a field of the sheet', 'sheet.fields[1].group: unknown group "Ghost"'],
    [
      'a layout that is not declared',
      'sheet.groups: no layout is declared, declare the layout first',
    ],
    ['a message without a path', 'Something else'],
  ])('gives %s to the form, path and all', (_name, text) => {
    expect(sheetLayoutAnswer(text)).toEqual({ columns: null, groups: {}, form: text })
  })
})

describe('reading a refusal for the point budget', () => {
  it.each([
    ['label', 'sheet.pointBudget.label: must be at most 60 characters'],
    ['total', 'sheet.pointBudget.total: must be at least 1'],
    ['min', 'sheet.pointBudget.min: must be a whole number'],
    ['max', 'sheet.pointBudget.max: must be at least min (20), 10 given'],
  ])('gives the %s message to its input, without its path', (name, text) => {
    const answer = sheetPointBudgetAnswer(text)

    expect(answer.inputs).toEqual({ [name]: text.slice(text.indexOf(': ') + 2) })
    expect(answer.fields).toBeNull()
    expect(answer.form).toBeNull()
  })

  it.each([
    ['sheet.pointBudget.fields: must list at least one field', 'must list at least one field'],
    ['sheet.pointBudget.fields[1]: duplicate field "pow"', 'duplicate field "pow"'],
  ])('gives %s to the fields list', (text, message) => {
    expect(sheetPointBudgetAnswer(text)).toEqual({ inputs: {}, fields: message, form: null })
  })

  it.each([
    ['the budget as a whole', 'sheet.pointBudget: unknown field "bonus"'],
    ['a property with no input', 'sheet.pointBudget.colour: unknown property'],
    ['a field of the sheet', 'sheet.fields[1].label: must not be blank'],
    ['a message without a path', 'Something else'],
  ])('gives %s to the form, path and all', (_name, text) => {
    expect(sheetPointBudgetAnswer(text)).toEqual({ inputs: {}, fields: null, form: text })
  })
})
