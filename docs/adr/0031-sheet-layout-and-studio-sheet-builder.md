# 0031. Sheet layout and Studio sheet builder

- **Status:** Accepted, amends the layout line of [0022](0022-character-sheets-in-schema-version-2.md)
- **Date:** 2026-10-17

## Context

A schema 2 GameSystem draft ([ADR 0028](0028-studio-gamesystem-drafts.md)) can only get its `sheet` from a copied or restored release. The `studio-sheet-builder` feature lets a `GAME_MANAGER` build it: fields of every type, a layout, derived-value formulas ([ADR 0023](0023-formula-kernel-and-derived-values.md)) and a live preview. The only layout today is a field's `group` string ([ADR 0022](0022-character-sheets-in-schema-version-2.md)): consecutive fields with the same group render together, in one column, in list order. Paper character sheets use side-by-side boxes and stat grids, so the release contract needs a real layout model.

## Decision

- **Group grid, additive in schema version 2.** Groups are declared once, in order. The sheet has 1 or 2 columns of groups (`sheet.columns`, default 1). A group has 1 to 4 columns of fields (`columns`, default 1) and may span 1 or 2 sheet columns (`span`, default 1). A field may span 1 to 4 columns of its group (`span`, default 1). `sheet` allows exactly `fields`, `columns` and `groups`; `groups` holds at most 20 `{name, columns?, span?}`, with `name` 1 to 60 characters, not blank, unique by exact match. Phones show one column.
- **Rules (item rules, so they also hold on a draft).** `sheet.columns` and a field `span` need declared groups; a group `span` is at most the sheet's columns; a field `span` is at most its group's columns; with declared groups every field has a `group` naming a declared group. An empty declared group is valid and renders nothing. Groups render in declared order and fields in list order within their group. Without `groups`, today's consecutive-group rendering stays.
- **Canonical form.** Defaults (`columns` 1, `span` 1) and `[]` or `null` groups are omitted, so every release valid before keeps its content hash.
- **Play.** The translator reads the layout and re-checks every rule, failing closed; a release stored without layout reads as before. The Campaign response carries `pinnedRelease.sheetLayout` (`null` without groups) and `span` on each field. The sheet renderer and the derived-value helpers move to `frontend/src/shared/sheet/` with a neutral type, drawn with a CSS grid that collapses to one column on small screens.
- **Studio sheet API**, in the style of [ADR 0030](0030-studio-flow-editor.md): `GET /sheet` returns a read model (version, sheet, tracker catalog, field keys of the latest release). Field commands (`POST`, `PUT`, `DELETE`, `move`) and layout commands (`PUT /sheet/layout` with rename cascade, `POST /sheet/layout/declare`, `DELETE /sheet/layout`) carry `expectedVersion`, run the item rules on the whole draft and return the read model; 404, 409 and 422 as in ADR 0030. Dropping a group that holds fields is a 422. Declaring converts a sheet without groups: groups in order of first appearance, fields without a group go to a new `General` group.
- **References.** The draft reference scan gains sheet field keys: derived formulas, check formulas and sheet effects (`field` and `{formula}` values). Renaming or deleting a referenced field is a 422 listing the paths. A type change goes through the item rules.
- **Formula editor.** Validation runs on the client with the TypeScript evaluator: syntax with a structured `position` (TypeScript only; PHP messages and the conformance vectors do not change), unknown or wrong-kind references and cycles. The server stays the authority on save. It is built as a reusable `FormulaInput` for the check editor.
- **Live preview.** It renders the unsaved form through the shared renderer with editable sample values; tracker samples start at each tracker's initial value; nothing is saved.
- **Rename after publish** is allowed, with a hint that Characters on campaigns that upgrade later lose the value of a renamed key (migration is a later feature, [ADR 0010](0010-versioned-gamesystem-releases.md)).

## Consequences

- Layout costs the contract three properties and one rule set, shared by the publisher, the JSON Schema and Play's translator, and held equal by the agreement vectors.
- Old releases and their hashes are untouched; presets gain a layout only when an author adds one.
- The model is a grid of declared boxes, not a free-form canvas; there is no drag and drop ([ADR 0004](0004-react-vite-spa-ui-toolkit.md)).
- Check authoring stays out of scope: this feature only reads checks to find references.
