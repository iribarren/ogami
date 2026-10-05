import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card'

import { useHealth } from './useHealth'

/** Small card with the API and database status, fed by the generated API client. */
export function HealthStatus() {
  const health = useHealth()

  return (
    <Card size="sm" className="max-w-xs" role="region" aria-labelledby="health-status-title">
      <CardHeader>
        <CardTitle id="health-status-title">System status</CardTitle>
      </CardHeader>
      <CardContent className="pt-2 text-sm">
        {health.isPending && <p className="text-muted-foreground">Checking…</p>}
        {health.isError && <p className="text-destructive">The API is unreachable.</p>}
        {health.isSuccess && (
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
            <dt className="text-muted-foreground">API</dt>
            <dd data-status={health.data.status}>{health.data.status}</dd>
            <dt className="text-muted-foreground">Database</dt>
            <dd
              data-status={health.data.database}
              className={health.data.database === 'down' ? 'text-destructive' : undefined}
            >
              {health.data.database}
            </dd>
          </dl>
        )}
      </CardContent>
    </Card>
  )
}
