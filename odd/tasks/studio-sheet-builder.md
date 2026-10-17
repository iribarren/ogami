# Feature: studio-sheet-builder

- **Locator:** `odd/tasks/studio-sheet-builder.md` · Engram topic `odd/studio-sheet-builder/tasks`
- **Issue:** #166 · **Branch:** `feat/studio-sheet-builder-2-play-layout` · **Status:** in progress (slice 2)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `studio-flow-editor` (PRs #152–#165)

## Objective

Roadmap feature 16 (milestone M4). In a schema 2 GameSystem draft, a GAME_MANAGER builds the sheet template visually: fields of every type, a layout of declared groups in a grid, and derived-value formulas with live validation. A live preview shows the sheet as Play renders it, filled with sample values. The publisher's rules are the builder's rules. The release contract gains an additive layout model, and Play renders it.

Out of scope: the check editor (17: checks are only read here, to find references); the library (18b); migrating Character values when a key is renamed or removed (ADR 0010, 0014: campaigns stay pinned); new formula operators or functions (ADR 0023); drag and drop (ADR 0004); a free-form grid.

## Problem and why

- A draft's `sheet` can only come from a copied or restored release (ADR 0028: no JSON import). The Sections tab only counts sheet fields.
- The only layout today is a field's `group` string: consecutive fields with the same group render together, one column, in list order. Paper character sheets use side-by-side boxes and stat grids.
- Formulas are plain text inputs checked only by the server on save. A TS evaluator held equal to PHP by conformance vectors already exists (`frontend/src/shared/lib/formula/`) and ADR 0023 plans its use by Studio's formula editor. Its errors carry the position only inside the message.
- Play's `SheetFields` renderer is stateless but typed on Play's normalized API type, so a Studio preview needs a neutral type.
- Nothing finds references to a sheet field key (derived formulas, check formulas, sheet effects), so a rename or delete cannot be refused with the places it is used.

## Model summary (today, schema 2)

`sheet` is `{fields?}` ([contract §Sheet](../../docs/contracts/gamesystem-release.md), ADR 0022). Field `{key, label 1–100, hint? ≤500, group? 1–60, type}`, ≤100 fields, keys unique. Types: `number{min?, max?, default?}`, `text{multiline?, maxLength?}`, `list{itemLabel?, maxItems?}`, `track{max?}`, `derived{formula}`. Validation: `backend/src/Studio/Domain/Release/Version2/SheetFields.php` and `Formulas.php` (sheet scope: `{key}` on number/track/derived, `{key.max}` on tracks, `{tracker:key}`; `{check:…}` refused; cycles via `DependencyOrder`). Play re-checks in `Play/Infrastructure/GameSystem/GameSystemReleaseTranslator.php` into `Play/Domain/GameSystem/Sheet/SheetTemplate.php`. JSON Schema `backend/contracts/gamesystem-release/v2.schema.json`, kept equal to the domain by `ReleaseSchemaAgreementTest`. Defaults are omitted from the canonical form, so additive properties keep hashes.

## Decisions

| Question | Decision |
|---|---|
| Layout (user, 2026-10-17) | **Extend the contract** with a real layout model, additive in schema version 2 |
| Layout model (user, 2026-10-17) | **Group grid.** Groups declared once, in order; the sheet has 1–2 columns of groups; a group has 1–4 columns of fields and may span the sheet's columns; a field may span its group's columns. Edited with move buttons and selects. Phones show one column |
| Contract shape (Claude) | `sheet` allows exactly `fields`, `columns`, `groups`. `columns?` 1–2. `groups?` ≤20 of `{name (1–60, not blank, unique), columns? 1–4, span? 1–2}`. Field `span? 1–4`. Defaults (`columns` 1, `span` 1) and `[]`/`null` groups are omitted from the canonical form, so every release valid before keeps its hash |
| Layout rules (Claude) | Domain checks, item rules: `sheet.columns` and field `span` need declared `groups`; a group's `span` ≤ the sheet's columns; a field's `span` ≤ its group's columns; with declared groups every field has a `group` naming a declared group. An empty declared group is allowed (renders nothing). Groups render in declared order; fields in list order within their group. Without `groups`, today's consecutive-group rendering stays |
| Play (Claude) | Translator reads and re-checks the layout (fails closed). Campaign response: `pinnedRelease.sheetLayout {columns, groups[{name, columns, span}]}` or `null`, and `span` on each field. `SheetFields` and the derived-value helpers move to `frontend/src/shared/sheet/` with a neutral type; a CSS grid that collapses to one column on small screens |
| Studio API (Claude) | `GameSystemSheetController` in the ADR 0030 style: `GET /sheet` read model (version, sheet, tracker catalog, field keys of the latest release); `POST /sheet/fields`, `PUT /sheet/fields/{field}` (rename when the key changes), `DELETE …?expectedVersion=N`, `POST /sheet/fields/{field}/move {group?, index}`; `PUT /sheet/layout {columns, groups[{name, previousName?, columns, span}]}` (rename cascades to fields; dropping a group that holds fields is a 422); `POST /sheet/layout/declare` converts a sheet without groups (groups from first appearance, fields without a group into a new `General` group, fields regrouped stably); `DELETE /sheet/layout` back to plain groups. Body `{expectedVersion, …}`; every command returns the read model; 404/409/422 as ADR 0030 |
| References (Claude) | `DraftReferences` gains sheet field keys: derived formulas, check formulas, sheet effects (`field` and `{formula}` values) in steps, outcomes and table entries. Rename or delete while referenced is a 422 listing the paths. A type change goes through the item rules (a sheet effect on a now-`text` field fails with its path) |
| Formula editor (Claude) | Client-side live validation with `shared/lib/formula`: syntax with a structured `position` (TS only; PHP keeps its messages, conformance vectors unchanged), unknown or wrong-kind references in the sheet scope, cycles across the draft sheet. Insert-reference menu; computed value from the preview's sample values. The server stays the authority on save. Built as a reusable `FormulaInput` for the check editor (17) |
| Live preview (Claude) | Renders the unsaved form state through the shared `SheetFields`; sample values editable per field, sample tracker values start at each tracker's initial value; nothing is saved |
| Rename after publish (Claude) | Allowed. A hint when the key exists in the latest release: Characters on campaigns that upgrade later lose that value (migration is feature 20) |

## Design

### Contract and Play (slices 1–2)

- New ADR 0031 (sheet layout and Studio sheet builder) amending ADR 0022's layout line; contract §Sheet; glossary (Sheet layout, Group).
- 2026-10-17: T2 done. `SheetLayout` (Studio) and `SheetFields` check columns, groups and spans as item rules; schema and agreement vectors updated; hash of a release without layout unchanged.
- 2026-10-17: T3 done. Play `SheetLayout`/`SheetGroup` value objects, `SheetTemplate::layout()`, field `span`; translator re-checks every layout rule and fails closed; legacy releases read with no layout and span 1.
- Studio: `SheetFields::validate` (or a `SheetLayout` helper next to it) checks `columns`, `groups` and spans; `v2.schema.json`; agreement vectors; canonical-form and hash tests (a release without layout keeps its hash).
- Play: `SheetTemplate` gains a layout value object; translator re-checks; Campaign response + OpenAPI; shared renderer with the grid.

### Studio backend (slices 3–4)

- `GameSystem` commands for fields and layout, each running `ReleaseContent::checkItemRules` on the whole document; `DraftReferences` for sheet field keys; read model handler; controller and API tests for every 404/409/422.

### Studio frontend (slices 5–7)

- Sheet tab on `GameSystemPage`: groups with their fields, field editor per type, add/edit/delete/move, path-prefixed 422s mapped to fields (`sheetError.ts`), 409 as conflict.
- Layout panel: declare groups, rename/reorder/delete groups, columns and spans. Live preview panel with sample values.
- `FormulaInput`: live diagnostics with position, insert reference, computed value.

## Constraints

- Releases valid before keep the same canonical form and hash; Play reads stored releases without a layout as today.
- The publisher, the JSON Schema and Play's translator agree (ADR 0022, 0023); Domain stays framework-free.
- Frontend uses only the generated client; no new library unless a slice needs it (ADR 0004).
- Studio is owner-only per draft (ADR 0028).

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice (reviewer refuses ~1,850). Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/studio-sheet-builder-1-layout-contract` | `feat(studio): studio-sheet-builder 1/8 sheet layout contract` | T1, T2, T3 | ~1,200 |
| 2 | `feat/studio-sheet-builder-2-play-layout` | `feat(play): studio-sheet-builder 2/8 play renders the sheet layout` | T4, T5 | ~1,300 |
| 3 | `feat/studio-sheet-builder-3-field-commands` | `feat(studio): studio-sheet-builder 3/8 sheet field commands` | T6, T7 | ~1,600 |
| 4 | `feat/studio-sheet-builder-4-layout-commands` | `feat(studio): studio-sheet-builder 4/8 sheet layout commands` | T8 | ~1,100 |
| 5 | `feat/studio-sheet-builder-5-sheet-tab` | `feat(studio): studio-sheet-builder 5/8 sheet tab and field editor` | T9 | ~1,600 |
| 6 | `feat/studio-sheet-builder-6-layout-preview` | `feat(studio): studio-sheet-builder 6/8 layout editor and live preview` | T10, T11 | ~1,400 |
| 7 | `feat/studio-sheet-builder-7-formula-editor` | `feat(studio): studio-sheet-builder 7/8 formula editor` | T12 | ~1,200 |
| 8 | `feat/studio-sheet-builder-8-e2e` | `test(e2e): studio-sheet-builder 8/8 sheet builder end to end` | T13 | ~700 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0031 (sheet layout model and Studio sheet builder; amends ADR 0022's layout line) and ADR index row; contract §Sheet (layout table, canonical form, Play reading); glossary (Sheet layout, Group) | delegated writer (slice 1) | [x] | 68c37f8 |
| T2 | 1 | Studio publisher: `sheet.columns`, `sheet.groups`, field `span` with the layout rules as item rules; canonical form omits defaults; `v2.schema.json` and agreement vectors; tests incl. hash of a release without layout unchanged | delegated writer (slice 1) | [x] | 8763fc2 |
| T3 | 1 | Play translator: read and re-check the layout into `SheetTemplate` (fails closed); stored releases without layout read as today; unit tests | delegated writer (slice 1) | [x] | fac045b |
| T4 | 2 | Play API: `pinnedRelease.sheetLayout` and field `span`; `make api`; API tests | delegated writer (slice 2) | [x] | |
| T5 | 2 | Frontend: move `SheetFields`, `derivedValues`, `sheetValues` to `shared/sheet/` with a neutral type; grid rendering (one column on phones); Play fixtures, tests and stories | | [ ] | |
| T6 | 3 | Domain: `DraftReferences` for sheet field keys; `GameSystem` save/delete/move field commands (rename refused while referenced) | | [ ] | |
| T7 | 3 | Application + HTTP: handlers, `GET /sheet` read model, field endpoints, error mapping, API tests, `make api` | | [ ] | |
| T8 | 4 | Layout commands: `PUT /sheet/layout` (rename cascade, refuse dropping a group with fields), `POST /sheet/layout/declare`, `DELETE /sheet/layout`; API tests; `make api` | | [ ] | |
| T9 | 5 | Sheet tab: `useGameSystemSheet`, grouped field list, field editor per type, add/edit/delete/move, `sheetError.ts`, conflict handling, tests, stories | | [ ] | |
| T10 | 6 | Layout panel: declare, rename, reorder, delete groups; sheet/group columns; group and field spans; tests, stories | | [ ] | |
| T11 | 6 | Live preview: shared `SheetFields` over unsaved state, editable sample values, sample tracker values; tests, stories | | [ ] | |
| T12 | 7 | `FormulaInput`: TS `InvalidFormula.position`, sheet-scope reference and cycle checks, highlighted error, insert reference, computed value; used for derived fields; tests, stories | | [ ] | |
| T13 | 8 | Playwright: build a sheet with groups, a derived field and a preview; one preset gains a layout (`make presets`); feature doc close | | [ ] | |

## Progress

- 2026-10-17: Feature started. Explored the sheet contract, Formula kernel, Studio editor pattern and Play renderer. User chose to extend the contract with a group-grid layout. Issue #166.
- 2026-10-17: T1 done. ADR 0031, ADR index and 0022 note, contract §Sheet layout, glossary (Sheet layout, Group).
- 2026-10-17: Slice 1 review fix R3: Play translator tests cover a non-string group name, a derived field span above its group's columns and an invalid second field (index 1); no translator bug.
- 2026-10-17: T4 done. Campaign response `pinnedRelease.sheetLayout` (nullable) and field `span`; OpenAPI and TS types regenerated; API tests for a layout and a legacy release (null, span 1).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-794ba421dad9849f` | 68c37f8, 8763fc2, fac045b | approved, acknowledged; 1 suggestion R3-play-layout-coverage-gaps (Play translator tests: non-string group name, derived field span > group columns, invalid second field reports index 1), fixed in slice 2 | #167 |

## Next step

Slice 2: writer delivers the slice 1 review fix, T4 and T5 on `feat/studio-sheet-builder-2-play-layout`, then native review and PR.
