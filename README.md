# Ogami

A companion web app for **solo tabletop RPG play**.

- **Play**: a solo player runs campaigns in any supported game system, guided by a narrative flow, character sheets, structured checks and oracles that keep outcomes uncertain.
- **Studio**: game managers author game systems, sheet templates, checks, narrative flows and oracles, with rich editing tools.
- **Admin**: the owner manages users and app settings.

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

Symfony (PHP) modular monolith · React + Vite SPA · PostgreSQL · Docker Compose · GitHub Actions.
See the ADRs for the reasons behind each choice.
