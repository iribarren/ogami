# Frontend Caddy snippets

Files named `*.caddyfile` here are imported inside the dev site block of
`docker/php/Caddyfile`, after the `/api` handler and before the PHP fallback.
Use them to serve the SPA from the same origin as the API (ADR 0006), e.g.:

```caddyfile
handle {
	reverse_proxy node:5173
}
```
