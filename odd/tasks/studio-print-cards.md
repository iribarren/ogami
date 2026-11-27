# Feature: studio-print-cards

- **Locator:** `odd/tasks/studio-print-cards.md` · Engram topic `odd/studio-print-cards/tasks`
- **Issue:** [#427](https://github.com/iribarren/ogami/issues/427) · **Branch:** `feat/studio-print-cards-5-flow-sheets` · **Status:** in progress (slice 5)
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
| D14 | Pins live in their own table (`studio_card_image_pin`) beside the blob table (`studio_image`), both plain DBAL tables excluded from the ORM by a `schema_filter` in `doctrine.yaml`; the draft row and its version are untouched | Writer |
| D15 | Discarding a GameSystem unpins its images and releases unreferenced blobs explicitly (`DiscardGameSystemHandler` takes `CardImages`); the DB cascade alone would leave blobs and in-memory fakes behind | Writer |
| D16 | A refused upload answers 422 `{error, reason}` with the stable reason code; a multipart `PUT` works because Symfony parses PUT bodies with `request_parse_body()` | Writer |
| D17 | Slice 1 keeps T2 whole at ~3,130 changed lines (size exception, label `size:exception`); reviewed as three candidates: T1, T2 core (domain, store, normalizer, migration), T2 HTTP (controller, API tests, generated spec and types) | User (2026-11-27) |
| D18 | Release pins live in their own plain DBAL table `studio_release_card_image_pin (release_id, slot, sha256)`, written once at publish, with a foreign key to `studio_image` (like D14) instead of a JSONB side column, so the database guarantees a pinned blob exists. A blob is unreferenced only when no draft pin and no release pin names it. ADR 0053 is updated to say so | Parent (2026-11-27) |
| D19 | Restoring a release into the draft also restores its image pins: the draft's slots become the release's (a slot the release had empty is emptied), and images nobody pins any more are released | Parent (proposed) |
| D20 | Slice 1 showed backend slices run about twice their forecast, so the plan is re-cut to six slices: the upload UI (T4) moves to slice 3 with T5, and the later tasks shift by one | Parent (2026-11-27) |
| D21 | Slice 3 keeps T5 at ~2,480 changed lines (size exception, label `size:exception`); reviewed as three candidates: slice 2 fixes + T4, T5a shared reader/builder, T5b manual move | User (2026-11-27) |
| D22 | Frontend work ran about 1.6× its forecast in slice 3, so the plan is re-cut to seven slices: slice 4 is the slice 3 fixes and T6, which now also takes the A4/A5 named pages (the card layout already depends on the paper); T7 is slice 5, T8 slice 6, T9 and T10 slice 7 | Parent (2026-11-27) |
| D23 | Where the images go: the front frame (`cardFront`) sits behind every face that prints text (a Scene Type card's front and back, a "(cont.)" card, a deck card's front), with the text in the 5 mm safe area; the back image (`cardBack`) is the back of every deck card. Without a pinned image the face prints plain | Parent (proposed) |
| D24 | Slice 4 keeps T6 whole at ~1,880 changed lines (size exception, label `size:exception`); reviewed as two candidates: slice 3 fixes + T6 shared faces and sheets, then the Studio print page and route | User (2026-11-27) |
| D25 | The flow sheet prints, from the release only: the flow's name, description and introduction; then each phase in order with its name, mode (once or loop), description, selection (the Scene Types of a `sequence` or `player` selection by name, or the table an `oracle` selection draws from) and its non-empty hooks with their steps' titles and prompts; then the B34 diagram. The diagram is scaled to the printable width of the paper; when its scaled height (estimated from `layoutFlowDiagram`, no DOM measure) does not fit one page, it prints one `phaseDiagram` per phase instead, each on its own page | Parent (proposed) |
| D26 | The oracle tables sheet prints every oracle table of the flow (`flow.oracles`) in flow order: its name and die, each entry's numbers (a ranged entry its `min`–`max`; otherwise `tableRanges` of the weights, moved from the manual to `shared/print/`), its text and its effects in words. A table may break across pages; a row never does. The manual keeps its own tables sheet, unchanged (D12) | Parent (proposed) |

## Slice plan

| Slice | Tasks | Forecast (incl. generated) | Branch |
|---|---|---|---|
| 1/6 | T1, T2 | ~1,400–1,650 lines: ADR 0053 + index ~90, `gd` in Dockerfile ~5, image domain + port + Doctrine `bytea` adapter + migration ~350, GD validation/re-encode ~150, upload/read/remove endpoints ~250, PHPUnit/Behat ~450, OpenAPI + `schema.d.ts` ~250, feature doc ~150 | `feat/studio-print-cards-1-image-store` |
| 2/6 | slice 1 fixes, T3 | ~1,300–1,600 lines: four slice 1 warnings fixed with tests ~250, release pins table + publish pin + `no_changes` + restore + pinned-image read ~350, release content endpoint ~200, tests ~500, OpenAPI + `schema.d.ts` ~300 | `feat/studio-print-cards-2-pin-and-content` |
| 3/6 | slice 2 fixes, T4, T5 | ~1,400–1,700 lines: Studio "Card images" upload UI with preview and requirements ~250 + Vitest ~200, typed release reader ~250, shared card builder (generated wording D8, decks D9, continuation D10) ~400, manual moved onto it ~150, Vitest ~400 | `feat/studio-print-cards-3-upload-and-cards` |
| 4/7 | slice 3 fixes, T6 | ~1,300–1,600 lines: eight slice 3 warnings fixed with tests ~300, card faces and poker sheets with frame/back images, mirrored duplex, bleed + crop marks, A4/A5 named pages ~450, print route + release content hook ~150, Vitest ~550, feature doc ~60 | `feat/studio-print-cards-4-card-sheets` |
| 5/7 | slice 4 fixes, T7 | ~1,300–1,600 lines: three slice 4 suggestions fixed with tests ~150, reader reads introduction, phases and hooks ~150, flow sheet with diagram, one per phase when too tall ~300, oracle tables sheet ~150, print page wiring ~50, Vitest ~550, feature doc ~50 | `feat/studio-print-cards-5-flow-sheets` |
| 6/7 | slice 5 fixes, T8 | ~1,200–1,500 lines: trackers and clocks ~200, checks reference ~200, campaign log ~100, Vitest ~550 | `feat/studio-print-cards-6-printables` |
| 7/7 | slice 6 fixes, T9, T10 | ~1,200–1,500 lines: print options (flow, paper, bleed, deck tables) and the overflow list ~300, a Print button on the Releases tab ~80, Vitest ~300, Playwright ~200, manual chapter ~100, glossary "Deck" + roadmap ~40 | `feat/studio-print-cards-7-print-pass` |

Shippable:
- Slice 1 adds owner-only image endpoints with no UI.
- Slice 2 adds the release pins and the content read, with no UI.
- Slice 3 adds the upload UI (images upload and preview, and nothing prints them yet) and the card builder.
- Slices 4–6 build the print views behind an unlinked route.
- Slice 7 links them from the Releases tab and adds the Playwright pass and the manual chapter.

## Tasks

| ID | Slice | Task | Route | Done | Commit |
|---|---|---|---|---|---|
| T1 | 1 | ADR 0053 (D4–D7) and its row in `docs/adr/README.md`; `gd` added to the PHP image and the upload limits raised (PHP `upload_max_filesize`/`post_max_size`) | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `c619e7fe` |
| T2 | 1 | Studio image store: `CardImage` domain (slot, sha256, media type, width, height, bytes), `ImageStore` port, Doctrine `bytea` adapter + migration, the draft's image pins on `studio_gamesystem`, GD validation and re-encoding (D6), endpoints `PUT/DELETE /api/studio/game-systems/{key}/card-images/{slot}` (multipart) and `GET /api/studio/game-systems/{key}/images/{sha256}` (D7), `make api` | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `f79ac285`, `ae2fba90` |
| T3 | 2 | Release pins images at publish (D5, D18): pins table, `no_changes` counts pins, pinned images stay readable after the draft replaces them, unreferenced blobs removed on replace; restore re-pins the draft (D19); release content endpoint (D13); `make api` | delegated writer | [x] | `920abce7`, `2252b951` |
| T4 | 3 | Studio "Card images" section on the GameSystem page: upload, replace and remove both slots, preview, requirements text (D6), errors | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `f7744612` |
| T5 | 3 | Typed release reader for print (narrow, throw-first, like `readPreset`), shared card builder with generated wording (D8), decks (D9), continuation cards (D10); manual moved onto it (D12), its tests unchanged | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `5e5b89fc`, `675f254a` |
| T6 | 4 | Poker card sheets: plain, framed front (safe area) and image back (D23), mirrored duplex, bleed + crop marks option (D11), paper size A4/A5 as named `@page` sizes; the print route under Studio, not linked yet | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | `37e916f0`, `2014a32e`, `2ab2fb01`, `6239db84` |
| T7 | 5 | Flow sheet (description, introduction, phases, selection, hooks) with the diagram, one per phase when too tall; oracle tables sheet | delegated writer | [ ] | |
| T8 | 6 | Trackers and clocks sheet, checks reference (checks, likelihood oracles), campaign log (Fact slots, Tags) (D2) | delegated writer | [ ] | |
| T9 | 7 | Print options (flow, paper, bleed, deck tables) and the overflow list; a Print button per release on the Releases tab | delegated writer | [ ] | |
| T10 | 7 | Playwright print pass (overflow check on `[data-card]`, images pinned), Studio manual chapter on printing, glossary "Deck", roadmap `Done:` line | delegated writer (+ doc-writer) | [ ] | |

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
- Playwright print pass in slice 7; a Chrome pass by hand at the end of slices 4–7, with a real duplex test print if possible.

## Progress

- 2026-11-27: Feature doc created; five slices, ten tasks. D1 from the prompt; D2–D5 decided by the user; D6–D13 proposed and not objected to. Issue #427.
- 2026-11-27: T1 done. ADR 0053 and its index row, `gd` in the PHP image and `upload_max_filesize` 5M / `post_max_size` 8M. Docs and config only, so no RED. Observed after `make build up`: `gd_info()` reports PNG and JPEG support, and `ini_get` returns 5M and 8M.
- 2026-11-27: T2 done. Image domain (`CardImage`, `CardImageSlot`, `ImageRefused` reasons), ports `ImageStore`/`CardImagePins`/`ImageNormalizer`, `DbalImageStore` and migration `Version20261127100000`, `GdImageNormalizer` (type and pixels checked before decoding, PNG alpha kept, JPEG q92), list/upload/remove/read handlers and `GameSystemCardImageController`, `make api`. 23 API tests plus unit and integration tests. No clean RED: tests were written with the code; the observed failures (a bad test PNG, the discard cascade) were fixed. `make verify` (full run) green except one PHPStan error in the new API test, fixed and `make backend-qa` clean.
- 2026-11-27: Slice 1 came out at ~3,130 changed lines (T2 ~2,940: backend 991, tests 1,218, generated 693); the user kept it as one slice (D17).
- 2026-11-27: Slice 1 review warnings fixed (slice 2, commit 1). Pin writes of a GameSystem and every put/remove of one image hash now take transaction-scoped advisory locks (a row lock cannot serialise an empty slot); the upload answers with the image it stored (`UploadedCardImage` receipt), no re-read; a missing file answers 422 `missing_file`. Commands already run in the bus's `doctrine_transaction`. Tests written with the code, not before; the lock tests use a second connection on `pg_try_advisory_xact_lock`.
- 2026-11-27: T3 done. Migration `Version20261127110000` (`studio_release_card_image_pin`, D18), port `ReleaseCardImagePins` on `DbalImageStore`, `CardImages` pins a release / restores the draft / answers reads. Publish copies the draft's pins and counts them as a change (409 only when hash and pins both equal); console publisher untouched. `removeIfUnreferenced` checks both pin tables. Image read serves draft or any release pin of the owner's GameSystem (D7). Restore re-pins (D19). `GET .../releases/{version}/content` (D13) with the pinned images, `make api`. ADR 0053 updated. Unit, DBAL, API and Behat tests written with the code, not before; pinned-image tests assert the ordering of publish, replace and restore.
- 2026-11-27: Slice 2 review warning R3-vacuous-same-key-assertion fixed (slice 3, commit 1): the assertion was vacuous because GameSystem keys are globally unique, so bob's create with the same key answered 409; the test now gives bob a GameSystem of his own and asserts the 201.
- 2026-11-27: T4 done. `CardImagesSection` on the Overview tab under the details form (not for library packs), hooks `useCardImages` (multipart `FormData` through the typed client's `bodySerializer`; a 422 `reason` becomes a message per code, 413 and other statuses generic), a confirm dialog for Remove. 14 Vitest tests (rules text, list, upload as multipart, every 422 reason, unknown reason, 413/500, remove with confirm and its failure, load failure) written with the code; the whole `src/studio` suite stays green (2,226 tests). jsdom's `File`/`FormData` differ from Node's `Request`, so the upload test stubs Node's pair and reads the body as text.
- 2026-11-27: R3-image-lock-deadlock fixed (slice 3, own commit): `ImageStore::lockImages()` takes the hash locks sorted, each once; `CardImages` replace, remove, discard and restore lock the GameSystem first, then every image they touch (the new one, the ones the slots hold now, a release's target) in that one call, so no hash lock is taken out of order. Tests written with the code: sorted order, every hash held, the restore locks what it drops and what it brings, and one `lockImages` call per command in the unit test.
- 2026-11-27: T5a: `shared/print/` holds the typed release reader (`readRelease`: narrow, throw-first with the JSON path, schema v1 and optional parts read as empty or absent, unknown steps and effects kept as `other`), the wording helpers moved from the manual (`BAND_LABELS`, `diceText`, `outcomeToken`), and `buildCards` (generated wording D8 with `{step:}`/`{fact:}` placeholders, decks D9 with weight and range copies and a per-entry fallback over 54, continuation D10 by a pure line estimate, `CARD_CAPACITY`, hooks for wording, tips and a text transform). 42 Vitest tests written with the code; every backend preset reads. The manual still uses its own reader until T5b.
- 2026-11-27: T5b done. `buildFlowCards` reads with `readRelease` and builds its Scene Type cards and deck with `buildCards` (paper wording and the `checkOutcomes` drift check as the `wording` hook, tips override, `guard` as the text transform, `capacity: null` so paper cards never split); `preset.ts` and the duplicated reader, outcomes and wording code are deleted. `flowCards.test.ts`, `CardSheets.test.tsx`, `PrintApp.test.tsx` and `paperEdition.test.ts` pass unchanged (`src/manual`: 100 tests). The manual keeps its own finale card, tables sheet and rules sheet, which Studio prints later (T7, T8).
- 2026-11-27: Slice 3 review warnings fixed (slice 4, commits 1 and 2), each with a test: the restore-lock test builds its state without `put`/`replace` (they take image locks in the same transaction) and checks both locks are free before the restore and held after; a 422 whose body is not an object answers the generic message; the reader and the builder refuse an entry weight below 1; preset tests resolve their path from the file; a paper deck over 54 copies says so and names the table; tests pin the guard on name, purpose, tips and lines after substitution, the hint, description and license checks, and the manual's Scene Type card list. The deck message and the weight checks are new behaviour (RED seen for the 422 body); the others pin existing behaviour and were written after the code.
- 2026-11-27: T6 done. Pure geometry in `shared/print/layout.ts` (grid per paper and bleed, centred offsets, crop marks at the trim lines, frame image placement 69 x 94 mm shifted -3 mm without bleed, 5 mm safe area); `paginate`/`mirrorRows` moved to `shared/print/sheets.ts` (manual output unchanged); `CardFaces.tsx` (Scene Type front/back, "(cont.)", deck front/back, each with `data-card`) and `PokerSheets.tsx` (`DuplexSheets`, mirrored backs on the same centred grid) with `printCards.css` (named `@page print-a4`/`print-a5`, no unnamed `@page`, every rule under `.print-cards`, shell chrome hidden by `body:has(.print-cards)` in print only; `AppShell` carries `data-app-chrome`/`data-app-main`). Studio: `useReleaseContent` (content endpoint through the typed client, `readRelease`, image URLs made from the pinned sha256 only, 404/reader/network errors as messages), `printOptions` (`flow`, `paper`, `bleed`, `decks` read as text and checked against the release: a missing or unknown value is a message, never a default), `ReleasePrintPage`, and the unlinked route `/studio/game-systems/$key/releases/$version/print`. Tests written with the code (layout and options first, components after), 90 new Vitest tests; the CSS test guards the scoping. Not checked in a browser yet: the 8 pt back text against `CARD_CAPACITY` and a real duplex print are for the Chrome pass.
- 2026-11-27: Slice 4 review suggestions fixed (slice 5, commit 1): a non-whole weight reads "expected a whole number of at least 1" in the reader and the builder (test matches the full message); the router reads `flow=1e3` as the number 1000, so `flow` and `decks` stay as read in `PrintSearch` and `resolvePrintOptions` refuses a non-text key with "write the key in double quotes" rather than turning it back into another key (keeping the raw value also keeps `validateSearch` idempotent, which the router needs); `useReleaseContent` fails closed on a missing or malformed `cardImages` (list, slot, hash), and page tests cover missing or malformed `cardImages` and `content`. Tests written with the code; the route test first failed on the first design (an `unreadable` side field that the router's re-validation dropped), which is why the raw value is kept.
- 2026-11-27: T7 reader and flow sheet (slice 5, commit 2): `readRelease` reads `introduction` and the phases (key, name, mode, description, selection, non-empty hooks in `PHASE_HOOKS` order with their steps; an unknown rule or mode throws with the path); `buildFlowSheet` words each phase with Scene Type and table names, hook labels and the card wording of each hook step (`generatedLine` and `resolvePlaceholders` exported from `cards.ts`, placeholders resolved against the hook's own steps); `diagramPages` scales the diagram to the printable width (`printableArea`, 14 mm margin) and cuts one page per phase when it would be taller than the page; `FlowSheetText`/`FlowDiagramPages` and `.print-doc` CSS (padding repeated per page with `box-decoration-break: clone`, black-on-white diagram tokens, text alternative hidden). `useReleaseContent` also returns the raw `content`. `testRelease` gives entry "Two" a Scene Type, which the diagram builder requires of an oracle selection table. Tests written with the code; every preset flow builds a sheet. Not yet shown on the page (commit 3), and the repeated padding is for the Chrome pass.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1 | T1 declined by the user; T2 core `review-ad4db4d3bd414073`; T2 HTTP `review-ba1b5380446595c7` | T2 core and HTTP approved | Four warnings, fixed in slice 2: R3-empty-slot-race (`DbalImageStore.php:93-98`, two first uploads to an empty slot orphan a blob), R3-release-fk-race (`DbalImageStore.php:45-51`, `removeIfUnreferenced` against a concurrent same-bytes re-upload breaks the pin's foreign key), R3-missing-file-422-contract (controller `:86-87`, the missing-file 422 has no `reason`), R3-upload-reread-race (controller `:92-106`, the response re-reads the pins and can 500) | [#428](https://github.com/iribarren/ogami/pull/428) |
| 2 | `review-689ddf4911f82f81` (commits `920abce7`, `2252b951`) | Approved | Two warnings, fixed in slice 3: R3-image-lock-deadlock (`DbalImageStore.php:76-77`, per-hash advisory locks taken in different orders by two GameSystems swapping images, and by `restoreDraftFrom`, can deadlock), R3-vacuous-same-key-assertion (`GameSystemReleaseCardImagesApiTest.php:108-110`, bob's create is not asserted to succeed) | [#429](https://github.com/iribarren/ogami/pull/429) |
| 3 | C1 `review-b9e1651c32611726` (slice 2 fixes and T4, `d7876b47`–`ea474996`); C2 `review-f569a91e3ca266ca` (T5a, `5e5b89fc`); C3 `review-f6bb11931ed6b217` (T5b, `675f254a`) | All three approved (size exception D21) | Eight warnings, fixed in slice 4: R3-vacuous-restore-lock-test (`DbalImageStoreTest.php:357-365`), R3-refusal-in-operator-on-undefined (`useCardImages.ts:75-79`, `in` on a non-object error body throws), R3-entry-weight-not-positive (`release.ts:262`, a weight below 1 is read), R3-preset-test-cwd-dependent (`release.test.ts:7`, preset path depends on the working directory), R3-deck-perentry-throw (`flowCards.ts:168-171`, a manual deck over 54 cards now throws with an unclear message), R3-guard-hook-unproved (`flowCards.ts:325`, no test that `guard` sees name, purpose, tips and generated lines after placeholder substitution), R3-new-validation-untested (`flowCards.ts:233-235`, `:280-282`, missing hint/description/license checks untested), R3-scenecards-scope (`flowCards.ts:329`, the manual's Scene Type card list is not pinned) | [#430](https://github.com/iribarren/ogami/pull/430) |
| 4 | C1 `review-37ede93fd2f4d02b` (slice 3 fixes and T6 shared faces and sheets, `37e916f0`–`2ab2fb01`); C2 `review-23e8a8e3ff5a62b4` (Studio print page, `6239db84`) | Both approved (size exception D24) | Three suggestions, fixed in slice 5: R3-noninteger-weight-message (`cards.ts:282`, a weight of 1.5 is refused as "below 1" and the test matches only the prefix), R3-001 (`printOptions.ts:23-25`, the router JSON-parses the query, so deck keys like `1e3` or `1.0` arrive as numbers `1000` or `1`), R3-002 (`ReleasePrintPage.test.tsx:157-160`, no test that a 200 with missing or malformed `cardImages` or `content` shows the alert) | [#431](https://github.com/iribarren/ogami/pull/431) |

## Next step

Slice 5 (slice 4 fixes, T7): delegated writer on `feat/studio-print-cards-5-flow-sheets`.
