# 0008. Full Docker Compose dev environment and Makefile

- **Status:** Accepted
- **Date:** 2026-10-05

## Context

The project spans PHP, Node and PostgreSQL. Local setup must be reproducible for humans and agents, with one command to start and one to test.

## Decision

- A **full Docker Compose** environment (`compose.yaml`) runs every service:

| Service | Role |
|---|---|
| FrankenPHP | Symfony backend and API |
| PostgreSQL | Database |
| Node | Vite dev server and frontend tooling |
| Mailpit | Captures outgoing email in development |

- A **Makefile** wraps common tasks (planned: `up`, `down`, `test`, `qa`, `sh`).
- No tool needs to be installed on the host except Docker and Make.

## Consequences

- Same environment on every machine and in CI.
- Slower file I/O on some hosts than native tooling.
- Commands are documented once in the Makefile; `CLAUDE.md` lists them after Feature 2.
