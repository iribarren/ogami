# Feature: play-items-economy

- **Locator:** `odd/tasks/play-items-economy.md` · Engram topic `odd/play-items-economy/tasks`
- **Issue:** [#311](https://github.com/iribarren/ogami/issues/311) · **Branch:** `feat/play-items-economy-2-play-inventory` · **Status:** in progress (slice 2 of 8)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B11 (before B12 `preset-cpr-campaign`; settles [ADR 0025](../../docs/adr/0025-items-and-shops-after-the-mvp.md))

## Objective

Structured gear on a Character, a currency and a `shop` step kind that buys and sells against it, replacing the flat sheet `list` for gear. Designed for its first user, `preset-cpr-campaign` (rent, lifestyle, Night Market; [example 4](../../docs/domain/flow-examples.md#4-cyberpunk-red-campaign-in-acts)). Every layer: release contract and Studio publisher, Play's anti-corruption layer, Play (sheet, flow, journal), the Studio editors and the release upgrade planner. The design is recorded as ADR 0045.

Out of scope: crafting, encumbrance rules beyond slots, flow effects that give or take gear, services (bought and not kept), formulas or conditions that read an inventory, gear in library packs.

## Problem and why

- Gear is a `list` of strings (ADR 0022): no price, no quantity, no slot. A price per item needs one `choice` per price (example 4 note), so a Night Market cannot be authored.
- Money has no home on the Character: example 4 used an `eddies` campaign tracker as an M2 workaround; `night-job` pays out into a text fact slot.
- Sheet effects clamp at a field's `min` (ADR 0027), so a "Pay the rent" option can be taken with no money.
- B12 prefers a second Flow in `night-job` (a new release), whose `gear` and `cyberware` are `list` fields: running campaigns upgrade through the planner (ADR 0035), which today resets a field whose type changed.

## Decisions

| Question | Decision |
|---|---|
| Currency (user, 2026-10-27) | Per Character. `sheet.currency` names a sheet `number` field (e.g. `eddies`); sheet effects, formulas, conditions in sheet scope and the upgrade planner already handle number fields |
| Unaffordable spends (user, 2026-10-27) | Refuse. A shop purchase, or a `choice` option's / `prompt`'s / `character` step's sheet effect that would take the currency below its floor fails with `insufficient_funds`; check outcomes (involuntary) still clamp (ADR 0027) |
| Shop stock (user, 2026-10-27) | An offer of catalog gear by key and/or category, plus an optional `stock` count (integer or dice) that rolls a random subset when the FlowRun reaches the step and stays fixed until it completes |
| Item effects (user, 2026-10-27) | Out of scope: gear changes only through `shop` steps and the player's sheet edits. Additive later |
| Name (parent, 2026-10-27) | **Gear**, not "item": Play already uses item for NPCs, Threads and Characters (`Flow/ItemKind`, `PickedItem`, `ItemCount`, `{picked}`), and Studio for library items and item rules. Contract `gear`, sheet field type `inventory`, an inventory holds *stacks* |
| Order | Contract first, then Play backend (inventory, shop, upgrade), Play UI, Studio; one release contract shape, no later reshaping |

### For the ADR (slice 1) to settle

Defaults the ADR writer starts from; it may change them with a reason recorded in the ADR.

- **Gear catalog** `gear`: optional release-level list of 0–500 entries `{key, name, cost, slots?, category?, description?}`. `key` the key rule, unique; `name` 1–60; `cost` int 0..1,000,000; `slots` int 0..100 per unit, default 1; `category` a label 1–40 (no category list; shops and inventories match it exactly); `description` ≤ 500. `[]` and absent canonicalize to absent.
- **`sheet.currency`**: optional `{field, unit?}`. `field` names a `number` field (not `derived`); `unit` 1–10 chars shown after amounts ("eb"). The floor is the field's `min`, else 0. At most one currency per sheet.
- **`inventory` field type**: `{capacity?, maxStacks?, categories?}`. `capacity` int 1..999 slots (absent: no slot limit); `maxStacks` 1..100, default 50; `categories` 1–20 labels it accepts (absent: every category, and gear without one). A value is a list of stacks: `{gear, quantity}` (a catalog key the field accepts, at most once per field) or a free stack `{name, quantity, slots}` (name 1–200 trimmed, slots 0..100). `quantity` 1..9,999. Used slots `Σ quantity × slots` ≤ `capacity`. It starts empty. Not a sheet effect target, not readable by formulas.
- **`shop` step**: `{kind: "shop", key, title?, text?, offer, stock?, sellRate?, effects?}`. `offer` `{gear?: [keys], categories?: [labels]}`, at least one non-empty and resolving to ≥ 1 catalog entry; `stock` int 1..50 or `{dice}`: that many distinct entries, drawn with the injected random source when the FlowRun reaches the step (stored on the FlowRun, as `PendingCheck` is; a FlowRun stored before this feature has none); without `stock` the whole offer. `sellRate` int 1..100 (percent of `cost`, rounded down); absent: the shop does not buy. Every offered entry must land in some `inventory` field (publisher check); a release with a `shop` step needs `sheet.currency`.
- **Shop completion**: request `{characterId, buy: [{gear, quantity}], sell: [{field, gear, quantity}]}`; a Character is required. Sells apply before buys; only catalog stacks are sold; a purchase lands in the first inventory field (sheet order) that accepts its category and stacks onto an existing stack. The whole transaction is refused (nothing changes) on unknown or unstocked gear, an over-sell, `insufficient_funds`, or an inventory over `capacity` / `maxStacks` (`inventory_full`). An empty transaction completes the step ("leave"). `{step:key}` reads the bought gear names, comma-joined (empty when nothing). One journal entry: Character, bought and sold lines with unit prices, currency before and after.
- **Affordability** applies to the currency field only, on voluntary placements (choice option, prompt, `character` step); the response error carries code `insufficient_funds` ([ADR 0037](../../docs/adr/0037-api-error-codes.md); the ADR picks 409 or 422 by that ADR's rule).
- **Upgrade (amends ADR 0035)**, new change kind `converted`:
  - `list` → `inventory` (same key): each string matching a catalog name (trimmed, case-insensitive) that the field accepts becomes `{gear, quantity: 1}` (duplicates add up); others become free stacks `{name, quantity: 1, slots: 0}`; then fit.
  - `inventory` → `inventory`: a stack whose gear is gone, or no longer accepted, becomes a free stack with its last name and slots; then fit.
  - `inventory` → `list`: names, `"<name> ×<n>"` when quantity > 1; then `maxItems` as today.
  - Fit: stacks over `maxStacks` or `capacity` are dropped from the end (`trimmed`). Any other type change still resets.
- **Studio**: gear catalog routes `PUT/DELETE /api/studio/game-systems/{key}/gear/{gear}` (point-budget style, ADR 0043), refused while a shop offers it (`DraftReferences`); `PUT/DELETE …/sheet/currency`; deleting or retyping the currency field is refused; `inventory` saved through the existing free-form field route; a category renamed away from a shop's or inventory's categories is a publisher error, not a guard.
- All additive in schema version 2; existing content hashes unchanged. Play's translator refuses each new shape until the slice that plays it (precedent ADR 0027, 0040, 0041, 0044).

## Constraints

- Additive in schema version 2; no migration (Character values, FlowRun and journal content are JSONB). Characters, FlowRuns and journal entries stored before this feature read unchanged.
- The upgrade plan stays deterministic and fingerprinted.
- Randomness only through the injected source (stock rolls).
- Every slice safe on `main` alone: backend unused until its UI lands; Play refuses the new release shapes until it plays them. Slice ceiling ~1,700 changed lines, including generated files.

## Layer map (explorer, 2026-10-27)

- Contract: `backend/contracts/gamesystem-release/v2.schema.json` (`sheetField` :104, `listField` :206, `step` union :664, `choiceStep` :996, `effect` :1159); `docs/contracts/gamesystem-release.md` (steps :149-166, sheet :246-264, sheet effects :308-327, upgrade fitting :506).
- Publisher: `Studio/Domain/Release/Version2/ReleaseVersion2.php:105,174`, `SheetFields.php` (`TYPES` :39-46, `field()` :108, `list()` ~:197), `Steps.php` (`KINDS` :41-51, `choice()` :524, `refuseSheetEffects` :564), `Effects.php`, `FlowReferences.php`, `StepParts.php`, `DraftReferences.php`; Studio drafts save Scene Types and Flows as whole documents (`SaveNarrativeItem`).
- Play ACL: `Play/Infrastructure/GameSystem/GameSystemReleaseTranslator.php` (limits :106-131, `FIELD_PROPERTIES` :132-139, `steps()` :702, `sheet()` :957, `sheetField()` :1566, `sheetEffect()` :1927, `assertSheetEffectReadsTheSheet` :437-483); snapshot `Play/Domain/GameSystem/Sheet/` (`Field` subclasses, `SheetTemplate`), `GameSystemSnapshot::sheet()` :136.
- Play Character: `Campaign/Characters/Character.php`, `CharacterValues.php` (`resolve` :44, `checked` :96, `initial` :113, `items` :158), `CharacterCreation.php`; `Campaign.php` (`editCharacter` :866, `changeCharacterValue` :886); JSONB `CampaignCharactersType.php`; HTTP `CampaignCharactersController.php`, `CharacterRequest`/`CharacterResponse`, `SheetFieldView.php:54`, `SheetFieldResponse`.
- Play flow: `FlowRun.php` (`completeStep` :282, `PendingCheck` :158/:343, `finish` ~:1199, `currentStep` :1753), `FlowRunContext::applySheetEffect` :273, `CampaignEffects::applySheetEffect` :85-132 (clamp :125-128), `FlowRun/StepKind.php`, `StepResult.php`, `CampaignFlowRunType.php`; HTTP `FlowRunController.php:96-128`, `CompleteFlowStepRequest.php`, `CompleteFlowStepHandler.php`, `FlowStepResponse.php`; journal `Play/Domain/Journal/*Content.php`.
- Upgrade: `Campaign/Upgrade/ReleaseUpgradePlanner.php` (`fit` :78, `fitValue` :207, `normalized` :232, `adjusted` :252), `FieldChangeKind.php`, `ReleaseUpgradePlan::toArray` :47; `UpgradeFieldChangeResponse`; UI `play/campaigns/ReleaseUpgradeDialog.tsx`.
- Play UI: `shared/sheet/SheetFields.tsx` (list ~:249-282), `sheetValues.ts`, `types.ts`; `play/characters/CharacterSheetDialog.tsx`, `CharacterForm.tsx`; `play/flow/StepCard.tsx` (:161), `SheetCharacterSelect.tsx`, `useFlowRun.ts`; `play/journal/JournalEntryView.tsx`.
- Studio UI: `studio/sheet/` (`sheetDraft.ts` :14, `SheetFieldDialog.tsx` :362/:428, `SheetTab.tsx`); `studio/narrative/steps/` (`stepDraft.ts`, `StepListEditor.tsx`, `BranchingFields.tsx`, `stepKindTile.tsx`); `studio/validationLocation.ts`.
- Fixtures: `backend/tests/Fixtures/Studio/releases/examples/cpr-campaign-in-acts.json` (example 4; `eddies` tracker, Night Market with one price per choice); presets with `list` gear: `night-job` (`gear` 20, `cyberware` 10), `percentile-horror` (`gear` 20).

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes. Re-planned to 8 slices on 2026-10-28: T4 plus T5 came to ~1,985 lines, so T5 moved to slice 3 with T7 and the shop (T6) became slice 4.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-items-economy-1-contract` | `feat(studio): play-items-economy 1/7 gear, inventory, currency and shop contract` | T1–T3 | ~1,650: this doc and roadmap ~250; ADR 0045, glossary, example 4 economy ~300; schema and contract doc ~300; publisher (gear, currency, inventory, shop, references) with unit tests and the example fixture ~700; translator refusals ~100 |
| 2 | `feat/play-items-economy-2-play-inventory` | `feat(play): play-items-economy 2/8 gear and inventories on Characters` | T4 | ~1,160 (actual): slice 1 review fixes ~50; translator reads gear, currency, inventory; `InventoryField`, stacks and capacity in `CharacterValues` ~540; tests ~570 |
| 3 | `feat/play-items-economy-3-play-api` | `feat(play): play-items-economy 3/8 gear in the Play API and affordability` | T5, T7 | ~1,150: T5 built on local `wip/play-items-economy-2-t5` (620213e, d5e8464) ~820 incl. generated ~285; affordability on choice, prompt, character steps ~300 |
| 4 | `feat/play-items-economy-4-play-shop` | `feat(play): play-items-economy 4/8 shop step` | T6 | ~1,400: translator `shop`; stock roll on reaching the step; transaction, journal content, `{step:key}` ~900; request, step response ~150; generated ~350 |
| 5 | `feat/play-items-economy-5-play-sheet-ui` | `feat(play): play-items-economy 5/8 inventories in the sheet and the upgrade` | T8–T9 | ~1,500: planner conversions and `converted` ~450; inventory control, read-only view, currency unit ~700; upgrade dialog ~100; generated ~150 |
| 6 | `feat/play-items-economy-6-play-shop-ui` | `feat(play): play-items-economy 6/8 shop step in Play` | T10 | ~1,200: shop step card (Character, stock, cart, sell, totals), `insufficient_funds` messages on choices, journal entry view, fixtures, e2e |
| 7 | `feat/play-items-economy-7-studio-gear` | `feat(studio): play-items-economy 7/8 gear catalog, inventory and currency in Studio` | T11–T12 | ~1,700: gear routes, guards, views ~650; Gear tab ~500; sheet builder inventory and currency ~300; generated ~250 |
| 8 | `feat/play-items-economy-8-studio-shop` | `feat(studio): play-items-economy 8/8 shop step editor` | T13–T14 | ~1,100: shop step editor, error locations ~600; release view and diff gear ~250; e2e ~150; closing |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; roadmap B11 in progress | parent (inline, docs) | [x] | fa4c738 |
| T2 | 1 | ADR 0045 (gear catalog, `inventory`, `sheet.currency`, `shop`, affordability, upgrade conversions), ADR index, ADR 0025 and 0035 amended-by notes, glossary (Gear, Inventory, Stack, Currency, Shop); schema `v2.schema.json` and contract doc; example 4's Economy section | delegated writer (Opus, design; preparation trigger: code reading for the design) | [x] | fa4c738 |
| T3 | 1 | Publisher: `gear` catalog, `sheet.currency`, `inventory` field, `shop` step, references (offers, categories, a destination inventory, currency required), canonical form; unit tests; economy in `cpr-campaign-in-acts.json`; Play's translator refuses the new shapes with a clear error | delegated writer (Opus, same writer) | [x] | 393c5d5 |
| T4 | 2 | Play: translator reads `gear`, `sheet.currency`, `inventory`; `InventoryField`, stacks, capacity, `maxStacks`, categories in `CharacterValues`; legacy Characters read; tests | delegated writer (Sonnet) | [x] | |
| T5 | 3 | Play API: pinned release gear catalog and currency, sheet field view, Character values with stacks; `make api`; tests | delegated writer (Sonnet) | [ ] | |
| T6 | 4 | Play flow: translator `shop`; stock roll when reached (stored on the FlowRun); transaction (sell then buy, routing, stacking), errors, journal content, `{step:key}`; request and step response; tests | delegated writer (Sonnet or Opus) | [ ] | |
| T7 | 3 | Affordability: `insufficient_funds` on choice option, prompt and `character` step sheet effects on the currency; check outcomes clamp; tests | delegated writer (same writer) | [ ] | |
| T8 | 5 | Upgrade planner: `list` ↔ `inventory` and `inventory` → `inventory` conversions, fitting, `converted`; response; tests | delegated writer (Sonnet) | [ ] | |
| T9 | 5 | Play UI: inventory control (catalog picker by category, free stack, quantity, remove, slots used), read-only sheet view, currency unit; upgrade dialog `converted`; fixtures and tests | delegated writer (Sonnet) | [ ] | |
| T10 | 6 | Play UI: shop step card, choice `insufficient_funds`, journal shop entry; fixtures, tests, e2e | delegated writer (Sonnet) | [ ] | |
| T11 | 7 | Studio backend: gear routes, currency routes, guards (`DraftReferences`), views; `make api`; tests | delegated writer (Sonnet) | [ ] | |
| T12 | 7 | Studio UI: Gear tab; sheet builder `inventory` type and currency setting; tests and stories | delegated writer (Sonnet) | [ ] | |
| T13 | 8 | Studio flow editor: `shop` step (offer, stock, sell rate), error locations; release view and diff show gear; tests, e2e | delegated writer (Sonnet) | [ ] | |
| T14 | 8 | Wrap-up: findings fixed or filed; roadmap B11 done; this doc closed | parent (closing doc commit) | [ ] | |

## Acceptance criteria

- A release with `gear`, `sheet.currency`, `inventory` fields and `shop` steps publishes; malformed shapes and dangling references fail closed with a path; releases without them keep their content hash.
- Play stores inventories on Characters within capacity, stack and category rules; Characters stored before read unchanged.
- A `shop` step rolls its stock once, buys and sells for a Character all or nothing, journals the transaction and refuses `insufficient_funds` and `inventory_full`.
- A voluntary sheet effect that would take the currency below its floor is refused; check outcomes clamp.
- A campaign upgrading from a release with a `list` gear field to one with an `inventory` keeps its gear as catalog or free stacks, and the plan shows it before confirming.
- Studio authors the catalog, inventories, the currency and shop steps, and guards references.

## Checks

- Per slice: test first where a runnable test exists; `make verify` once at the end; CI runs every suite.
- Slices 5 and 7: e2e (Play shop, Studio shop editor).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-4430bdf6d7dc77a6` | fa4c738, 393c5d5 | approved; R3 warning (refusal tests: formulas, `startMax`, check modifiers and point budgets reject an `inventory` field) and R3 suggestion (positive `{step:key}` test on a shop step) fixed in slice 2 | [#312](https://github.com/iribarren/ogami/pull/312) |

## Progress

- 2026-10-27: feature started; explorer layer map; four design decisions taken with the user; issue #311; slice plan of 7.
- 2026-10-27: T2 done: ADR 0045 settles the defaults and adds unique gear names (for the upgrade's name match), no `mandatory` and no sheet effects on a shop, no spill between inventories, a 0..offer clamp on dice stock, 422 for both codes; glossary, schema, contract doc, example 4 (docs only, no runnable RED).
- 2026-10-27: T3 done: publisher `Gear`, inventory, currency and shop rules (RED: `gear: unknown property`, then GREEN, 59 tests in `ReleaseContentGearTest`, schema agreement in `ReleaseSchemaGearTest`); example 4 fixture carries the economy; Play's translator refuses each shape (ADR 0045) and its tests read the example without it.
- 2026-10-28: slice 1 review fixes: publisher tests that an `inventory` field is refused in a derived formula, a `startMax`, a check modifier and a point budget, and that `{step:key}` may name a `shop` step (the publisher already refused and accepted these, so no RED; the tests pin the behavior, 64 tests green in `ReleaseContentGearTest`).
- 2026-10-28: T4 done: translator reads the gear catalog, `sheet.currency` and `inventory` fields (shops still refused); `GearEntry`, `Currency`, `InventoryField`, stacks and capacity in `CharacterValues`. RED: new tests failed on missing classes (stashed `src`), GREEN: Play unit and integration suites (3,490 tests). The translator now also checks a track `startMax` reads number and derived fields only (a gap the publisher already closed); `changeCharacterValue` has no sheet, so it checks the stack shape only (sheet effects cannot target an inventory).

## Next step

Slice 2: review and PR. Slice 3: rebase `wip/play-items-economy-2-t5` (T5) onto `main`, then T7. Slice 4 drops the stripped example-4 copy `GameSystemReleaseTranslatorGearTest::playable()` once Play reads shops too.
