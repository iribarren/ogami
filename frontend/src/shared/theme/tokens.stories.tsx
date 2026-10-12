import type { Meta, StoryObj } from '@storybook/react-vite'

const COLOR_TOKENS = [
  'background',
  'foreground',
  'card',
  'card-foreground',
  'popover',
  'popover-foreground',
  'primary',
  'primary-foreground',
  'secondary',
  'secondary-foreground',
  'muted',
  'muted-foreground',
  'accent',
  'accent-foreground',
  'destructive',
  'success',
  'success-foreground',
  'warning',
  'warning-foreground',
  'info',
  'info-foreground',
  'border',
  'input',
  'ring',
  'chart-1',
  'chart-2',
  'chart-3',
  'chart-4',
  'chart-5',
  'sidebar',
  'sidebar-foreground',
  'sidebar-primary',
  'sidebar-primary-foreground',
  'sidebar-accent',
  'sidebar-accent-foreground',
  'sidebar-border',
  'sidebar-ring',
]

const TYPE_SCALE = [
  { className: 'text-page-title', name: 'text-page-title', sample: 'Page title' },
  { className: 'text-section-title', name: 'text-section-title', sample: 'Section title' },
  { className: 'text-panel-title', name: 'text-panel-title', sample: 'Panel title' },
]

// Literal class names, so Tailwind can see them.
const RADII = [
  { name: 'rounded-sm', className: 'rounded-sm' },
  { name: 'rounded-md', className: 'rounded-md' },
  { name: 'rounded-lg', className: 'rounded-lg' },
  { name: 'rounded-xl', className: 'rounded-xl' },
  { name: 'rounded-2xl', className: 'rounded-2xl' },
]

function Tokens() {
  return (
    <div className="max-w-5xl space-y-10 bg-background p-6 text-foreground">
      <section className="space-y-3">
        <h2 className="font-heading text-section-title">Color</h2>
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {COLOR_TOKENS.map((token) => (
            <li key={token} className="space-y-1.5 text-xs">
              <div
                className="h-12 rounded-md border"
                style={{ backgroundColor: `var(--${token})` }}
              />
              <code>--{token}</code>
            </li>
          ))}
        </ul>
      </section>
      <section className="space-y-3">
        <h2 className="font-heading text-section-title">Type</h2>
        <ul className="space-y-3">
          {TYPE_SCALE.map(({ className, name, sample }) => (
            <li key={name} className="flex items-baseline gap-4">
              <code className="w-44 shrink-0 text-xs text-muted-foreground">{name}</code>
              <span className={`font-heading ${className}`}>{sample}</span>
            </li>
          ))}
          <li className="flex items-baseline gap-4">
            <code className="w-44 shrink-0 text-xs text-muted-foreground">text-base</code>
            <span>Body text in Geist, comfortable for long reading.</span>
          </li>
        </ul>
      </section>
      <section className="space-y-3">
        <h2 className="font-heading text-section-title">Radius</h2>
        <ul className="flex flex-wrap gap-4">
          {RADII.map(({ name, className }) => (
            <li key={name} className="space-y-1.5 text-xs">
              <div className={`size-16 border bg-muted ${className}`} />
              <code>{name}</code>
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}

const meta = {
  title: 'Foundations/Tokens',
  component: Tokens,
  parameters: { layout: 'fullscreen' },
} satisfies Meta<typeof Tokens>

export default meta
type Story = StoryObj<typeof meta>

export const AllTokens: Story = {}
