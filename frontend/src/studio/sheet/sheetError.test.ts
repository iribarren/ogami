import {
  currencyAnswer,
  gearAnswer,
  sheetFieldAnswer,
  sheetLayoutAnswer,
  sheetPointBudgetAnswer,
} from './sheetError'

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

describe('reading a refusal for the point budget at a position', () => {
  it.each([
    ['key', 'sheet.pointBudgets[1].key: duplicate budget key "attributes"'],
    ['label', 'sheet.pointBudgets[1].label: must be at most 60 characters'],
    ['total', 'sheet.pointBudgets[1].total: must be at least 1'],
    ['min', 'sheet.pointBudgets[1].min: must be a whole number'],
    ['max', 'sheet.pointBudgets[1].max: must be at least min (20), 10 given'],
  ])('gives the %s message to its input, without its path', (name, text) => {
    const answer = sheetPointBudgetAnswer(text, 1)

    expect(answer.inputs).toEqual({ [name]: text.slice(text.indexOf(': ') + 2) })
    expect(answer.fields).toBeNull()
    expect(answer.form).toBeNull()
  })

  it.each([
    ['sheet.pointBudgets[1].fields: must list at least one field', 'must list at least one field'],
    [
      'sheet.pointBudgets[1].fields[2]: field "pow" is already in budget "attributes"',
      'field "pow" is already in budget "attributes"',
    ],
  ])('gives %s to the fields list', (text, message) => {
    expect(sheetPointBudgetAnswer(text, 1)).toEqual({ inputs: {}, fields: message, form: null })
  })

  it.each([
    ['the budget as a whole', 'sheet.pointBudgets[1]: unknown field "bonus"'],
    ['a property with no input', 'sheet.pointBudgets[1].colour: unknown property'],
    ['another budget', 'sheet.pointBudgets[0].total: must be at least 1'],
    ['a position that only starts like it', 'sheet.pointBudgets[11].total: must be at least 1'],
    ['the list as a whole', 'sheet.pointBudgets: at most 10 point budgets'],
    ['the legacy budget', 'sheet.pointBudget.total: must be at least 1'],
    ['a field of the sheet', 'sheet.fields[1].label: must not be blank'],
    ['a message without a path', 'Something else'],
  ])('gives %s to the form, path and all', (_name, text) => {
    expect(sheetPointBudgetAnswer(text, 1)).toEqual({ inputs: {}, fields: null, form: text })
  })
})

describe('reading a refusal of an option of a conditions field', () => {
  it.each(['key', 'label'])('gives the %s of an option to its input by position', (input) => {
    expect(
      sheetFieldAnswer(`sheet.fields[2].options[1].${input}: duplicate option key "hurt"`, 2),
    ).toEqual({
      fields: {},
      form: null,
      options: { 1: { [input]: 'duplicate option key "hurt"' } },
    })
  })

  it('gives the list of options to the options input', () => {
    expect(sheetFieldAnswer('sheet.fields[2].options: must have at least 1 item', 2)).toEqual({
      fields: { options: 'must have at least 1 item' },
      form: null,
    })
  })

  it('keeps the options of another field with the form', () => {
    expect(sheetFieldAnswer('sheet.fields[1].options[0].key: bad', 2).form).toBe(
      'sheet.fields[1].options[0].key: bad',
    )
  })
})

describe('the answer to a refused gear entry', () => {
  it('puts the message of an input of this entry next to it', () => {
    expect(gearAnswer('gear[2].cost: must be at most 1000000', 2)).toEqual({
      inputs: { cost: 'must be at most 1000000' },
      form: null,
    })
    expect(gearAnswer('gear[0].category: must not be blank', 0).inputs).toEqual({
      category: 'must not be blank',
    })
  })

  it('keeps another entry, the catalog and anything else whole as a form message', () => {
    for (const text of [
      'gear[1].cost: must be at least 0',
      'gear: the catalog has too many entries',
      'gear[2].key: is taken',
      'Another request saved the draft.',
    ]) {
      expect(gearAnswer(text, 2)).toEqual({ inputs: {}, form: text })
    }
  })
})

describe('the answer to a refused currency', () => {
  it('puts the field and the unit next to their inputs', () => {
    expect(currencyAnswer('sheet.currency.field: must be a number field')).toEqual({
      inputs: { field: 'must be a number field' },
      form: null,
    })
    expect(currencyAnswer('sheet.currency.unit: is at most 10 characters').inputs).toEqual({
      unit: 'is at most 10 characters',
    })
  })

  it('keeps anything else whole as a form message', () => {
    expect(currencyAnswer('sheet.currency: is not an object')).toEqual({
      inputs: {},
      form: 'sheet.currency: is not an object',
    })
  })
})
