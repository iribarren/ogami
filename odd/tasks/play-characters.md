# Feature: play-characters

- **Locator:** `odd/tasks/play-characters.md` · Engram topic `odd/play-characters/tasks`
- **Issue:** #96 · **Branch:** `feat/play-characters-2-characters` · **Status:** slice 1 merged (PR #97); slice 2 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-guided-sample` (PRs #93–#95, M2 complete)

## Objective

Roadmap feature 10 (milestone M3). Release schema gains a SheetTemplate with Field types (number, text, list, resource track). Play creates and edits Characters from the campaign's pinned release template; list fields cover inventory and cyberware. A Campaign has zero or more Characters and an NPC can be promoted to a Character ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) decisions 22–25). Characters carry tags; the Character part of the Scene cast; the Session party. A `character` flow step kind ([ADR 0017](../../docs/adr/0017-narrativeflow-model.md)) completes when a Character exists, so Session Zero can create one.

Out of scope: derived values (feature 11), checks (12), effects on sheet fields (a later schema version), items/inventory/`shop` (13), deleting or retiring Characters, release upgrade data migration.

## Problem and why

- `sheet` is reserved (`{}`) in schema versions 1 and 2; Play has no Character. Session Zero flows use prompt and table steps for "the character" and the examples (West Marches roster, CPR crew) wait for feature 10 (`backend/tests/Fixtures/Studio/releases/examples/README.md`).
- Scene cast holds NPCs only (`Scene::$cast`, `list<NpcId>`); Sessions have no party.

## Decisions

| Question | Decision |
|---|---|
| Schema version (user, 2026-10-13) | **Additive in schema version 2**, as `credits` was: the reserved `sheet` gains `fields`, the `character` step kind joins, and `pick` / `condition` `of` gain `character`. Every valid release stays valid with the same hash (`sheet: {}` canonical). Sheet effects, derived values and checks open schema version 3. Amend the contract's "new capability = new schema version" rule and ADR 0017's "M3 step kinds arrive as later schema versions" note; record in ADR 0022 |
| Character storage (Claude) | A value object inside the `Campaign` aggregate, like `Npc`: `characters` JSONB column with its own Doctrine type, no table. At most 100 per Campaign |
| Values vs template (Claude) | Values are validated against the pinned release's sheet on every write (unknown key, wrong type, out of bounds → 422). A missing key reads as the field default. A release without fields gives Characters with a name and tags only (Free journal, Mythic-style work) |
| NPC promotion (Claude) | Creates a Character with the NPC's name, release tags and free tags; the NPC is closed and records `promotedTo`; it cannot be reopened or promoted again (409). In the current Scene's cast the Character replaces the NPC |
| Party (Claude) | A Session's party starts as the previous Session's party (empty for Session 1). The player edits the current Session's party. A Character created or promoted while a Session runs joins its party and the current Scene's cast. A `pick of: character` in a session hook adds to the party; in a scene part it adds to the cast |
| `character` step (Claude) | Completes with the id of an existing Character the player picks (created inline from the step if none exists); 422 without one. `{step:key}` reads the Character's name |

## Design

### Contract (schema version 2, additive)

```
sheet   { fields?: Field[] (0–100, unique keys) }      // {} and {"fields": []} canonicalize to {}
Field   { key, label (1–100), hint? (≤ 500), group? (1–60), type, …type props }
  number  min?, max?, default?    integers −1,000,000..1,000,000; min ≤ default ≤ max
  text    multiline? (bool), maxLength? (1–2000; default 200, 2000 when multiline)
  list    itemLabel? (1–60), maxItems? (1–100, default 50); items are trimmed strings 1–200
  track   max? (1–999)            resource track {current, max}; a fixed max when set, else each Character sets its max; starts full
Step    { kind: "character", key, … common step props }
pick.of / condition count.of: "npc" | "thread" | "character"
```

- `group` labels lay fields out: consecutive fields with the same group render together.
- JSON Schema and the Version2 validator agree (`ReleaseSchemaAgreementTest`): valid and invalid fixtures per rule (unknown type, unknown prop for the type, duplicate key, min > max, default out of bounds, maxLength out of range, `character` in `of`, `character` step).
- Canonical form: null and absent optional fields omitted; a sheet without fields encodes as `{}` so existing hashes hold (`ReleaseContent::withSheetObject`).
- Studio validation only; Play's translator reads `sheet` into the snapshot (slice 1) and fails closed on the `character` step kind and `of: character` until slice 4 adds them.

### Play

- `SheetTemplate` / `Field` (number, text, list, track) in `Play/Domain/GameSystem/`, read by the translator (limits re-checked; a stored release is not trusted).
- `Character` value object in `Play/Domain/Campaign/`: `CharacterId`, name (1–100), release tags, free tags, values (field key → int | string | list<string> | {current, max}), `promotedFrom?` NpcId. `Campaign::addCharacter`, `editCharacter`, `promoteNpc`, `characters()`.
- Endpoints: `POST /api/campaigns/{id}/characters`, `PUT …/characters/{characterId}`, `POST …/npcs/{npcId}/promote`; the Campaign response gains `characters` and `pinnedRelease.sheet`.
- Session `party: list<CharacterId>`; Scene cast gains `characters: list<CharacterId>` (legacy rows read empty); `PUT …/sessions/current/party`, the cast endpoint takes Characters too.
- `character` step and `ItemKind::Character` in the ACL and FlowRun (pick candidates, condition counts, `{picked}`, joining party or cast); every `npc ? … : threads` ternary becomes an exhaustive `match`.
- UI: a Characters panel (list, create, edit) rendering the four field types by `group`; tags as for NPCs; "Promote to Character" on an NPC; party editor at session start; Characters in the Scene cast; a `character` step card.

## Constraints

- Schema version 2 extended additively; JSON Schema and Version2 validator agree. Play reads releases only through its ACL ([ADR 0010](../../docs/adr/0010-versioned-gamesystem-releases.md), [ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)).
- `make api` after response changes; commit `openapi.json` and `schema.d.ts`.
- Every slice safe on `main` alone: slice 1 is validation and a snapshot nobody reads; slice 2 endpoints unused until slice 3's UI; slices 4–5 likewise.
- Fan-content rule (ADR 0018 decision 29) for the Night Job sheet.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-characters-1-contract` | `feat(studio): play-characters 1/5 sheet contract` | T1, T2, T3 | ~1,450 |
| 2 | `feat/play-characters-2-characters` | `feat(play): play-characters 2/5 characters api` | T4, T5, T6 | ~1,700 |
| 3 | `feat/play-characters-3-sheet-ui` | `feat(play): play-characters 3/5 sheet ui` | T7, T8 | ~1,500 |
| 4 | `feat/play-characters-4-party` | `feat(play): play-characters 4/5 party and character step` | T9, T10 | ~1,700 |
| 5 | `feat/play-characters-5-play` | `feat(play): play-characters 5/5 party ui and preset` | T11, T12, T13 | ~1,300 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0022 "Character sheets in schema version 2" (additive decision, field types, Characters in the Campaign, party and promotion rules); ADR 0017 status note on M3 step kinds; contract "new capability" rule amended; glossary (SheetTemplate, Field types, Resource track, Character, Party); ADR index | delegated writer (slice 1) | [x] | 23247cd |
| T2 | 1 | Contract: `sheet.fields` and the four field types, the `character` step kind, `of: character` in `pick` and `condition` counts in `v2.schema.json` and the Version2 validator; canonical form keeps existing hashes; agreement fixtures (valid + structural + semantic); West Marches example uses `pick of: character` and gains a small sheet; examples README; contract doc section | delegated writer (slice 1) | [x] | 46a5ce0 |
| T3 | 1 | Play ACL: `SheetTemplate` and `Field` snapshot classes, translator reads `sheet` with limits re-checked; `character` step and `of: character` fail closed with Play's translator error until slice 4; translator tests | delegated writer (slice 1) | [x] | 5089994 |
| T4 | 2 | Slice 1 review fixes. `Character` value object, `CharacterId`, values validated against the snapshot sheet, `Campaign::addCharacter` / `editCharacter` / `characters()`, `characters` column + Doctrine type + migration, legacy rows read empty | | [ ] | |
| T5 | 2 | Characters API: create and edit endpoints, Campaign response `characters` and `pinnedRelease.sheet`, error mapping (404/409/422), API tests, Behat `characters.feature`, `make api` | | [ ] | |
| T6 | 2 | NPC promotion: `Campaign::promoteNpc`, endpoint, NPC `promotedTo` (persisted, legacy rows null), promoted NPC cannot be reopened or promoted again (409), API tests. The current Scene cast swap moves to T9, where the cast gains Characters | | [ ] | |
| T7 | 3 | Slice 2 review fixes. Characters panel: list, create and edit forms for number, text, list and track fields grouped by `group`; release and free tags; Vitest | | [ ] | |
| T8 | 3 | "Promote to Character" on an NPC; e2e: create and edit a Character, promote an NPC | | [ ] | |
| T9 | 4 | Slice 3 review fixes. Session party and the Character part of the Scene cast: domain, promotion swaps the NPC for its Character in the current Scene's cast, persistence (legacy rows empty), party endpoint, cast endpoint with Characters, a new Scene's cast starts as the party, creation/promotion joins party and cast; API tests, `make api` | | [ ] | |
| T10 | 4 | `character` step kind and `ItemKind::Character` in the ACL and FlowRun: step completion with a Character id, `{step:key}` name, pick candidates and condition counts over Characters, picks join the party in session hooks and the cast in scenes; exhaustive matches; FlowRun view | | [ ] | |
| T11 | 5 | Slice 4 review fixes. UI: party editor at session start, Characters in the Scene cast, `character` step card (pick or create inline), Characters as pick candidates; Vitest | | [ ] | |
| T12 | 5 | Night Job preset: an original sheet (role, stats, HP and Humanity tracks, gear and cyberware lists) and a `character` step in Crew; preset tests; e2e Session Zero creates a Character | | [ ] | |
| T13 | 5 | Wrap-up: roadmap 10 done, flow examples notes, feature doc closed | | [ ] | |

## Progress

- 2026-10-13: T1 done. ADR 0022, ADR index, ADR 0017 note, contract evolution rule, glossary. Docs only: structural readback, no RED/GREEN. Route: delegated writer.
- 2026-10-13: T2 done. `sheet.fields` (four types), `character` step, `of: character` in `pick` / `condition` in `v2.schema.json` and `SheetFields` / `Steps`; fixtures, agreement cases, `ReleaseContentSheetTest`; contract doc. RED: with the schema, `ReleaseContent` and `Steps` stashed, 6 errors and 18 failures in `tests/Unit/Studio`. GREEN: 731 tests. Hashes of every pre-change fixture, example (but West Marches) and preset equal before and after. West Marches gains a sheet; its `pick of: character` waits for slice 4 because `PlayReadsPublishedGameSystemReleasesTest` runs every example through the ACL. Route: delegated writer.
- 2026-10-13: T3 done. `Sheet/` value objects (`SheetTemplate`, `NumberField`, `TextField`, `ListField`, `TrackField`), `GameSystemSnapshot::sheet()`, translator reads and re-checks the sheet; `character` step and `of: character` fail closed ("not supported by Play yet"). RED: new translator test 35 of 36 failing before the translator change. GREEN: 36 tests; `tests/Unit/Play` and the West Marches integration read pass. Note: `tests/Support/Play/ReleaseViews::of()` blanks the sheet, so the sheet tests rebuild the view; fix in slice 2. Route: delegated writer.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-1bd08a5815e4b5fe` | 23247cd, 46a5ce0, 5089994 | Approved, acknowledged. Fixed in slice 2: R3-001 Studio accepts a whitespace-only field `label`, `group` or `itemLabel` that Play's translator rejects; R3-002 Studio accepts the `character` step and `of: character` before Play reads them (pin that Play's catalog lists such a release without Flows and campaign creation fails cleanly); R3-003 `SheetTemplate` silently keeps the last of two fields with the same key. Also the T3 note: `ReleaseViews::of()` blanks the sheet | #97 |

## Next step

Slice 2 (T4–T6) on `feat/play-characters-2-characters`: slice 1 review fixes, Characters in the Campaign, Characters API, NPC promotion.
