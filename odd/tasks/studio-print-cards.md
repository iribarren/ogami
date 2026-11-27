# Feature: studio-print-cards

- **Locator:** `odd/tasks/studio-print-cards.md` · Engram topic `odd/studio-print-cards/tasks`
- **Issue:** [#427](https://github.com/iribarren/ogami/issues/427) · **Branch:** `feat/studio-print-cards-1-image-store` · **Status:** in progress (slice 1)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B36 in [`docs/roadmap.md`](../../docs/roadmap.md); builds on B34 `flow-diagram` ([ADR 0051](../../docs/adr/0051-flow-diagram-layout-and-rendering.md)); B38 `play-tabletop` will reuse its card rendering and images

## Objective

Studio prints one flow of a published GameSystem release (never a draft) as a PDF to play on a real table. Everything printed comes from the release, with no new authored fields:

- **Cards:** poker-size cards (63 × 88 mm) for each Scene Type of the flow, and deck cards for the flow's oracle tables. Fronts and backs are mirrored for double-sided printing.
- **Sheets:** A4 or A5 (the author picks):
  - the flow description with the B34 diagram;
  - the flow's oracle tables;
  - trackers and clocks;
  - a checks reference;
  - a campaign log.
- **Images:** a card back image and a front frame image are uploaded to the GameSystem. The front's text sits in a fixed safe area inside the frame. A published release pins the exact images it was published with. Without images, the cards print plain.

The manual's card sheets (`frontend/src/manual/print/`) move onto the same builder, and their output does not change.

Out of scope: a user-defined visual style (fonts, colours, a Studio-made theme), planned later on top of these images; printing from Play (B38); a blank character sheet (not chosen, D2).

## Problem and why

- An author can play their flow only on screen. The manual prints only its two flows, from preset files plus a hand-written `*.paper.ts` per flow.
- The app stores no binary data yet, and a release has no way to carry a visual asset.

## Evidence

**Manual print pipeline**
- `PrintApp.tsx:12-39` maps two docs to presets bundled at build time (`manual/flowDiagrams.ts`, `import.meta.glob` of `backend/presets/{hard-country,dogwatch}.json`).
- `buildFlowCards(raw, paper)` (`flowCards.ts`) returns `FlowCards {sceneCards, deck, deckBack, rulesSheet, tables}`.
- Card sizes: Scene Type cards are A6 (2×2 per A4); deck cards are 63×88 mm (3×3 per A4).
- `paginate` and `mirrorRows` (`sheets.ts`) build the mirrored back sheets; `CardSheets.tsx` `Duplex` renders them.
- `print.css` has a single global `@page {size: A4}`, a 296 mm sheet, no bleed, no crop marks and no A5.
- `make manual-pdf` is `vite.print.config.ts` plus Playwright `page.pdf` (`frontend/print/manual-pdf.spec.ts`), which checks `[data-card]` for overflow.

**What the hand-written paper file adds**
- `paperFlow.ts:17-34`: wording for `check`, `character`, `shop` and `condition` steps; tip overrides; the deck (`table`/`compartments`, back title, finale); Fact slot meanings; the rules sections.
- `buildFlowCards` throws on those step kinds without paper wording.
- `guard()` (`flowCards.ts:56-67`) throws on app wording and `{placeholders}`.
- `BAND_LABELS` and `diceText` already generate check wording from bands.

**Flow diagram**
- `flowDiagramFromReleaseContent(content, flowKey)` (`shared/flowDiagram/fromReleaseContent.ts:82`) and `FlowDiagramView` render vector SVG.
- `phaseDiagram` cuts one phase; `.paper` tokens in `print.css` are guarded by `printCss.test.ts`.

**Release**
- `GameSystemRelease` (`backend/src/Studio/Domain/Release/GameSystemRelease.php:29-77`) is immutable.
- Table `studio_gamesystem_release`: `content` JSONB plus `content_hash` (sha256 of the canonical JSON); `notes` and `library_sources` JSONB sit outside the hash.
- `ReleasePublisher` snapshots the draft. Content validation rejects unknown properties (contract `docs/contracts/gamesystem-release.md:107-170`).
- There is no deck concept and no image field.

**Studio HTTP**
- `GameSystemReleaseController.php`: list (:59), publish (:77, 409 `no_changes` / `release_exists`), view (:134), and restore (:149).
- The view returns only an outline, counts and a diff; no endpoint returns full content to Studio.
- Full content reaches Play only through `GetPublishedRelease`.
- Frontend hooks are `studio/useGameSystemReleases.ts`. Releases are the `releases` tab of `GameSystemPage` (`ReleasesTab.tsx`), with no route of their own.

**Access**
- `security.yaml:42-54`: `^/api/studio` is ROLE_GAME_MANAGER; roles have no hierarchy (ADR 0006).
- A GameSystem is owner-only (ADR 0028): `OwnedGameSystems::get(ownerId, key)` throws `GameSystemNotFound`, which becomes 404.

**Files**
- No upload, multipart, blob, Flysystem or object-store code exists; the PHP defaults are 2M/8M.
- The PHP image has no `gd` (`docker/php/Dockerfile:11`).
- The only backup is `make db-restore` (`pg_dump -Fc`). No production deployment is documented.
- The highest ADR is 0052.

## Decisions

| # | Decision | By |
|---|---|---|
| D1 | Always printed: Scene Type cards, deck cards, the flow sheet with its diagram, the oracle tables sheet | User (prompt) |
| D2 | Extra printables chosen: trackers and clocks, a checks reference (checks and likelihood oracles), and a campaign log (Fact slots and Tags). The blank character sheet is not built | User (2026-11-27) |
| D3 | PDF through browser print: a Studio print route with print CSS (`@page` named sizes); the author uses the browser's "Save as PDF". No server-side renderer | User (2026-11-27) |
| D4 | Image storage is PostgreSQL `bytea`, content-addressed by sha256, behind a Studio `ImageStore` port (a later object store fits behind it). It travels with `pg_dump` and `db-restore`. Recorded in ADR 0053 | User (2026-11-27) |
| D5 | A release pins its images in an unhashed side column on `studio_gamesystem_release` (as `library_sources`), set at publish and never changed. The content hash and the ADR 0036 preset export stay unchanged. Publishing counts a changed image pin as a change (no 409 `no_changes`) | User (2026-11-27) |
| D6 | Image rules (ADR 0053): <ul><li>**Slots:** two per GameSystem, `cardBack` and `cardFront` (frame).</li><li>**Formats:** PNG or JPEG only, re-encoded on the server with GD, which strips metadata and neutralises polyglots. SVG and anything else are refused with 422.</li><li>**Size:** the upload is at most 5 MB.</li><li>**Pixels:** recommended 815 × 1110 px (69 × 94 mm = 63 × 88 plus 3 mm bleed, at 300 dpi). Accepted from 408 × 555 up to 1630 × 2220. The aspect ratio must be within ±2 % of 69:94.</li><li>**Safe area:** the front's text sits in a fixed 5 mm inset inside the trim.</li></ul> | Parent (proposed, not objected) |
| D7 | Access: only the owning GAME_MANAGER uploads, replaces, removes and reads a GameSystem's images or a release's pinned images; anyone else gets 404 (ADR 0028). Images are served with their stored `Content-Type`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` and an immutable cache (the URL holds the sha256). Play gets its own read path in B38 | Parent (proposed, not objected) |
| D8 | Step wording is generated with no new fields: check outcomes from their bands (reusing `BAND_LABELS` and `diceText`); any other step prints its title and prompt; `{step:…}` and `{fact:…}` are replaced by their labels. The manual keeps its paper overrides and its `guard()`. Studio prints without the guard | Parent (proposed, not objected) |
| D9 | Decks: the table of each `oracle` selection of the flow is a deck by default, and the author may tick any other oracle table of the flow. Weighted entries get copies by weight; ranged entries get copies by range width. A deck over 54 cards prints one card per entry with its range | Parent (proposed, not objected) |
| D10 | Cards: a Scene Type card's front carries its name, purpose and tips; its back carries its setup, play and closing steps. A card that overflows continues on a "(cont.)" card, and the print view lists those cards | Parent (proposed, not objected) |
| D11 | Layout: the paper size (A4 or A5) applies to the whole print. Poker cards are butted on shared cut lines, 3×3 on A4 and 2×2 on A5, with backs mirrored on the long edge. A "bleed + crop marks" option prints 69×94 mm cards, 2×3 on A4 and 2×2 on A5 | Parent (proposed, not objected) |
| D12 | The manual moves onto the shared builder with its paper overrides; its PDFs stay as they are (Scene Type cards stay A6) | Parent (proposed, not objected) |
| D13 | Studio gets a full-content read of one release: `GET /api/studio/game-systems/{key}/releases/{version}/content`, owner-only, returning the content as published plus its image pins. Printing reads only this | Parent |

## Slice plan

| Slice | Tasks | Forecast (incl. generated) | Branch |
|---|---|---|---|
| 1/5 | T1, T2 | ~1,400–1,650 lines: ADR 0053 + index ~90, `gd` in Dockerfile ~5, image domain + port + Doctrine `bytea` adapter + migration ~350, GD validation/re-encode ~150, upload/read/remove endpoints ~250, PHPUnit/Behat ~450, OpenAPI + `schema.d.ts` ~250, feature doc ~150 | `feat/studio-print-cards-1-image-store` |
| 2/5 | slice 1 fixes, T3, T4 | ~1,400–1,600 lines: release pin at publish + `no_changes` + pinned-image read ~300, release content endpoint ~200, tests ~400, OpenAPI ~250, Studio "Card images" upload UI with preview and requirements ~250 + Vitest ~200 | `feat/studio-print-cards-2-pin-and-upload` |
| 3/5 | slice 2 fixes, T5, T6 | ~1,500–1,700 lines: typed release reader ~250, shared card builder (generated wording D8, decks D9, continuation D10) ~400, manual moved onto it ~150, poker card sheets with frame/back images and bleed option ~250, Vitest ~600 | `feat/studio-print-cards-3-cards` |
| 4/5 | slice 3 fixes, T7, T8 | ~1,300–1,600 lines: A4/A5 named pages ~80, flow sheet with diagram ~200, oracle tables sheet ~120, trackers and clocks ~200, checks reference ~200, campaign log ~100, Vitest ~600 | `feat/studio-print-cards-4-sheets` |
| 5/5 | slice 4 fixes, T9, T10 | ~900–1,200 lines: print route (unlinked until here) and print options (paper, bleed, deck tables) ~300, a Print button on the Releases tab ~80, Vitest ~250, Playwright ~200, manual chapter ~100, glossary "Deck" + roadmap ~40 | `feat/studio-print-cards-5-print` |

Shippable:
- Slice 1 adds owner-only image endpoints with no UI.
- Slice 2 adds the pin, the content read and the upload UI (images upload and preview, and nothing prints them yet).
- Slices 3–4 build the print views behind an unlinked route.
- Slice 5 links them from the Releases tab.

## Tasks

| ID | Slice | Task | Route | Done | Commit |
|---|---|---|---|---|---|
| T1 | 1 | ADR 0053 (D4–D7) and its row in `docs/adr/README.md`; `gd` added to the PHP image and the upload limits raised (PHP `upload_max_filesize`/`post_max_size`) | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | |
| T2 | 1 | Studio image store: `CardImage` domain (slot, sha256, media type, width, height, bytes), `ImageStore` port, Doctrine `bytea` adapter + migration, the draft's image pins on `studio_gamesystem`, GD validation and re-encoding (D6), endpoints `PUT/DELETE /api/studio/game-systems/{key}/card-images/{slot}` (multipart) and `GET /api/studio/game-systems/{key}/images/{sha256}` (D7), `make api` | delegated writer (Sonnet): slice of 2+ non-trivial files | [ ] | |
| T3 | 2 | Release pins images at publish (D5): side column, `no_changes` counts pins, pinned images stay readable after the draft replaces them, unreferenced blobs removed on replace; release content endpoint (D13); `make api` | delegated writer | [ ] | |
| T4 | 2 | Studio "Card images" section on the GameSystem page: upload, replace and remove both slots, preview, requirements text (D6), errors | delegated writer | [ ] | |
| T5 | 3 | Typed release reader for print (narrow, throw-first, like `readPreset`), shared card builder with generated wording (D8), decks (D9), continuation cards (D10); manual moved onto it (D12), its tests unchanged | delegated writer | [ ] | |
| T6 | 3 | Poker card sheets: plain, framed front (safe area) and image back, mirrored duplex, bleed + crop marks option (D11); the print route under Studio, not linked yet | delegated writer | [ ] | |
| T7 | 4 | Paper size A4/A5 as named `@page` sizes; flow sheet (description, introduction, phases, selection, hooks) with the diagram, one per phase when too tall; oracle tables sheet | delegated writer | [ ] | |
| T8 | 4 | Trackers and clocks sheet, checks reference (checks, likelihood oracles), campaign log (Fact slots, Tags) (D2) | delegated writer | [ ] | |
| T9 | 5 | Print options (flow, paper, bleed, deck tables) and the overflow list; a Print button per release on the Releases tab | delegated writer | [ ] | |
| T10 | 5 | Playwright print pass (overflow check on `[data-card]`, images pinned), Studio manual chapter on printing, glossary "Deck", roadmap `Done:` line | delegated writer (+ doc-writer) | [ ] | |

## Acceptance criteria

- Printing reads only a published release, through the release content endpoint, never a draft.
- A later upload never changes what a published release prints. A release published without images prints plain.
- Only the owning game manager uploads, removes and reads the images; anyone else gets 404.
- Bad uploads are refused with 422 and a clear reason: an unknown format, SVG, over 5 MB, pixels out of range or a wrong aspect ratio. Nothing is stored for a refused upload.
- Cards and sheets fit their size: no `[data-card]` overflows, and overflowing Scene Type text continues on a "(cont.)" card.
- Mirrored backs line up for long-edge duplex on A4 and A5.
- The manual's PDFs are unchanged.

## Checks

- PHPUnit (domain, GD validation, Doctrine round trip of a whole image, the publish pin) and API tests for every status: 201/204/404/409/413/422.
- Behat where the Studio features already cover publishing.
- Vitest for the reader, builder, sheets and print views; ESLint, Prettier, tsc via `make verify`.
- `make qa test` in slice 1, because it changes the Dockerfile.
- Playwright print pass in slice 5; a Chrome pass by hand at the end of slices 3–5, with a real duplex test print if possible.

## Progress

- 2026-11-27: Feature doc created; five slices, ten tasks. D1 from the prompt; D2–D5 decided by the user; D6–D13 proposed and not objected to. Issue #427.
- 2026-11-27: T1 done. ADR 0053 and its index row, `gd` in the PHP image and `upload_max_filesize` 5M / `post_max_size` 8M. Docs and config only, so no RED. Observed after `make build up`: `gd_info()` reports PNG and JPEG support, and `ini_get` returns 5M and 8M.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|

## Next step

Slice 1 (T1, T2): delegated writer on `feat/studio-print-cards-1-image-store`.
