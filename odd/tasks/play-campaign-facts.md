# Feature: play-campaign-facts

- **Locator:** `odd/tasks/play-campaign-facts.md` · Engram topic `odd/play-campaign-facts/tasks`
- **Issue:** #75 · **Current branch:** `feat/play-campaign-facts-2-facts` (from `main` `0fca1fb`) · **Status:** slice 2 written, review pending
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-threads-npcs` (PRs #64–#74)

## Objective

Campaign facts, fact slot hints, the `fillFact` effect and `{fact:key}` placeholders, as decided by [ADR 0017](../../docs/adr/0017-narrativeflow-model.md) §6 and [ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) (decisions 9, 17, 20, 21). Roadmap feature 8b (M2).

## Problem and why

Worldbuilding output (the city, the Prince, a faction, the character's ambition) gets buried in the journal. Flows need it too: Session Zero fills slots, later prompts quote them (`{fact:ambition}`), and session hooks refill short-term goals. Releases already declare fact slots (`{key, label, type}`, feature 7) but nothing fills them; four of the five example fixtures wait for this feature.

## Scope

- **In:** fact slot `hint`; effect `fillFact`; placeholder `{fact:key}`; the 8b parts of the example fixtures (examples README); Play ACL; Campaign facts (free facts, slot facts, links) with persistence, HTTP and a Facts panel beside the journal; an `npc` / `thread` slot filled by hand picks an existing item or creates one; NPC and Thread rows show their linked facts; FlowRun applies `fillFact` and renders `{fact:key}`; the step card shows the slots a step fills, with their hints.
- **Out:** mentions and backlinks from journal entries; deleting facts or clearing a slot (as NPCs and Threads, items are never deleted in M2); carrying facts to another campaign (`play-campaign-transfer`); player trackers linked to facts.
- **Factions, disposition, goals** need no new fields (ADR 0018 decisions 20, 21): a faction is a free fact plus a `leader` NPC; disposition is a fact linked to an NPC; sandbox goals are Threads in `thread` slots.

## Constraints

- Schema version 2 is extended **additively**: every release valid today stays valid and reads the same. Never edit `v1.schema.json`. The JSON Schema and the domain validator stay in agreement (`ReleaseSchemaAgreementTest`).
- Dependency rule and PHPat ([ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)): facts live in framework-free `Play\Domain`; the translator in `Play\Infrastructure\GameSystem`; Studio validation in `Studio\Domain\Release\Version2`.
- ADR 0007: facts are a JSONB column on the Campaign, under its optimistic lock (version → 409).
- Links and slot values are references by id; names and labels are read when shown, so a rename shows everywhere.
- Glossary terms in code, UI and docs: Campaign fact, Fact slot, Free fact, Fact link, `fillFact`, Effect.
- Every slice is safe on `main` alone: backend unused until its UI lands.

## Contract: schema version 2 additions

```text
FactSlot     + hint?                     (≤500, string or null, like tracker and tag hints)
Effect kind  fillFact                    new
Placeholder  {fact:key}                  new
```

| Part | Shape | Rules |
|---|---|---|
| FactSlot | `{key, label (1–100), type: text \| npc \| thread, hint? (≤500)}` | Unchanged otherwise (≤100 slots, unique keys) |
| `fillFact` | `{kind: fillFact, slot: key, text? (1–2000)}` | `slot` names a declared fact slot. `text` is placeholder text and is allowed on a `text` slot only (on `npc` / `thread` slots it fails: the value is the picked item). Omitted `text` on a `text` slot means `{answer}` |
| `{fact:key}` | Any placeholder text (step `title`, `prompt`, `tip`; effect text, including `fillFact` `text`) | `key` names a declared fact slot of the release; `{fact}` without a key fails |

Effects keep the limit of 10 per list and may appear wherever effects do (step `effects`, bands, branches, options, `otherwise`, table entries). Canonical form: a `null` hint is omitted, as for trackers and tags.

## Play rules

### Campaign facts

| Field | Free fact | Slot fact |
|---|---|---|
| `id` | UUID | UUID |
| `slot` | — | slot key of the pinned release; at most one fact per slot |
| `label` | 1–100, set by the player | — (the slot's `label` is shown) |
| `text` | 1–2000 | `text` slot: 1–2000; `npc` / `thread` slot: — |
| `subject` | — | `npc` slot: an NPC id; `thread` slot: a Thread id; `text` slot: — |
| `links` | `[{kind: npc \| thread \| fact, id}]`, ≤20, unique, never the fact itself | same |

- At most 500 facts per campaign. Listed slot facts first (release slot order), then free facts in creation order.
- The player adds free facts, fills a slot (text, or an existing NPC / Thread, or a new one by name: the command creates an open weight-1 NPC / Thread with no tags and fills the slot with it, all or nothing), refills a slot (replaces its value, keeps its links), edits a free fact's label and text, and edits any fact's links. Closed items may be subjects and link targets.
- Refusals, all or nothing: unknown slot key, text on an `npc` / `thread` slot or a subject on a `text` slot, a missing value (422); a link or subject naming an unknown NPC, Thread or fact (404); a duplicate link or a self link (422); the 500 limit (409, as for NPCs); an unpinned or unreadable release (as for NPCs).
- The value of a fact (shown in the panel, rendered by `{fact:key}`): the text, or the subject's current name. An unfilled slot renders empty.
- An NPC or Thread shows its linked facts: the facts whose `links` name it, and the slot facts whose `subject` is it.

### Flow behaviour

- **`fillFact`** applies in effect order, like other effects:
  - `text` slot: renders `text` (default `{answer}`), trimmed and cut to 2000; empty → nothing changes.
  - `npc` / `thread` slot: the first item of that kind in the current `{picked}` (so `createNpc` then `fillFact prince` fills it with the new NPC); none → nothing changes.
  - A filled slot is replaced (its links stay); an unfilled one gets a new slot fact with a name-based id derived from the campaign id and its fact count (as `GeneratedItemId`); at 500 facts nothing is created. A generated id already held fails closed (`FactAlreadyExists`, 409) and nothing changes.
  - No journal entry of its own: the step's entry already records the answer.
- **`{fact:key}`:** the slot fact's value, read when rendered (a rename shows); empty when unfilled.
- **Step card:** a step view lists the slots its effects may fill (`fills: [{key, label, type, hint}]`, from every effect list of the step, unique, release order); the card shows them with their hints.

## Slice plan

Forecasts include tests, docs and generated files (OpenAPI spec, TS types). Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-campaign-facts-1-contract-acl` | `feat(studio): play-campaign-facts 1/5 contract-acl` | T1, T2 | ~1,400 |
| 2 | `feat/play-campaign-facts-2-facts` | `feat(play): play-campaign-facts 2/5 facts` | T3 | ~1,600 |
| 3 | `feat/play-campaign-facts-3-facts-http` | `feat(play): play-campaign-facts 3/5 facts-http` | T4 | ~1,700 |
| 4 | `feat/play-campaign-facts-4-flow` | `feat(play): play-campaign-facts 4/5 flow` | T5 | ~1,400 |
| 5 | `feat/play-campaign-facts-5-ui` | `feat(play): play-campaign-facts 5/5 ui` | T6, T7 | ~1,700 |

Safe on `main`: slice 1 lets Studio accept the new parts and Play read them; releases are published only from `backend/presets/` and none uses them; FlowRun ignores `fillFact` and renders `{fact:key}` empty until slice 4. Slices 2–4 add unused backend (the campaign response gains `factSlots` and `facts`, the step view `fills`, ignored by the SPA); slice 5 shows it.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc. `v2.schema.json`: fact slot `hint`, effect `fillFact`. Studio `Version2`: slot hint, `fillFact` (slot exists, `text` only on `text` slots), `{fact:key}`. Contract doc v2 additions. Valid and invalid fixtures; unit tests | delegated writer (slice 1, 4+ non-trivial files) | [x] | `da655e4` |
| T2 | 1 | Play ACL: `FactSlot` hint, `FillFactEffect`, typed and framework-free; FlowRun ignores `fillFact` and Play `Placeholders` renders `{fact:key}` empty until slice 4; the four example fixtures gain their 8b parts (examples README); translator tests | delegated writer (slice 1, 4+ non-trivial files) | [x] | `d4ae473`, `a0f5122` |
| T3 | 2 | Campaign facts (domain: free facts, fill and refill a slot by hand incl. creating an NPC / Thread, edit label, text, links, limits, all or nothing); JSONB column, Doctrine type, migration; repository round trip incl. legacy rows | delegated writer (slice 2, 4+ non-trivial files) | [x] | |
| T4 | 3 | Campaign response gains `factSlots` and `facts` (values and link names resolved); commands, handlers and endpoints (add free fact, fill slot, edit fact); API tests (400/404/409/422), Behat; `make api` | | [ ] | |
| T5 | 4 | FlowRun: `fillFact` (text, `{picked}` NPC / Thread, refill, limit, generated id), `{fact:key}` in views and effect text (an unknown key fails closed: slice 1 review), step view `fills` and its HTTP field; unit, integration and API tests; `make api` | | [ ] | |
| T6 | 5 | UI: Facts panel beside the journal (slots with hints, fill by text or pick / create, free facts, edit, links), linked facts on NPC and Thread rows, slots a step fills on the step card; tests and stories | | [ ] | |
| T7 | 5 | Glossary and contract doc wrap-up; e2e smoke for facts | | [ ] | |

## Progress

- 2026-10-12: feature started; doc and slice plan written; issue #75.
- 2026-10-12: slice 1 — T1 done: schema, Studio validation (slot hint, `fillFact`, `{fact:key}`), contract doc, fixtures and tests; RED observed first.
- 2026-10-12: slice 1 — T2 done: Play ACL (`FactSlot` hint, `FillFactEffect`), FlowRun ignores `fillFact`, `{fact:key}` renders empty; the four example fixtures gain their 8b parts and Play reads all five.
- 2026-10-12: slice 2 — T3 domain: `Facts` namespace (`Fact`, `FactLink`, `FactFill`), Campaign add / fill / edit / read facts, `reconstitute()` checks; unit tests (RED first).
- 2026-10-12: slice 2 — T3 persistence: `facts` JSONB column and migration, strict `CampaignFactsType` (fails closed), `assertFactsConsistent()` after load; repository round trip, legacy row and malformed-row tests.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-c4fd077e3ac7057e` | `da655e4`, `d4ae473`, `a0f5122` | Approved. One non-blocking suggestion, to fix in slice 4: Play `Placeholders` renders any `{fact:key}` empty without checking that the key names a fact slot of the pinned release (`Placeholders.php:53`), unlike `{tracker:key}` and `fillFact` slots; an unknown key should fail closed (translator or render time), with a test | [#76](https://github.com/iribarren/ogami/pull/76) |

## Next step

Slice 2 written, review pending. Slice 4 carries the slice 1 review suggestion (unknown `{fact:key}` fails closed).
