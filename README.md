# Ogami

A companion web app for **solo tabletop RPG play**.

- **Play**: a solo player runs campaigns in any supported game system, guided by a narrative flow, character sheets, structured checks and oracles that keep outcomes uncertain.
- **Studio**: game managers author game systems, sheet templates, checks, narrative flows and oracles, with rich editing tools.
- **Admin**: the owner manages users and app settings.

## Quick start

**Prerequisites:** Docker (with Compose v2) and `make`. Nothing else runs on the host.

```bash
make build up        # build images, start the stack, install dependencies
```

Open http://localhost:8080: the SPA, with the API under `/api` on the same origin.

```bash
make qa test e2e     # static checks, unit/integration/Behat/Vitest, Playwright
```

`make help` lists every command; [CLAUDE.md](CLAUDE.md#commands) groups them by task.

### Users and sign-in

There is no sign-up yet. Create a user from the console (the password is asked for, hidden):

```bash
make console ARGS="doctrine:migrations:migrate -n"   # once, on a fresh database
make console ARGS="app:user:create you@example.com --role=SOLO_PLAYER"
```

| Role | Opens |
|---|---|
| `SOLO_PLAYER` | Play (`/play`) |
| `GAME_MANAGER` | Studio (`/studio`) |
| `OWNER` | Admin (`/admin`) |

Roles are independent: repeat `--role` to give several. `--password=…` skips the prompt; `--if-missing` succeeds without changes when the email exists. `make e2e` seeds its own users (`make e2e-seed`, password `E2E_PASSWORD`, default `e2e-password-123`).

Sign-in uses an HttpOnly session cookie. Behind a reverse proxy, set `TRUSTED_PROXIES` (backend environment) to the proxy addresses so client IPs, HTTPS and login throttling work.

### GameSystem presets

`make presets` publishes the shipped GameSystem presets (`backend/presets/*.json`: Free journal, Mythic-style) to the dev database. It skips a preset whose content has not changed, so it is safe to rerun. See the [release contract](docs/contracts/gamesystem-release.md).

### Ports and environment

Defaults work out of the box. To change one, put it in a git-ignored `.env` file at the repository root (Compose reads it).

| Variable | Default | Purpose |
|---|---|---|
| `HTTP_PORT` | `8080` | App (SPA + API) on the host |
| `POSTGRES_PORT` | `5433` | PostgreSQL on the host |
| `MAILPIT_PORT` | `8026` | Mailpit web UI on the host |
| `STORYBOOK_PORT` | `6006` | Storybook on the host (`make storybook`) |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | `ogami` | Dev database credentials |
| `XDEBUG_MODE` | `off` | `debug`, `coverage` or `develop` to enable Xdebug |
| `UID`, `GID` | your user | Set by `make`; containers write files as you |

## Docs

| Topic | Where |
|---|---|
| Vision and goals | [docs/vision.md](docs/vision.md) |
| Domain: context map | [docs/domain/context-map.md](docs/domain/context-map.md) |
| Domain: glossary | [docs/domain/glossary.md](docs/domain/glossary.md) |
| Architecture decisions | [docs/adr/](docs/adr/README.md) |
| Agent/dev conventions | [CLAUDE.md](CLAUDE.md) |
| Feature task docs (ODD) | [odd/tasks/](odd/tasks/) |

## Stack (summary)

Symfony 8.1 (PHP 8.5) modular monolith · React 19 + Vite SPA (TypeScript, Tailwind, shadcn/ui, TanStack Router/Query) · OpenAPI typed client · PostgreSQL 18 · Docker Compose · GitHub Actions.
See the ADRs for the reasons behind each choice.
