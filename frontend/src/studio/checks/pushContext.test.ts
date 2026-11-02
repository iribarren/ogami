import { checks } from '../fixtures'
import { pushContext } from './pushContext'

const data = {
  ...checks,
  fields: [
    { key: 'pow', label: 'Power', type: 'number' as const, max: null },
    { key: 'hp', label: 'Hit points', type: 'track' as const, max: 12 },
  ],
  conditions: [{ key: 'phys', label: 'Physical', options: [{ key: 'hurt', label: 'Hurt' }] }],
}

const keys = (order?: string[]) => pushContext(data, order).sheetFields.map(({ key }) => key)

describe('the context of a push cost', () => {
  it('lists the number and track fields, then the conditions fields, without the sheet order', () => {
    expect(keys()).toEqual(['pow', 'hp', 'phys'])
  })

  it('lists the fields in sheet order, a conditions field among the others (S8)', () => {
    expect(keys(['phys', 'pow', 'hp'])).toEqual(['phys', 'pow', 'hp'])
    expect(keys(['pow', 'phys', 'hp'])).toEqual(['pow', 'phys', 'hp'])
  })

  it('puts a field the sheet order does not know last, keeping the order of the rest', () => {
    expect(keys(['hp'])).toEqual(['hp', 'pow', 'phys'])
  })

  it('keeps the options of a conditions field', () => {
    const [, , phys] = pushContext(data).sheetFields

    expect(phys?.options).toEqual([{ key: 'hurt', name: 'Hurt' }])
  })
})
