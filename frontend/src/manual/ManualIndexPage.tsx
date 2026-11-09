import { ManualContents } from './ManualContents'

/** `/manual`: the whole book's contents, grouped by part. */
export function ManualIndexPage() {
  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="font-heading text-page-title">Manual</h1>
        <p className="text-muted-foreground">
          How to design a narrative flow and play it solo, with two complete examples.
        </p>
      </header>
      <ManualContents />
    </div>
  )
}
