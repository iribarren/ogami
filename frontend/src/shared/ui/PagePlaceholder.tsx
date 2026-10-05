/** Temporary page body for an area whose first feature has not started yet. */
export function PagePlaceholder({ title, summary }: { title: string; summary: string }) {
  return (
    <section className="space-y-3">
      <h1 className="font-heading text-3xl font-bold tracking-tight">{title}</h1>
      <p className="text-muted-foreground">{summary}</p>
    </section>
  )
}
