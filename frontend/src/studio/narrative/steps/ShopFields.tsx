import { TextField } from '../TextField'
import type { KindProps, Of } from './BranchingFields'
import { CheckList, SelectField } from './fields'
import { stockForms, type StockForm } from './stepDraft'

const stockLabels: Record<StockForm, string> = {
  none: 'Everything on offer',
  fixed: 'A fixed number of entries',
  dice: 'A dice roll of entries',
}

/**
 * A shop step's fields (ADR 0045): the gear and categories on offer, picked from the draft's
 * catalog, how many entries are in stock when the FlowRun reaches the step, and the sell rate.
 * The publisher owns every rule; the hints only say what the author needs to set up first.
 */
export function ShopFields({
  step,
  context,
  fieldError,
  onChange,
}: KindProps & { step: Of<'shop'> }) {
  const { economy } = context
  const gear = economy?.gear ?? []
  const categories = [
    ...new Set(gear.flatMap(({ category }) => (category === null ? [] : [category]))),
  ]

  return (
    <>
      {economy?.currency === false && (
        <p className="text-sm text-muted-foreground sm:col-span-2">
          A shop needs a currency: set one in the Sheet tab, or the publisher refuses this step.
        </p>
      )}
      {economy !== undefined && gear.length === 0 && (
        <p className="text-sm text-muted-foreground sm:col-span-2">
          The gear catalog is empty: add gear in the Gear section to offer it here.
        </p>
      )}
      <CheckList
        legend="Offered gear"
        options={gear.map(({ key, name }) => ({ key, name }))}
        value={step.gear}
        error={fieldError('offer.gear') ?? fieldError('offer')}
        onChange={(offered) => {
          onChange({ ...step, gear: offered })
        }}
      />
      <CheckList
        legend="Offered categories"
        options={categories.map((category) => ({ key: category, name: category }))}
        value={step.categories}
        error={fieldError('offer.categories')}
        onChange={(offered) => {
          onChange({ ...step, categories: offered })
        }}
      />
      <SelectField
        label="Stock"
        value={step.stockForm}
        options={stockForms.map((form) => ({ key: form, name: stockLabels[form] }))}
        error={step.stockForm === 'none' ? fieldError('stock') : undefined}
        onChange={(form) => {
          onChange({ ...step, stockForm: form as StockForm })
        }}
      />
      {step.stockForm === 'fixed' && (
        <TextField
          label="Stock count"
          value={step.stock}
          error={fieldError('stock')}
          help="Entries drawn at random from the offer, 1 to 50, when the step is reached."
          onChange={(stock) => {
            onChange({ ...step, stock })
          }}
        />
      )}
      {step.stockForm === 'dice' && (
        <TextField
          label="Stock dice"
          value={step.stock}
          error={fieldError('stock.dice') ?? fieldError('stock')}
          help="A dice expression such as 1d4+2: the entries drawn, up to the whole offer."
          onChange={(stock) => {
            onChange({ ...step, stock })
          }}
        />
      )}
      <TextField
        label="Sell rate (%)"
        value={step.sellRate}
        error={fieldError('sellRate')}
        help="The percent of its cost the shop pays for gear it buys, 1 to 100. Empty: the shop buys nothing."
        onChange={(sellRate) => {
          onChange({ ...step, sellRate })
        }}
      />
    </>
  )
}
