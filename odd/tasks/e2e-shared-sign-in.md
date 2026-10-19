# Feature: e2e-shared-sign-in

- **Locator:** `odd/tasks/e2e-shared-sign-in.md` · Engram topic `odd/e2e-shared-sign-in/tasks`
- **Issue:** #219 · **Branch:** `docs/e2e-shared-sign-in-wrap-up` · **Status:** done: slice 1 merged (PR #220)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `identity-account-error-codes` (PR #217)

## Objective

One shared Playwright sign-in helper module, `frontend/e2e/sign-in.ts` next to `mailpit.ts`, replaces the per-spec seeded-user constants and sign-in helpers (or inlined sign-in lines) of every spec in `frontend/e2e/`. Tooling only.

Out of scope:

- any change to what a scenario does or asserts;
- new e2e scenarios, Page Objects or Playwright fixtures/storage-state sign-in;
- app code, the seed (`make e2e-seed`) or `playwright.config.ts`.

## Problem and why

20 specs repeat the seeded emails, `process.env.E2E_PASSWORD ?? 'e2e-password-123'` and the four sign-in lines, in four variants (no `goto`, `goto` + `/play`, `goto` + "not `/login`", owner + `/admin`). A label or seed change today means editing every spec.

## Model summary (today)

| Variant | Specs |
|---|---|
| `signIn(page, email, secret)`: fill + click only, the caller opened the page | `auth.spec.ts`, `admin-users.spec.ts` (owner via `signInAsOwner` in a fresh context, invited users with their own passwords) |
| Inlined `goto('/login')` + fill + click + `toHaveURL('/play')` | `campaigns`, `play-checks`, `play-derived-values`, `play-guided`, `play-layout`, `play-night-job`, `play-percentile-horror` |
| `signIn(page)`: same as above, as a local function | `play-characters`, `play-facts`, `play-lists`, `play-session` |
| `signIn(page, email)`: `goto` + fill + click + `not.toHaveURL(/\/login/)` | `studio-check-editor`, `studio-flow-editor`, `studio-library`, `studio-oracle-editor`, `studio-publish` (manager and player), `studio-sheet-builder` |

Lookups everywhere: `getByLabel('Email')`, `getByLabel('Password', { exact: true })`, `getByRole('button', { name: 'Sign in' })`. `smoke.spec.ts` and `color-mode.spec.ts` do not sign in.

## Decisions

| Question | Decision |
|---|---|
| Module | `frontend/e2e/sign-in.ts`, same style as `mailpit.ts` (named exports, JSDoc on each function) |
| Seeded users | Exported `player`, `manager`, `owner` and `password` (`process.env.E2E_PASSWORD ?? 'e2e-password-123'`), with the "created by `make e2e-seed`" comment. Spec-local copies go; scenario-only values (`invitedPassword`, unique emails) stay in their spec |
| Lookups | Exactly the ones the specs use now (table above); no new selectors |
| `submitSignIn(page, email, secret = password)` | Fills the form on the current page and clicks Sign in; asserts nothing. For flows that open the page themselves (redirect, wrong credentials, invited users) |
| `signIn(page, email, landing?)` | `goto('/login')`, `submitSignIn` with the seeded password, then `toHaveURL(landing)` when given, else `not.toHaveURL(/\/login/)`. Play specs pass `'/play'`, the owner `'/admin'`, Studio specs none: each spec keeps the assertion it has today |
| `signInAsOwner` | Stays in `admin-users.spec.ts` (fresh context is that spec's concern), built on `signIn(page, owner, '/admin')` |

## Constraints

- No behaviour change: same tests, same steps, same assertions; `make e2e` green.
- Spec header comments about seeded presets stay (reworded only where they named the users).
- Planning heuristic only: the forecast below is not a cap.

## Slice plan

| Slice | Branch | Tasks | Forecast |
|---|---|---|---|
| 1 | `chore/e2e-shared-sign-in-1-helper` | T1 | ~250–400 changed lines (helper ~40, 20 specs ~250 mostly deletions, this doc ~90) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | `frontend/e2e/sign-in.ts` with the seeded users, `submitSignIn` and `signIn`; every signing-in spec uses it; local helpers and constants removed | delegated writer (slice 1; 20 files; feature doc inline by parent) | [x] | afdca12 |

## Progress

- 2026-10-19: feature doc created (parent); 20 specs and four sign-in variants mapped; one slice.
- 2026-10-19: T1 done (refactor, no RED: existing e2e suite is the safety net; GREEN: make e2e 26 passed, 1 flaky failure in studio-flow-editor passed on its own rerun, baseline not run); `signIn` takes the `landing` URL as an optional third argument and asserts `not.toHaveURL(/\/login/)` otherwise, so each spec keeps its assertion.
- 2026-10-19: parent spot check `make e2e` on `auth`, `admin-users` and `studio-flow-editor`: 9 passed. Slice 1 merged (PR #220, closes #219). From the next feature on, the last slice's doc wrap-up is pushed to that slice's PR before merge (CLAUDE.md, ADR 0015), not a separate branch.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | none (declined) | afdca12 | Review declined for this candidate (tests only); `make qa` and `make e2e` green apart from one flaky Studio spec | [#220](https://github.com/iribarren/ogami/pull/220) |

## Next step

None: the feature is done.
