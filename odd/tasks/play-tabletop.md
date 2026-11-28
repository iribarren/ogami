# Feature: play-tabletop

- **Locator:** `odd/tasks/play-tabletop.md` · Engram topic `odd/play-tabletop/tasks`
- **Issue:** [#438](https://github.com/iribarren/ogami/issues/438) · **Branch:** `feat/play-tabletop-3-scene-dice` · **Status:** slice 3 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B38 in [`docs/roadmap.md`](../../docs/roadmap.md); reuses B34 (`flow-diagram`, [ADR 0051](../../docs/adr/0051-flow-diagram-layout-and-rendering.md)) and B36 (`studio-print-cards`, [ADR 0053](../../docs/adr/0053-card-image-storage.md))

## Objective

A virtual tabletop view of a Play campaign, beside the current Play page:

- **A mat** in the middle where the scene cards the player drew are laid: this session's scenes in a row, earlier sessions as piles.
- **The deck** of the current phase, drawn by the flow's own rules.
- **A dice tray** to throw dice, 2D, no physics.
- **The journal as a notebook** on the table that the player opens to read and write.
- **An oracle book** and a **small flow map** at the table's edge.

Presentation only. The current Play page (`CampaignPage`) stays untouched except for one header button, and the backend gains only two read endpoints.

Out of scope: real shuffled decks or discard piles, free placement of cards, saved positions, dice physics, a new gesture or animation library.

## Problem and why

Play is a page of panels. The B34 diagram and B36 cards gave the flow a physical look in the manual and in print, but playing still feels like filling a form. A table with cards, dice and a notebook matches how a solo player plays on paper.

## Evidence

- **Play page:** `frontend/src/routes/play.campaigns.$campaignId.tsx:5-13` renders `CampaignPage` (`frontend/src/play/campaigns/CampaignPage.tsx`, 364 lines): header :124-137, Journal/Focus toggle :149, sidebar of `PlayPanel`s :180-271.
- **FlowRun:** server state in `backend/src/Play/Domain/Campaign/FlowRun/FlowRun.php`. The API gives `campaign.flowRun` `{status, waitsForSession, progress, step, pick, canMoveOn, next, canEndSession}`; `pick` is `{rule: sequence|player|oracle, cards, table, forced}` (`frontend/src/shared/api/schema.d.ts:2773`).
- **Scene pick:** `offered()` (`FlowRun.php:1685-1695`) offers the forced card, the sequence card, or every listed Scene Type for `player`, and none for `oracle`. `pickSceneTypeByOracle` (:588-603) rolls the phase's table on the server. **No deck, draw or discard state exists.**
- **Scenes played:** `campaign.sessions[].scenes[]` (`backend/src/Play/Infrastructure/Http/SceneResponse.php`) lists every scene in number order with title, kind (`scene|hook`), Scene Type and cast; `currentSessionNumber`, `currentSceneNumber` (schema.d.ts:3155-3160).
- **Commands:** one TanStack mutation `useFlowRunCommand` (`frontend/src/play/flow/useFlowRun.ts:41`) for all 11 FlowRun commands; each returns the whole campaign.
- **Reusable UI:** `GuidedBody` (`play/flow/GuidedParts.tsx:77-154`), `StepCard` (`play/flow/StepCard.tsx`), `ScenePickCard`, `OraclePanel` (`play/oracles/OraclePanel.tsx`), `DiceRoller` (`play/dice/DiceRoller.tsx`), `DiceGroups`, `shared/ui/die-shape.tsx`, `useRecordRoll` (`CampaignPage.tsx:352-364`), `useJournal` / `groupJournal` / `Journal` / `JournalEntryView` / `NoteComposer` (`play/journal/`), `useCampaignTheme` (`shared/theme/useCampaignTheme.ts`).
- **Diagram:** `FlowDiagramView` highlights `selectedId` (`phase:<key>`, `scene:<phase>:<sceneType>`) (`shared/flowDiagram/FlowDiagramView.tsx:60,125,151`); its adapter `flowDiagramFromReleaseContent` (`shared/flowDiagram/fromReleaseContent.ts:82`) needs the release content.
- **Cards:** `shared/print/CardFaces.tsx` (`SceneFront`, `SceneBack`, `DeckFront`, `DeckBack`) is plain React, but its CSS is scoped under `.print-cards` (`printCards.css:57`) and sized in mm; content comes from `buildCards(release, …)` (`shared/print/cards.ts:333`); poker 63 × 88 mm (`layout.ts:28`).
- **Missing read paths:** release content is served only by the owner-only `/api/studio/game-systems/{key}/releases/{v}/content` (`GameSystemReleaseContentResponse`), card images only by `/api/studio/game-systems/{key}/images/{sha256}` (`GameSystemCardImageController.php:123`). ADR 0053 says Play gets its own read path with B38.
- **Play's ACL:** `PublishedGameSystemReleases` (`backend/src/Play/Application/PublishedGameSystemReleases.php`) implemented by `StudioPublishedGameSystemReleases`, which asks Studio's Published Language (`GetPublishedRelease` → `PublishedReleaseView`) on the query bus.
- **Theme:** a release pins a Theme (`pinnedRelease.theme`, schema.d.ts:2370); `useCampaignTheme` sets `data-theme` on `<html>`; tokens in `frontend/src/index.css`.
- **Stack:** React 19, TanStack Router and Query, Tailwind 4, shadcn with `radix-ui`, `tw-animate-css`, dagre; no drag, gesture or animation library.

## Decisions

Approved by the user on 2026-11-28.

- **D1 A separate view.** A new route `/play/campaigns/$campaignId/table`, unlinked until slice 3. It reads the same campaign query as `CampaignPage`, so both stay in sync for free: the FlowRun lives on the server. A **"Table" button in the `CampaignPage` header** links to it (slice 3), and the table links back.
- **D2 The mat is derived, never stored.** This session's scenes lie in a row in play order; the current scene is larger and face up; hook scenes (`kind: hook`) are smaller cards. Earlier sessions are piles at the mat's edge; clicking a pile fans it out. Everything comes from `campaign.sessions[].scenes` and `campaign.flowRun`: no stored state, no saved positions, no free dragging.
- **D3 Comfortable to read and write (user requirement).** Cards on the mat show only what identifies them (title, Scene Type). Reading and writing happen in zooms that are large: the current scene's panel takes most of the viewport on desktop (at least ~2/3 of the width, up to the full height) and the whole screen on a phone, at the app's normal reading size; the notebook's writing area is a large textarea (at least ~12 rows, growing with the panel). No card-sized text for anything the player reads at length or writes.
- **D4 The deck is a picture of the current phase's selection rule.** Accepted by the user: no shuffle state.

  | Rule | On the table |
  |---|---|
  | `player` | Click the deck: the offered cards fan out face up; the player picks one (`pick`) |
  | `sequence` | The next card sits on top; clicking turns it (`pick`, or the auto-pick) |
  | `oracle` | Clicking the deck "draws": `pick/roll`; the card the table rolled flips face up |
  | forced (`nextScene`) | Only that card slides out of the deck |

- **D5 Playing a scene.** Clicking the current scene card opens the scene panel (D3), which renders `GuidedBody` unchanged: steps, checks, keep or push, choices, shop, "Next". No step logic is duplicated. Refined at slice 3: the panel renders Play's `FocusPanel` (progress, cast, `GuidedBody`, the scene so far) and `GuidedControls` (end scene, end phase, pause), so the whole flow plays from the table. With no current scene (before a session or between scenes) a "Play" card in the current scene's place opens the same panel.
- **D6 Dice tray.** `DiceRoller` presets for free rolls, recorded with `useRecordRoll`. The latest roll, a step's check included, shows as 2D `DieShape` dice that tumble with CSS and land on the result the server already returned. Reduced motion skips the tumble.
- **D7 Notebook.** A notebook on the table opens a full-screen overlay with `Journal` and `NoteComposer`, showing this scene first (D3 sizes).
- **D8 Oracle book and flow map.** The book opens `OraclePanel` in a side sheet. A small B34 `FlowDiagramView` at the table edge highlights the current phase through `selectedId`.
- **D9 Theming.** Each Theme gains table tokens (`--table-surface`, `--table-mat`, `--table-card-shadow`) in `index.css`. Cards use the release's pinned back and frame images, or a theme-styled fallback.
- **D10 Tablet and phone without a new library.** Tailwind 4 container queries, CSS 3D card flips, Radix Dialog/Sheet for the zooms. On a phone the mat becomes a horizontal strip and the deck, tray, notebook and book move to a bottom dock that opens sheets.
- **D11 Two Play read endpoints, no writes.**
  - `GET /api/campaigns/{campaignId}/release`: the campaign's pinned release, `{gameSystemKey, version, publishedAt, content, cardImages}`, field for field like Studio's `GameSystemReleaseContentResponse`, so `flowDiagramFromReleaseContent` and `buildCards` take it unchanged. Its `cardImages[].url` point at the Play image path below.
  - `GET /api/campaigns/{campaignId}/card-images/{sha256}`: the bytes of an image the campaign's pinned release pins; 404 for any other hash. Same headers as Studio's read (stored `Content-Type`, `nosniff`, `inline`, immutable private cache).
  - Both: only the campaign's owner; anyone else gets 404, like every campaign endpoint. Play reaches the content and the bytes through Studio's Published Language (application queries on the query bus) behind Play ports; PHPat boundaries hold.
  - The player's browser may load the whole flow and every oracle table: accepted by the user (solo play; the Paper cards print everything anyway).
- **D12 ADR 0054** records the tabletop as a third presentation of the FlowRun (after [ADR 0016](../../docs/adr/0016-flow-presentation-journal-with-focus-mode.md)): state derived, not stored; the deck as a picture of the selection rule; the two Play read paths (closing ADR 0053's open line).

## Slice plan

Sequential slices to `main`; forecasts include generated files (OpenAPI spec, TS types, route tree).

| Slice | Branch | Tasks | Forecast | Safe on `main` alone |
|---|---|---|---|---|
| 1 | `feat/play-tabletop-1-read-paths` | T1 | ~1,770 (actual) | endpoints unused by any UI |
| 2 | `feat/play-tabletop-2-mat` | T2, T3 | ~1,300–1,600 | components unused, `/table` route unlinked |
| 3 | `feat/play-tabletop-3-scene-dice` | T4, T5 | ~1,200–1,500 | `/table` route unlinked |
| 4 | `feat/play-tabletop-4-notebook` | T6, T7 | ~1,200–1,500 | the header button lands with a working table |

Re-planned on 2026-11-28 from three slices to four: T1 with its generated API reached ~1,770 lines, so T2 moved to slice 2 and the rest shifted.

## Tasks

| ID | Slice | Task | Route | Done | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Studio Published Language queries for a release's content and pinned image bytes; Play ports and adapters; `GET /api/campaigns/{id}/release` and `GET /api/campaigns/{id}/card-images/{sha256}` (D11) with unit, integration and API tests; `make api` | delegated writer (Sonnet): 2+ non-trivial files; generated API by the parent (`make api`) | [x] | `f341e237`, `78d9c5fd` |
| T2 | 2 | ADR 0054 (D12) and the ADR index; Play `useCampaignRelease` hook; on-screen card faces (a `.table-cards` scope in rem at the 63:88 ratio, reusing `CardFaces`) with Storybook stories from a preset | delegated writer (Sonnet) | [x] | `dbd69f3c` |
| T3 | 2 | `/table` route and table layout (desktop, tablet, phone dock, D10); `matOf(campaign)` pure derivation of rows and piles (D2) with tests; mat rendering with piles that fan out | delegated writer (Sonnet) | [x] | `91cec6ff`, `373cbaa2` |
| T4 | 3 | Deck by selection rule (D4) through `useFlowRunCommand`; scene panel rendering `GuidedBody` at the D3 sizes (D5); flip animation | delegated writer (Sonnet): 2+ non-trivial files | [x] | |
| T5 | 3 | Dice tray (D6): free rolls, latest-roll reveal, reduced motion | delegated writer (Sonnet): 2+ non-trivial files | [x] | |
| T6 | 4 | Notebook overlay (D7, D3 sizes), oracle book sheet and flow map (D8) | | [ ] | |
| T7 | 4 | Theme table tokens (D9); "Table" header button (D1); Playwright spec; Play manual passage "The table"; roadmap Done line | | [ ] | |

## Acceptance criteria

- The table shows the same FlowRun as the Play page: a command played on one shows on the other after navigation, with no reload and no stored table state.
- Drawing a scene follows the phase's rule (D4); a forced scene offers only its card; `oracle` draws through the server's table roll.
- Every step, check, push and choice of a scene plays from the scene panel exactly as on the Play page.
- The scene panel and the notebook's writing area are comfortable at desktop and phone widths (D3): no text below the app's reading size, a writing area of at least ~12 rows.
- The two read endpoints refuse other users' campaigns (404) and hashes the release does not pin (404); the release payload is accepted unchanged by `flowDiagramFromReleaseContent` and `buildCards`.
- The current Play page is unchanged apart from the header button.
- No new frontend library.

## Checks

- PHPUnit: Studio queries, Play adapters, API tests for 200 / 401 / 404 (other user, unknown campaign, unpinned hash, release without images); PHPat via PHPStan.
- Vitest: `useCampaignRelease`, `matOf`, deck by rule, scene panel, tray, notebook; ESLint, Prettier, tsc; `api-check` — all through `make verify`.
- Playwright spec in slice 4; a Chrome pass on desktop and phone width in slices 2–4.

## Progress

- 2026-11-28: Brainstorm done, plan approved by the user (D1–D12; oracle deck as a picture, mat as session row plus piles, large read/write zooms, header button, release content visible to the player). Feature doc created: 3 slices, 7 tasks. Issue #438.
- 2026-11-28: T1 done: Studio Published Language queries `GetPublishedReleaseContent` and `GetPublishedCardImage` (the hash is checked against the pins of that exact release), Play port `PublishedReleaseContents` with its Studio adapter, `GetCampaignRelease` / `GetCampaignCardImage` handlers on the campaign's pinned version, `CampaignReleaseController`. RED observed for the Studio and Play handler tests (classes missing), GREEN after (7 and 5 tests); the API test (12 tests) was written after the code, so no separate RED. An unreadable pinned release answers 500 like the other campaign endpoints. `make backend-qa` clean. Slice re-planned to four (see Slice plan).
- 2026-11-28: Slice 1 review findings fixed: the `DoesNotPerformAssertions` contradiction removed (the test now asserts four `CampaignNotFound`); unreadable-release tests added (Studio handler, Play adapter, API 500); a pinned hash with no bytes now raises `PublishedCardImageMissing` / `CardImageUnavailable` (500) while unpinned hashes stay 404. The new tests passed on first run, written after the code: no RED observed.
- 2026-11-28: T2 done: ADR 0054 and its index line; `useCampaignRelease` (`play/campaigns/`, `staleTime` infinite, key under the campaign key, removed by `useUpgradeCampaignRelease` so an upgrade refetches; returns `release` for `buildCards`, raw `content` for `flowDiagramFromReleaseContent`, image URLs from the API); `.table-cards` scope (`play/table/tableCards.css`, `TableCards`) sizes the print faces in rem through `--card-width`/`--mm`, `printCards.css` untouched; Storybook stories from the hard-country preset. Tests passed on first run, written after the code: no RED observed (hook 5, cards 3; print suites still green).
- 2026-11-28: T3 done: route `/play/campaigns/$campaignId/table` (`$campaignId_`, unlinked, link back to the Play page), `TablePage` (same campaign query as the Play page plus `useCampaignRelease`, theme applied), `TableLayout` (container queries: mat in the middle with five labelled placeholder slots at the edge from `3xl`, mat as a strip with a bottom dock below), `matOf` in `play/table/mat.ts`, `Mat` with piles that fan out in a dialog (focus returns to the pile, Escape closes, `motion-safe` hover). RED observed for `matOf` (module missing), GREEN after (10 tests); component and page tests (8) written after the code, no RED. A session with no scene makes no pile. No Chrome pass done in this slice's writer run.
- 2026-11-28: Slice 2 review findings fixed: API test that a pinned image whose bytes are missing answers 500 (`CardImageUnavailable`); `useCampaignRelease` tests that an upgrade evicts the cached release (refetch) and that unreadable or failed content surfaces as a query error. All passed on first run, written after the code: no RED observed.
- 2026-11-28: T4 done: `Deck` (by `flowRun.pick`: face down and disabled without a pick; `player` fans the offered cards in a dialog; `sequence` turns the next card with a CSS flip; forced slides out; `oracle` sends `pickByRoll`; buttons disabled while pending, errors as `role="alert"`), `ScenePanel` (Play's `FocusPanel` + `GuidedControls` in a dialog, full screen on a phone and at least 2/3 wide from `md`, open state kept in the table so refetches never close it, focus back to the card), `TableLayout` slots take nodes, current mat card is the panel button, a "Play" card stands in without a current scene. One `useFlowRunCommand` shared by deck and panel. The deck back is the release's pinned back image or a themed fallback (not `DeckBack`, which needs an oracle deck). The current card flips on mount, so also on first load. Tests written after the code: no RED observed (12 new, table suite 30).
- 2026-11-28: T5 done: `latestRoll(entries)` (last free roll or check roll in recorded order; a pool check's dice come from its pool; a check without dice is skipped), `LatestRollView` (2D `DieShape` dice, numbered chip for a die with no shape, dropped dice dimmed, `table-tumble` keyframe keyed by entry id, off under `prefers-reduced-motion`), `DiceTray` (`DiceRoller` default presets through `useRecordRoll`, disabled without a current scene like the Play page's dice panel; below `3xl` the dock slot is a button opening a bottom sheet). RED observed for `latestRoll` (module missing), GREEN after (6 tests); tray tests (6) written after the code, no RED.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-00d0ab9f669bdc19` | `f341e237`, `78d9c5fd` | approved, acknowledged. R3 non-blocking, fixed in slice 2: `DoesNotPerformAssertions` contradicting asserts in `GetCampaignReleaseHandlersTest`; unreadable-release 500 path untested; a pinned hash whose bytes are missing answered 404 instead of a distinct error | [#439](https://github.com/iribarren/ogami/pull/439) |
| 2 | `review-40cbbf2902582240` | `625effbc`, `dbd69f3c`, `91cec6ff`, `373cbaa2` | approved, acknowledged. R3 non-blocking, fixed in slice 3: `CardImageUnavailable` → 500 untested at the HTTP level (`CampaignReleaseController.php:76-77`); no test that a release upgrade evicts the cached release (`useReleaseUpgrade.ts:78-79`); no test that unreadable content surfaces as a query error (`useCampaignRelease.ts:67`) | [#440](https://github.com/iribarren/ogami/pull/440) |

## Next step

Slice 3: slice 2 review fixes, T4 and T5 on `feat/play-tabletop-3-scene-dice`, then native review and PR. Slice 4: T6 and T7. Chrome pass on desktop and phone still pending (slices 2–4).
